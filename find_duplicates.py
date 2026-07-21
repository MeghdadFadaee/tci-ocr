#!/usr/bin/env python3
"""Find exact duplicate images using hashes already stored in labels.csv."""

from __future__ import annotations

import argparse
import csv
import sys
from pathlib import Path

from ocr_dataset import read_rows


DUPLICATE_COLUMNS = (
    "hash",
    "canonical_filename",
    "duplicate_filename",
    "batch_id",
    "label",
    "verified",
)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--csv", type=Path, default=Path("dataset/labels.csv"))
    parser.add_argument(
        "--output", type=Path, default=Path("dataset/duplicates.csv")
    )
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    first_by_hash: dict[str, str] = {}
    rows = 0
    duplicates = 0
    missing_hashes = 0
    args.output.parent.mkdir(parents=True, exist_ok=True)

    try:
        with args.output.open("w", newline="", encoding="utf-8") as output:
            writer = csv.DictWriter(output, fieldnames=DUPLICATE_COLUMNS)
            writer.writeheader()
            for row in read_rows(args.csv):
                rows += 1
                digest = row["hash"].strip().lower()
                if not digest:
                    missing_hashes += 1
                    continue
                canonical = first_by_hash.setdefault(digest, row["filename"])
                if canonical == row["filename"]:
                    continue
                duplicates += 1
                writer.writerow(
                    {
                        "hash": digest,
                        "canonical_filename": canonical,
                        "duplicate_filename": row["filename"],
                        "batch_id": row["batch_id"],
                        "label": row["label"],
                        "verified": row["verified"],
                    }
                )
    except (OSError, ValueError) as error:
        print(f"error: {error}", file=sys.stderr)
        return 1

    print(f"Scanned {rows:,} rows; found {duplicates:,} duplicate files.")
    if missing_hashes:
        print(f"Warning: {missing_hashes:,} rows had no hash and were skipped.")
    print(f"Report: {args.output}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

