#!/usr/bin/env python3
"""Show OCR images with Kitty and interactively verify their labels."""

from __future__ import annotations

import argparse
import csv
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

from ocr_dataset import CSV_COLUMNS


DIGIT_TRANSLATION = str.maketrans(
    "۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩", "01234567890123456789"
)
ALLOWED_DIGITS = frozenset("0123456789۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩")


def kitty_command() -> list[str] | None:
    if executable := shutil.which("kitten"):
        return [executable, "icat"]
    if executable := shutil.which("kitty"):
        return [executable, "+kitten", "icat"]
    return None


def show_image(command: list[str], image: Path) -> None:
    subprocess.run(
        command + ["--clear", "--transfer-mode=file", str(image)],
        check=True,
    )


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--csv", type=Path, default=Path("dataset/labels.csv"))
    parser.add_argument(
        "--images-root",
        type=Path,
        help="Root for CSV filenames (default: directory containing the CSV)",
    )
    parser.add_argument("--start", type=int, default=0, help="Skip this many CSV rows")
    parser.add_argument(
        "--reverify", action="store_true", help="Also prompt for verified rows"
    )
    parser.add_argument(
        "--keep-digit-script",
        action="store_true",
        help="Keep Persian/Arabic digits instead of normalizing labels to ASCII",
    )
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    command = kitty_command()
    if command is None:
        print(
            "error: Kitty's 'kitten' or 'kitty' executable was not found in PATH",
            file=sys.stderr,
        )
        return 2
    if args.start < 0:
        print("error: --start must be non-negative", file=sys.stderr)
        return 2

    root = args.images_root or args.csv.parent
    verified_now = 0
    quit_requested = False
    temporary_path: Path | None = None

    try:
        with args.csv.open(newline="", encoding="utf-8") as source:
            reader = csv.DictReader(source)
            if reader.fieldnames != list(CSV_COLUMNS):
                raise ValueError(
                    f"columns are {reader.fieldnames!r}; expected {list(CSV_COLUMNS)!r}"
                )

            file_descriptor, temporary_name = tempfile.mkstemp(
                prefix=f".{args.csv.name}.", suffix=".tmp", dir=args.csv.parent
            )
            temporary_path = Path(temporary_name)
            with os.fdopen(file_descriptor, "w", newline="", encoding="utf-8") as output:
                writer = csv.DictWriter(output, fieldnames=CSV_COLUMNS)
                writer.writeheader()

                for index, row in enumerate(reader):
                    should_prompt = (
                        index >= args.start
                        and (args.reverify or row["verified"].strip() != "1")
                        and not quit_requested
                    )
                    if should_prompt:
                        image = root / row["filename"]
                        if not image.is_file():
                            print(f"\nMissing image, skipped: {image}", file=sys.stderr)
                        else:
                            show_image(command, image)
                            while True:
                                current = row["label"] or "unlabeled"
                                try:
                                    answer = input(
                                        f"[{index + 1}] {row['filename']} "
                                        f"(current: {current}) number [s=skip, q=quit]: "
                                    ).strip()
                                except (KeyboardInterrupt, EOFError):
                                    print("\nSaving and quitting...", file=sys.stderr)
                                    quit_requested = True
                                    break
                                if answer.lower() == "q":
                                    quit_requested = True
                                    break
                                if answer.lower() == "s" or not answer:
                                    break
                                if not all(character in ALLOWED_DIGITS for character in answer):
                                    print("Enter digits only (ASCII, Persian, or Arabic).")
                                    continue
                                normalized = (
                                    answer if args.keep_digit_script else answer.translate(DIGIT_TRANSLATION)
                                )
                                row["label"] = normalized
                                row["verified"] = "1"
                                verified_now += 1
                                break
                    writer.writerow(row)

                output.flush()
                os.fsync(output.fileno())

        assert temporary_path is not None
        os.replace(temporary_path, args.csv)
        temporary_path = None
    except KeyboardInterrupt:
        print("\nInterrupted. The current CSV was left unchanged.", file=sys.stderr)
        return 130
    except (OSError, ValueError, subprocess.CalledProcessError) as error:
        print(f"error: {error}. The current CSV was left unchanged.", file=sys.stderr)
        return 1
    finally:
        if temporary_path is not None:
            temporary_path.unlink(missing_ok=True)
        try:
            subprocess.run(command + ["--clear"], check=False) if command else None
        except OSError:
            pass

    print(f"Saved {verified_now:,} newly verified labels to {args.csv}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
