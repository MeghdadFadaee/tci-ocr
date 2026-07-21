#!/usr/bin/env python3
"""Merge multiple OCR dataset directories into one collision-free dataset."""

from __future__ import annotations

import argparse
import csv
import os
import shutil
import sys
import time
from dataclasses import dataclass
from pathlib import Path

from ocr_dataset import CSV_COLUMNS, read_rows, sha256_file


@dataclass(frozen=True)
class DatasetSource:
    csv_path: Path
    root: Path
    rows: int


class Progress:
    def __init__(self, total: int) -> None:
        self.total = total
        self.started = time.monotonic()
        self.last_drawn = 0.0
        self.last_width = 0

    def update(self, completed: int, *, force: bool = False) -> None:
        now = time.monotonic()
        if not force and completed != self.total and now - self.last_drawn < 0.1:
            return
        elapsed = max(now - self.started, 0.001)
        ratio = completed / self.total if self.total else 1.0
        filled = int(min(ratio, 1.0) * 30)
        bar = "#" * filled + "-" * (30 - filled)
        message = (
            f"\r[{bar}] {completed:,}/{self.total:,} "
            f"({ratio:6.2%}) {completed / elapsed:,.1f} images/s"
        )
        print(
            message + " " * max(0, self.last_width - len(message)),
            end="",
            file=sys.stderr,
            flush=True,
        )
        self.last_width = len(message)
        self.last_drawn = now

    def finish(self, completed: int) -> None:
        self.update(completed, force=True)
        print(file=sys.stderr)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "sources",
        nargs="+",
        type=Path,
        help="Dataset directories or labels.csv files to merge",
    )
    parser.add_argument(
        "--output", type=Path, default=Path("merged_dataset"),
        help="New destination directory (default: merged_dataset)",
    )
    parser.add_argument("--batch-size", type=int, default=10_000)
    parser.add_argument(
        "--mode",
        choices=("copy", "hardlink"),
        default="copy",
        help="Copy images, or hard-link them to save disk space",
    )
    parser.add_argument(
        "--flush-every",
        type=int,
        default=1_000,
        help="Flush labels.csv after this many rows",
    )
    return parser.parse_args()


def resolve_source(path: Path) -> tuple[Path, Path]:
    if path.is_dir():
        csv_path = path / "labels.csv"
        root = path
    else:
        csv_path = path
        root = path.parent
    if not csv_path.is_file():
        raise FileNotFoundError(f"dataset CSV not found: {csv_path}")
    return csv_path.resolve(), root.resolve()


def count_source(csv_path: Path) -> int:
    return sum(1 for _ in read_rows(csv_path))


def safe_source_image(root: Path, filename: str) -> Path:
    if not filename:
        raise ValueError("encountered an empty filename")
    image = (root / filename).resolve()
    if not image.is_relative_to(root):
        raise ValueError(f"image path escapes its dataset directory: {filename!r}")
    if not image.is_file():
        raise FileNotFoundError(f"source image not found: {image}")
    return image


def transfer_image(source: Path, destination: Path, mode: str) -> None:
    destination.parent.mkdir(parents=True, exist_ok=True)
    if mode == "hardlink":
        os.link(source, destination)
    else:
        shutil.copyfile(source, destination)


def main() -> int:
    args = parse_args()
    if args.batch_size <= 0 or args.flush_every <= 0:
        print("error: --batch-size and --flush-every must be positive", file=sys.stderr)
        return 2

    output = args.output.resolve()
    if output.exists():
        print(f"error: output already exists: {output}", file=sys.stderr)
        return 2

    try:
        resolved = [resolve_source(source) for source in args.sources]
        if any(root == output or root in output.parents for _, root in resolved):
            raise ValueError("the output directory cannot be inside a source dataset")
        sources = [
            DatasetSource(csv_path, root, count_source(csv_path))
            for csv_path, root in resolved
        ]
    except (OSError, ValueError) as error:
        print(f"error: {error}", file=sys.stderr)
        return 2

    total = sum(source.rows for source in sources)
    output.parent.mkdir(parents=True, exist_ok=True)
    staging = output.parent / f".{output.name}.merge-{os.getpid()}"
    if staging.exists():
        print(f"error: staging directory already exists: {staging}", file=sys.stderr)
        return 2

    staging.mkdir()
    progress = Progress(total)
    progress.update(0, force=True)
    completed = 0

    try:
        csv_path = staging / "labels.csv"
        with csv_path.open("w", newline="", encoding="utf-8") as output_file:
            writer = csv.DictWriter(output_file, fieldnames=CSV_COLUMNS)
            writer.writeheader()
            for source in sources:
                for row in read_rows(source.csv_path):
                    source_image = safe_source_image(source.root, row["filename"])
                    batch_id = completed // args.batch_size
                    suffix = source_image.suffix.lower() or ".img"
                    relative = (
                        Path("images")
                        / f"{batch_id:06d}"
                        / f"{completed:09d}{suffix}"
                    )
                    transfer_image(source_image, staging / relative, args.mode)
                    writer.writerow(
                        {
                            "filename": relative.as_posix(),
                            "label": row["label"],
                            "batch_id": str(batch_id),
                            "verified": row["verified"],
                            "hash": row["hash"] or sha256_file(source_image),
                        }
                    )
                    completed += 1
                    if completed % args.flush_every == 0:
                        output_file.flush()
                    progress.update(completed)
            output_file.flush()
            os.fsync(output_file.fileno())

        staging.rename(output)
    except (OSError, ValueError) as error:
        progress.finish(completed)
        print(f"error after {completed:,} images: {error}", file=sys.stderr)
        print(f"Incomplete staging data was left at: {staging}", file=sys.stderr)
        return 1
    except KeyboardInterrupt:
        progress.finish(completed)
        print("Interrupted.", file=sys.stderr)
        print(f"Incomplete staging data was left at: {staging}", file=sys.stderr)
        return 130

    progress.finish(completed)
    print(
        f"Merged {completed:,} images from {len(sources):,} datasets into {output}"
    )
    print("Exact duplicate hashes were preserved; run find_duplicates.py to report them.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
