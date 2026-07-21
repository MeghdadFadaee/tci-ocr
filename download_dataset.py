#!/usr/bin/env python3
"""Download a large, hash-indexed OCR image dataset from an HTTP API."""

from __future__ import annotations

import argparse
import concurrent.futures
import os
import random
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from pathlib import Path

from ocr_dataset import (
    atomic_write,
    image_extension,
    open_dataset_for_append,
    scan_dataset,
    sha256_bytes,
)


@dataclass(frozen=True)
class DownloadedImage:
    index: int
    data: bytes
    content_type: str


class Progress:
    def __init__(self, current: int, total: int) -> None:
        self.current = current
        self.initial = current
        self.total = total
        self.started = time.monotonic()
        self.last_width = 0

    def update(self, current: int, failures: int = 0) -> None:
        self.current = current
        elapsed = max(time.monotonic() - self.started, 0.001)
        newly_downloaded = max(current - self.initial, 0)
        # Rate is scoped to this process, not previously resumed rows.
        rate = newly_downloaded / elapsed
        ratio = min(current / self.total, 1.0) if self.total else 1.0
        bar_width = 30
        filled = int(ratio * bar_width)
        bar = "#" * filled + "-" * (bar_width - filled)
        message = (
            f"\r[{bar}] {current:,}/{self.total:,} "
            f"({ratio:6.2%}) {rate:,.1f} img/s failures={failures:,}"
        )
        padding = " " * max(0, self.last_width - len(message))
        print(message + padding, end="", file=sys.stderr, flush=True)
        self.last_width = len(message)

    def finish(self) -> None:
        print(file=sys.stderr)


def parse_assignments(values: list[str], option: str) -> dict[str, str]:
    result: dict[str, str] = {}
    for value in values:
        name, separator, content = value.partition("=")
        if not separator or not name.strip():
            raise ValueError(f"{option} expects NAME=VALUE, got {value!r}")
        result[name.strip()] = content
    return result


def build_url(url: str, params: dict[str, str], index: int) -> str:
    rendered = url.replace("{index}", str(index))
    if not params:
        return rendered
    parts = urllib.parse.urlsplit(rendered)
    existing = urllib.parse.parse_qsl(parts.query, keep_blank_values=True)
    query = urllib.parse.urlencode(existing + list(params.items()))
    return urllib.parse.urlunsplit(
        (parts.scheme, parts.netloc, parts.path, query, parts.fragment)
    )


def fetch_image(
    *,
    index: int,
    url: str,
    headers: dict[str, str],
    params: dict[str, str],
    timeout: float,
    retries: int,
    retry_backoff: float,
    delay: float,
) -> DownloadedImage:
    if delay:
        time.sleep(delay)

    request_url = build_url(url, params, index)
    last_error: Exception | None = None
    for attempt in range(retries + 1):
        try:
            request = urllib.request.Request(request_url, headers=headers)
            with urllib.request.urlopen(request, timeout=timeout) as response:
                data = response.read()
                content_type = response.headers.get("Content-Type", "")
            if not data:
                raise ValueError("API returned an empty response")
            if content_type.lower().startswith(("text/", "application/json")):
                preview = data[:120].decode("utf-8", errors="replace")
                raise ValueError(
                    f"API returned {content_type!r}, not image bytes: {preview!r}"
                )
            return DownloadedImage(index, data, content_type)
        except (OSError, ValueError, urllib.error.HTTPError) as error:
            last_error = error
            if attempt < retries:
                jitter = random.random() * 0.25
                time.sleep(retry_backoff * (2**attempt) + jitter)
    assert last_error is not None
    raise last_error


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--url", required=True, help="Image API URL; {index} is optional")
    parser.add_argument("--output", type=Path, default=Path("dataset"))
    parser.add_argument("--count", type=int, default=1_000_000)
    parser.add_argument("--workers", type=int, default=16)
    parser.add_argument("--batch-size", type=int, default=10_000)
    parser.add_argument("--timeout", type=float, default=30.0)
    parser.add_argument("--retries", type=int, default=4)
    parser.add_argument("--retry-backoff", type=float, default=0.5)
    parser.add_argument("--delay", type=float, default=0.0, help="Delay per request")
    parser.add_argument("--max-failures", type=int, default=100)
    parser.add_argument("--flush-every", type=int, default=100)
    parser.add_argument(
        "--header", action="append", default=[], metavar="NAME=VALUE",
        help="HTTP header; repeat as needed",
    )
    parser.add_argument(
        "--param", action="append", default=[], metavar="NAME=VALUE",
        help="Query parameter; repeat as needed",
    )
    return parser.parse_args()


def validate_args(args: argparse.Namespace) -> None:
    for name in ("count", "workers", "batch_size", "flush_every"):
        if getattr(args, name) <= 0:
            raise ValueError(f"--{name.replace('_', '-')} must be greater than zero")
    if args.retries < 0 or args.max_failures <= 0:
        raise ValueError("--retries must be non-negative and --max-failures positive")


def main() -> int:
    args = parse_args()
    try:
        validate_args(args)
        headers = parse_assignments(args.header, "--header")
        params = parse_assignments(args.param, "--param")
    except ValueError as error:
        print(f"error: {error}", file=sys.stderr)
        return 2

    headers.setdefault("User-Agent", "tci-ocr-dataset-builder/1.0")
    csv_path = args.output / "labels.csv"
    try:
        completed, next_index = scan_dataset(csv_path)
    except (OSError, ValueError) as error:
        print(f"error: cannot resume dataset: {error}", file=sys.stderr)
        return 2

    if completed >= args.count:
        print(f"Dataset already contains {completed:,} images (target: {args.count:,}).")
        return 0

    output, writer = open_dataset_for_append(csv_path)
    progress = Progress(completed, args.count)
    progress.update(completed)
    failures = 0
    written_this_run = 0

    def submit(executor: concurrent.futures.ThreadPoolExecutor, index: int):
        return executor.submit(
            fetch_image,
            index=index,
            url=args.url,
            headers=headers,
            params=params,
            timeout=args.timeout,
            retries=args.retries,
            retry_backoff=args.retry_backoff,
            delay=args.delay,
        )

    executor = concurrent.futures.ThreadPoolExecutor(
        max_workers=args.workers, thread_name_prefix="image-download"
    )
    pending: dict[concurrent.futures.Future[DownloadedImage], int] = {}
    try:
        initial = min(args.workers, args.count - completed)
        for index in range(next_index, next_index + initial):
            pending[submit(executor, index)] = index
        next_index += initial

        while pending and completed < args.count:
            done, _ = concurrent.futures.wait(
                pending, return_when=concurrent.futures.FIRST_COMPLETED
            )
            for future in done:
                index = pending.pop(future)
                try:
                    downloaded = future.result()
                except Exception as error:
                    failures += 1
                    print(f"\nrequest {index} failed: {error}", file=sys.stderr)
                    if failures >= args.max_failures:
                        raise RuntimeError(
                            f"stopped after {failures} failed requests"
                        ) from error
                    pending[submit(executor, index)] = index
                    continue

                extension = image_extension(downloaded.data, downloaded.content_type)
                batch_id = downloaded.index // args.batch_size
                relative = Path("images") / f"{batch_id:06d}" / (
                    f"{downloaded.index:09d}{extension}"
                )
                atomic_write(args.output / relative, downloaded.data)
                writer.writerow(
                    {
                        "filename": relative.as_posix(),
                        "label": "",
                        "batch_id": str(batch_id),
                        "verified": "0",
                        "hash": sha256_bytes(downloaded.data),
                    }
                )
                completed += 1
                written_this_run += 1
                if written_this_run % args.flush_every == 0:
                    output.flush()
                    os.fsync(output.fileno())
                progress.update(completed, failures)

                if next_index < args.count and completed + len(pending) < args.count:
                    pending[submit(executor, next_index)] = next_index
                    next_index += 1
    except KeyboardInterrupt:
        print("\nInterrupted; saving completed rows...", file=sys.stderr)
        return_code = 130
    except RuntimeError as error:
        print(f"\nerror: {error}", file=sys.stderr)
        return_code = 1
    else:
        return_code = 0
    finally:
        for future in pending:
            future.cancel()
        executor.shutdown(wait=False, cancel_futures=True)
        output.flush()
        os.fsync(output.fileno())
        output.close()
        progress.finish()

    print(f"Saved {completed:,} rows in {csv_path}")
    return return_code


if __name__ == "__main__":
    raise SystemExit(main())
