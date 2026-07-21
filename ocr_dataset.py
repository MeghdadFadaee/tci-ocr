"""Shared helpers for the Persian-number OCR dataset scripts."""

from __future__ import annotations

import csv
import hashlib
import os
from pathlib import Path
from typing import Iterator, TextIO


CSV_COLUMNS = ("filename", "label", "batch_id", "verified", "hash")


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_file(path: Path, chunk_size: int = 1024 * 1024) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as source:
        while chunk := source.read(chunk_size):
            digest.update(chunk)
    return digest.hexdigest()


def image_extension(data: bytes, content_type: str = "") -> str:
    """Return a conservative extension using magic bytes first."""
    if data.startswith(b"\xff\xd8\xff"):
        return ".jpg"
    if data.startswith(b"\x89PNG\r\n\x1a\n"):
        return ".png"
    if data.startswith((b"GIF87a", b"GIF89a")):
        return ".gif"
    if data.startswith(b"BM"):
        return ".bmp"
    if len(data) >= 12 and data[:4] == b"RIFF" and data[8:12] == b"WEBP":
        return ".webp"

    media_type = content_type.partition(";")[0].strip().lower()
    return {
        "image/jpeg": ".jpg",
        "image/png": ".png",
        "image/gif": ".gif",
        "image/bmp": ".bmp",
        "image/webp": ".webp",
    }.get(media_type, ".img")


def open_dataset_for_append(path: Path) -> tuple[TextIO, csv.DictWriter]:
    path.parent.mkdir(parents=True, exist_ok=True)
    exists = path.exists() and path.stat().st_size > 0
    output = path.open("a", newline="", encoding="utf-8")
    writer = csv.DictWriter(output, fieldnames=CSV_COLUMNS)
    if not exists:
        writer.writeheader()
        output.flush()
    return output, writer


def read_rows(path: Path) -> Iterator[dict[str, str]]:
    with path.open(newline="", encoding="utf-8") as source:
        reader = csv.DictReader(source)
        if reader.fieldnames != list(CSV_COLUMNS):
            raise ValueError(
                f"{path} has columns {reader.fieldnames!r}; expected {list(CSV_COLUMNS)!r}"
            )
        yield from reader


def scan_dataset(path: Path) -> tuple[int, int]:
    """Return (row count, next numeric image id)."""
    if not path.exists() or path.stat().st_size == 0:
        return 0, 0

    count = 0
    highest_id = -1
    for row in read_rows(path):
        count += 1
        try:
            highest_id = max(highest_id, int(Path(row["filename"]).stem))
        except ValueError:
            # Custom filenames do not prevent resuming; count remains a safe fallback.
            pass
    return count, max(count, highest_id + 1)


def atomic_write(path: Path, data: bytes) -> None:
    """Write bytes without leaving a partially written image at the final path."""
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(f".{path.name}.{os.getpid()}.tmp")
    try:
        with temporary.open("wb") as output:
            output.write(data)
        os.replace(temporary, path)
    finally:
        temporary.unlink(missing_ok=True)
