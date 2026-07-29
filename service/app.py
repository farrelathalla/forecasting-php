"""Chronos-2 inference sidecar.

The only part of this system that cannot be PHP: Chronos-2 is a 120M-parameter
transformer executed by PyTorch, and there is no PyTorch runtime for PHP.

Deliberately thin. No database, no scheduling, no business logic — it takes arrays of
numbers and returns arrays of numbers. Everything about which targets get forecast, when,
and what happens to the result stays in PHP, so replacing the model later touches this
file and nothing else.

The model is loaded once at startup (~3 minutes on CPU) and kept warm; a per-request
load would make the whole design unworkable.

    uvicorn app:app --host 127.0.0.1 --port 8008
"""

from __future__ import annotations

import logging
import os
import time
from contextlib import asynccontextmanager
from typing import Any

import numpy as np
import torch
from fastapi import FastAPI, HTTPException
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field, field_validator

MODEL_ID = os.environ.get("TBW_CHRONOS_MODEL", "amazon/chronos-2")
DEVICE = os.environ.get("TBW_CHRONOS_DEVICE", "auto")
MIN_CONTEXT = int(os.environ.get("TBW_MIN_CONTEXT", "192"))
MAX_PREDICTION_LENGTH = 1024

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
)
log = logging.getLogger("tbw.sidecar")

STATE: dict[str, Any] = {"pipeline": None, "device": None, "loaded_at": None, "error": None}


def _resolve_device() -> str:
    if DEVICE != "auto":
        return DEVICE
    return "cuda" if torch.cuda.is_available() else "cpu"


def _load_model() -> None:
    from chronos import BaseChronosPipeline

    device = _resolve_device()
    started = time.time()
    log.info("loading %s on %s ...", MODEL_ID, device)
    STATE["pipeline"] = BaseChronosPipeline.from_pretrained(MODEL_ID, device_map=device)
    STATE["device"] = device
    STATE["loaded_at"] = time.time()
    log.info("model ready in %.1fs", time.time() - started)


@asynccontextmanager
async def lifespan(_: FastAPI):
    try:
        _load_model()
    except Exception as exc:  # noqa: BLE001
        # Stay up and report the failure through /health. Exiting here would look
        # identical to "port not listening", and PHP already treats an unreachable
        # sidecar as a fallback condition rather than an outage.
        STATE["error"] = f"{type(exc).__name__}: {exc}"
        log.exception("model load failed")
    yield


app = FastAPI(title="TBW Chronos-2 sidecar", version="1.0", lifespan=lifespan)


class Series(BaseModel):
    id: str
    values: list[float | None] = Field(min_length=1)


class ForecastRequest(BaseModel):
    series: list[Series] = Field(min_length=1)
    prediction_length: int = 96
    quantile_levels: list[float] = [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9]

    @field_validator("prediction_length")
    @classmethod
    def _check_horizon(cls, v: int) -> int:
        if not 1 <= v <= MAX_PREDICTION_LENGTH:
            raise ValueError(f"prediction_length must be 1..{MAX_PREDICTION_LENGTH}")
        return v

    @field_validator("quantile_levels")
    @classmethod
    def _check_quantiles(cls, v: list[float]) -> list[float]:
        if not v:
            raise ValueError("quantile_levels must not be empty")
        if any(not 0.0 < q < 1.0 for q in v):
            raise ValueError("quantile levels must lie strictly between 0 and 1")
        return v


@app.get("/health")
def health() -> dict[str, Any]:
    return {
        "status": "ok" if STATE["pipeline"] is not None else "unavailable",
        "model": MODEL_ID,
        "device": STATE["device"],
        "min_context": MIN_CONTEXT,
        "torch": torch.__version__,
        "cuda_devices": torch.cuda.device_count(),
        "error": STATE["error"],
    }


@app.post("/forecast")
def forecast(request: ForecastRequest) -> JSONResponse:
    pipeline = STATE["pipeline"]
    if pipeline is None:
        raise HTTPException(status_code=503, detail=f"model not loaded: {STATE['error']}")

    contexts = []
    for series in request.series:
        # NaN, not zero. Chronos-2 handles missing values in the context natively, and
        # our history genuinely has holes: the seeded extract ends 2026-07-22 while live
        # polling starts later. Filling them with a number would invent operation that
        # never happened, which is exactly what F1 and F6 warn against.
        values = np.array(
            [np.nan if v is None else float(v) for v in series.values],
            dtype=np.float32,
        )
        finite = int(np.isfinite(values).sum())
        if finite < MIN_CONTEXT:
            raise HTTPException(
                status_code=422,
                detail=(
                    f"series '{series.id}' has {finite} usable points, "
                    f"needs at least {MIN_CONTEXT}"
                ),
            )
        contexts.append(torch.tensor(values, dtype=torch.float32))

    started = time.time()
    try:
        quantiles, _mean = pipeline.predict_quantiles(
            contexts,
            prediction_length=request.prediction_length,
            quantile_levels=request.quantile_levels,
        )
    except Exception as exc:  # noqa: BLE001
        log.exception("inference failed")
        raise HTTPException(status_code=500, detail=f"{type(exc).__name__}: {exc}") from exc
    elapsed_ms = int((time.time() - started) * 1000)

    forecasts = []
    for series, tensor in zip(request.series, quantiles, strict=True):
        array = tensor.detach().cpu().numpy()
        # Chronos-2 returns (variate, horizon, quantile); one variate per series here.
        if array.ndim == 3:
            array = array[0]
        # Quantile crossing is rare but not impossible, and a q90 below q10 would make
        # every interval width and every coverage number meaningless downstream.
        array = np.sort(array, axis=-1)

        by_level = {
            f"{level:g}": [float(x) for x in array[:, i]]
            for i, level in enumerate(request.quantile_levels)
        }
        median_index = min(
            range(len(request.quantile_levels)),
            key=lambda i: abs(request.quantile_levels[i] - 0.5),
        )
        forecasts.append(
            {
                "id": series.id,
                "median": [float(x) for x in array[:, median_index]],
                "quantiles": by_level,
            }
        )

    log.info(
        "forecast %d series x %d steps in %d ms",
        len(forecasts),
        request.prediction_length,
        elapsed_ms,
    )
    return JSONResponse(
        {
            "model": MODEL_ID,
            "device": STATE["device"],
            "elapsed_ms": elapsed_ms,
            "prediction_length": request.prediction_length,
            "quantile_levels": request.quantile_levels,
            "forecasts": forecasts,
        }
    )
