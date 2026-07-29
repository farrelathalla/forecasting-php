"""Export the notebook's cached 1-minute grid as a 15-minute CSV for seeding.

Without this the system starts with no context at all and cannot forecast for two weeks.
With it, the database opens with the full extract and the live poller simply continues
the series.

The downsample is mean over the already gap-filled 1-minute grid, which is exactly what
the notebook does (M = G.resample('15min').mean()). The mean is legitimate only because
the input is already LOCF-reconstructed — on raw report-by-exception data it would be the
F6 trap.

    python service/export_history.py [--source ../output/cache/grid_1min.parquet]
                                     [--out var/seed_15min.csv]
"""

from __future__ import annotations

import argparse
import os
import sys

import pandas as pd

DEFAULT_SOURCE = os.path.join("..", "output", "cache", "grid_1min.parquet")
DEFAULT_OUT = os.path.join("var", "seed_15min.csv")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", default=DEFAULT_SOURCE)
    parser.add_argument("--out", default=DEFAULT_OUT)
    parser.add_argument("--freq", default="15min")
    args = parser.parse_args()

    root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    source = args.source if os.path.isabs(args.source) else os.path.join(root, args.source)
    out = args.out if os.path.isabs(args.out) else os.path.join(root, args.out)

    if not os.path.isfile(source):
        print(f"source not found: {source}", file=sys.stderr)
        return 1

    grid = pd.read_parquet(source)
    print(f"read {grid.shape[0]:,} rows x {grid.shape[1]} series from {source}")

    fifteen = grid.resample(args.freq).mean()
    print(f"downsampled to {fifteen.shape[0]:,} steps at {args.freq}")

    long = (
        fifteen.stack(future_stack=True)
        .rename("value")
        .reset_index()
        .rename(columns={"level_1": "series", fifteen.index.name or "index": "ts"})
    )
    long.columns = ["ts", "series", "value"]
    long = long.dropna(subset=["value"])

    split = long["series"].str.split("|", n=1, expand=True)
    long["signal"] = split[0]
    long["asset"] = split[1]
    long["ts"] = pd.to_datetime(long["ts"]).dt.strftime("%Y-%m-%d %H:%M:%S")

    os.makedirs(os.path.dirname(out), exist_ok=True)
    long[["ts", "asset", "signal", "value"]].to_csv(out, index=False)

    print(f"wrote {len(long):,} rows -> {out}")
    print(f"span {long['ts'].min()} .. {long['ts'].max()}")
    print("assets: " + ", ".join(sorted(long["asset"].unique())))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
