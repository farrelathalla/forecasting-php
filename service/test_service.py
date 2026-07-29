"""Sidecar tests.

    .venv/Scripts/python -m pytest service/test_service.py -q

Loads the real Chronos-2 weights once (module-scoped TestClient), because the properties
that matter — quantile monotonicity, NaN tolerance, output shape — are properties of the
model, and a mocked pipeline would assert nothing about the thing we deploy.
"""

from __future__ import annotations

import os
import sys

import numpy as np
import pytest
from fastapi.testclient import TestClient

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import app as service  # noqa: E402

QUANTILES = [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9]


@pytest.fixture(scope="module")
def client():
    with TestClient(service.app) as c:
        yield c


def synthetic(n: int = 1344, level: float = 35.0, seed: int = 0) -> list[float]:
    """A daily cycle at 15-minute resolution, like the real INLET_TEMP channel."""
    rng = np.random.default_rng(seed)
    t = np.arange(n)
    return list(level + 2.0 * np.sin(2 * np.pi * t / 96.0) + rng.standard_normal(n) * 0.1)


def test_health_reports_a_loaded_model(client):
    body = client.get("/health").json()
    assert body["status"] == "ok", body
    assert body["model"] == "amazon/chronos-2"
    assert body["device"] in {"cpu", "cuda"}


def test_forecast_returns_the_requested_shape(client):
    response = client.post(
        "/forecast",
        json={
            "series": [{"id": "POWER|TBW1", "values": synthetic()}],
            "prediction_length": 96,
            "quantile_levels": QUANTILES,
        },
    )
    assert response.status_code == 200, response.text
    body = response.json()

    assert len(body["forecasts"]) == 1
    forecast = body["forecasts"][0]
    assert forecast["id"] == "POWER|TBW1"
    assert len(forecast["median"]) == 96
    assert set(forecast["quantiles"]) == {f"{q:g}" for q in QUANTILES}
    for level in forecast["quantiles"].values():
        assert len(level) == 96


def test_all_nine_targets_in_one_call(client):
    series = [{"id": f"target-{i}", "values": synthetic(seed=i)} for i in range(9)]
    body = client.post(
        "/forecast",
        json={"series": series, "prediction_length": 96, "quantile_levels": QUANTILES},
    ).json()

    assert len(body["forecasts"]) == 9
    assert [f["id"] for f in body["forecasts"]] == [s["id"] for s in series]


def test_quantiles_are_monotonic(client):
    """If q10 can exceed q90 the interval width, cov80 and the whole alarm layer are
    meaningless — section 15 standardises residuals by exactly this spread."""
    body = client.post(
        "/forecast",
        json={
            "series": [{"id": "s", "values": synthetic()}],
            "prediction_length": 96,
            "quantile_levels": QUANTILES,
        },
    ).json()

    levels = [body["forecasts"][0]["quantiles"][f"{q:g}"] for q in QUANTILES]
    for step in range(96):
        column = [level[step] for level in levels]
        assert column == sorted(column), f"quantile crossing at step {step}: {column}"


def test_median_matches_the_half_quantile(client):
    body = client.post(
        "/forecast",
        json={
            "series": [{"id": "s", "values": synthetic()}],
            "prediction_length": 96,
            "quantile_levels": QUANTILES,
        },
    ).json()
    forecast = body["forecasts"][0]
    assert forecast["median"] == forecast["quantiles"]["0.5"]


def test_context_with_holes_still_produces_a_sane_forecast(client):
    """Our history genuinely has a gap: the seeded extract ends 2026-07-22 and live
    polling starts later. Sending null is more honest than interpolating across it."""
    values = synthetic()
    values[200:900] = [None] * 700

    body = client.post(
        "/forecast",
        json={
            "series": [{"id": "gappy", "values": values}],
            "prediction_length": 96,
            "quantile_levels": [0.5],
        },
    ).json()

    median = body["forecasts"][0]["quantiles"]["0.5"]
    assert len(median) == 96
    assert all(np.isfinite(median))
    assert 25.0 < float(np.mean(median)) < 45.0


def test_short_series_is_refused_with_422_not_a_guess(client):
    response = client.post(
        "/forecast",
        json={"series": [{"id": "tiny", "values": [1.0, 2.0, 3.0]}], "prediction_length": 96},
    )
    assert response.status_code == 422
    assert "at least" in response.text


def test_series_that_is_all_null_is_refused(client):
    response = client.post(
        "/forecast",
        json={"series": [{"id": "empty", "values": [None] * 1344}], "prediction_length": 96},
    )
    assert response.status_code == 422


def test_rejects_out_of_range_quantile_levels(client):
    response = client.post(
        "/forecast",
        json={
            "series": [{"id": "s", "values": synthetic()}],
            "prediction_length": 96,
            "quantile_levels": [0.0, 1.5],
        },
    )
    assert response.status_code == 422


def test_rejects_absurd_prediction_length(client):
    response = client.post(
        "/forecast",
        json={"series": [{"id": "s", "values": synthetic()}], "prediction_length": 99999},
    )
    assert response.status_code == 422


def test_reports_elapsed_time(client):
    body = client.post(
        "/forecast",
        json={"series": [{"id": "s", "values": synthetic()}], "prediction_length": 96},
    ).json()
    assert body["elapsed_ms"] >= 0
    assert body["prediction_length"] == 96
