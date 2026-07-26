from __future__ import annotations

import base64
import csv
import hashlib
import json
import os
import re
import shutil
import socket
import sqlite3
import subprocess
import sys
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path


PROJECT = Path(__file__).resolve().parents[1]
PNG = b"\x89PNG\r\n\x1a\n" + b"test-image-payload"


class ToolTests(unittest.TestCase):
    def make_dataset(
        self,
        root: Path,
        *,
        label: str,
        verified: str,
        digest: str,
    ) -> Path:
        image = root / "images" / "000000" / "000000000.png"
        image.parent.mkdir(parents=True)
        image.write_bytes(PNG)
        csv_path = root / "labels.csv"
        with csv_path.open("w", newline="", encoding="utf-8") as output:
            writer = csv.DictWriter(
                output,
                fieldnames=["filename", "label", "batch_id", "verified", "hash"],
            )
            writer.writeheader()
            writer.writerow(
                {
                    "filename": image.relative_to(root).as_posix(),
                    "label": label,
                    "batch_id": "0",
                    "verified": verified,
                    "hash": digest,
                }
            )
        return image

    def test_downloader_resumes_and_duplicate_finder_reports(self):
        with tempfile.TemporaryDirectory() as temp:
            dataset = Path(temp) / "dataset"
            fixture = Path(temp) / "image.png"
            fixture.write_bytes(PNG)
            url = fixture.as_uri()
            first = subprocess.run(
                [
                    sys.executable,
                    str(PROJECT / "download_dataset.py"),
                    "--url", url,
                    "--output", str(dataset),
                    "--count", "3",
                    "--workers", "2",
                    "--flush-every", "1",
                ],
                text=True,
                capture_output=True,
            )
            self.assertEqual(first.returncode, 0, first.stderr)
            second = subprocess.run(
                [
                    sys.executable,
                    str(PROJECT / "download_dataset.py"),
                    "--url", url,
                    "--output", str(dataset),
                    "--count", "5",
                    "--workers", "2",
                ],
                text=True,
                capture_output=True,
            )
            self.assertEqual(second.returncode, 0, second.stderr)

            with (dataset / "labels.csv").open(newline="", encoding="utf-8") as f:
                rows = list(csv.DictReader(f))
            self.assertEqual(len(rows), 5)
            self.assertEqual(list(rows[0]), ["filename", "label", "batch_id", "verified", "hash"])
            self.assertEqual(rows[0]["hash"], hashlib.sha256(PNG).hexdigest())
            self.assertTrue(all((dataset / row["filename"]).is_file() for row in rows))

            report = dataset / "duplicates.csv"
            result = subprocess.run(
                [
                    sys.executable,
                    str(PROJECT / "find_duplicates.py"),
                    "--csv", str(dataset / "labels.csv"),
                    "--output", str(report),
                ],
                text=True,
                capture_output=True,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            with report.open(newline="", encoding="utf-8") as f:
                duplicates = list(csv.DictReader(f))
            self.assertEqual(len(duplicates), 4)

    def test_verifier_normalizes_persian_digits_and_saves_on_quit(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            image = root / "images" / "000000" / "000000000.png"
            image.parent.mkdir(parents=True)
            image.write_bytes(PNG)
            csv_path = root / "labels.csv"
            with csv_path.open("w", newline="", encoding="utf-8") as output:
                writer = csv.DictWriter(
                    output,
                    fieldnames=["filename", "label", "batch_id", "verified", "hash"],
                )
                writer.writeheader()
                for suffix in ("0", "1"):
                    writer.writerow(
                        {
                            "filename": image.relative_to(root).as_posix(),
                            "label": "",
                            "batch_id": "0",
                            "verified": "0",
                            "hash": hashlib.sha256(PNG + suffix.encode()).hexdigest(),
                        }
                    )

            binary_dir = root / "bin"
            binary_dir.mkdir()
            fake_kitten = binary_dir / "kitten"
            fake_kitten.write_text("#!/bin/sh\nexit 0\n", encoding="utf-8")
            fake_kitten.chmod(0o755)
            environment = os.environ.copy()
            environment["PATH"] = f"{binary_dir}{os.pathsep}{environment.get('PATH', '')}"
            result = subprocess.run(
                [
                    sys.executable,
                    str(PROJECT / "verify_labels.py"),
                    "--csv", str(csv_path),
                ],
                input="۱۲۳\nq\n",
                text=True,
                capture_output=True,
                env=environment,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            with csv_path.open(newline="", encoding="utf-8") as source:
                rows = list(csv.DictReader(source))
            self.assertEqual(rows[0]["label"], "123")
            self.assertEqual(rows[0]["verified"], "1")
            self.assertEqual(rows[1]["verified"], "0")

    def test_merger_reindexes_images_and_preserves_metadata(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            expected_hash = hashlib.sha256(PNG).hexdigest()
            source_one = root / "download-one"
            source_two = root / "download-two"
            original_one = self.make_dataset(
                source_one, label="123", verified="1", digest=expected_hash
            )
            original_two = self.make_dataset(
                source_two, label="", verified="0", digest=""
            )
            destination = root / "combined"

            result = subprocess.run(
                [
                    sys.executable,
                    str(PROJECT / "merge_datasets.py"),
                    str(source_one),
                    str(source_two / "labels.csv"),
                    "--output", str(destination),
                    "--batch-size", "1",
                ],
                text=True,
                capture_output=True,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            with (destination / "labels.csv").open(
                newline="", encoding="utf-8"
            ) as source:
                rows = list(csv.DictReader(source))

            self.assertEqual(len(rows), 2)
            self.assertEqual([row["batch_id"] for row in rows], ["0", "1"])
            self.assertEqual([row["label"] for row in rows], ["123", ""])
            self.assertEqual([row["verified"] for row in rows], ["1", "0"])
            self.assertEqual([row["hash"] for row in rows], [expected_hash] * 2)
            self.assertNotEqual(rows[0]["filename"], rows[1]["filename"])
            self.assertTrue(
                all((destination / row["filename"]).read_bytes() == PNG for row in rows)
            )
            self.assertTrue(original_one.is_file())
            self.assertTrue(original_two.is_file())

    def test_php_web_verifier_initializes_saves_skips_edits_and_syncs(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP is not installed")
        sqlite_support = subprocess.run(
            [php, "-r", "exit(extension_loaded('pdo_sqlite') ? 0 : 1);"]
        )
        if sqlite_support.returncode != 0:
            self.skipTest("PHP PDO SQLite is not installed")

        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            dataset = root / "dataset"
            image = dataset / "images" / "000000" / "000000000.png"
            image.parent.mkdir(parents=True)
            web_png = base64.b64decode(
                "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwC"
                "AAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII="
            )
            image.write_bytes(web_png)
            outside_image = root / "outside.png"
            outside_image.write_bytes(web_png)
            csv_path = dataset / "labels.csv"
            with csv_path.open("w", newline="", encoding="utf-8") as output:
                writer = csv.DictWriter(
                    output,
                    fieldnames=["filename", "label", "batch_id", "verified", "hash"],
                )
                writer.writeheader()
                for index, (label, verified) in enumerate(
                    [("123", "1"), ("", "0"), ("", "0")]
                ):
                    writer.writerow(
                        {
                            "filename": (
                                "../outside.png"
                                if index == 2
                                else image.relative_to(dataset).as_posix()
                            ),
                            "label": label,
                            "batch_id": "0",
                            "verified": verified,
                            "hash": hashlib.sha256(web_png + str(index).encode()).hexdigest(),
                        }
                    )

            with socket.socket() as listener:
                listener.bind(("127.0.0.1", 0))
                port = listener.getsockname()[1]

            environment = os.environ.copy()
            environment["OCR_DATASET_DIR"] = str(dataset)
            server = subprocess.Popen(
                [
                    php,
                    "-S",
                    f"127.0.0.1:{port}",
                    "-t",
                    str(PROJECT / "web"),
                ],
                stdout=subprocess.DEVNULL,
                stderr=subprocess.PIPE,
                text=True,
                env=environment,
            )
            base_url = f"http://127.0.0.1:{port}/"

            def get(path: str = "") -> tuple[int, bytes, dict[str, str]]:
                request = urllib.request.Request(base_url + path)
                with urllib.request.urlopen(request, timeout=10) as response:
                    return response.status, response.read(), dict(response.headers)

            def post(
                values: dict[str, str],
                *,
                accept_json: bool = False,
            ) -> tuple[int, bytes, dict[str, str]]:
                body = urllib.parse.urlencode(values).encode()
                request = urllib.request.Request(base_url, data=body, method="POST")
                if accept_json:
                    request.add_header("Accept", "application/json")
                with urllib.request.urlopen(request, timeout=20) as response:
                    return response.status, response.read(), dict(response.headers)

            try:
                for _ in range(50):
                    try:
                        status, page, _ = get()
                        if status == 200:
                            break
                    except urllib.error.URLError:
                        time.sleep(0.05)
                else:
                    self.fail("PHP development server did not start")

                self.assertIn(b"Initialize verifier", page)
                status, payload, _ = post({"action": "initialize"})
                self.assertEqual(status, 200)
                self.assertIn(b'"done":true', payload)

                _, page, _ = get()
                self.assertIn(b'OCR verifier', page)
                self.assertRegex(page, rb'name="row_id" value="1"')
                self.assertRegex(page, rb'name="revision" value="0"')
                self.assertIn(b'enterkeyhint="go"', page)
                self.assertIn(b'id="mobile-counts"', page)
                node = shutil.which("node")
                if node is not None:
                    scripts = re.findall(rb"<script>(.*?)</script>", page, re.DOTALL)
                    fast_entry_script = next(
                        script for script in scripts if b"submitFast" in script
                    )
                    script_path = root / "fast-entry.js"
                    script_path.write_bytes(fast_entry_script)
                    syntax = subprocess.run(
                        [node, "--check", str(script_path)],
                        text=True,
                        capture_output=True,
                    )
                    self.assertEqual(syntax.returncode, 0, syntax.stderr)

                status, image_body, headers = get("?action=image&id=1")
                self.assertEqual(status, 200)
                self.assertEqual(image_body, web_png)
                self.assertEqual(headers["Content-Type"], "image/png")

                with self.assertRaises(urllib.error.HTTPError) as invalid:
                    post(
                        {
                            "action": "save",
                            "row_id": "1",
                            "revision": "0",
                            "mode": "queue",
                            "label": "12x",
                        }
                    )
                self.assertEqual(invalid.exception.code, 422)
                self.assertIn(b"Enter digits only", invalid.exception.read())
                invalid.exception.close()

                status, payload, headers = post(
                    {
                        "action": "save",
                        "row_id": "1",
                        "revision": "0",
                        "mode": "queue",
                        "label": "۱۲۳۴",
                    },
                    accept_json=True,
                )
                self.assertEqual(status, 200)
                self.assertTrue(headers["Content-Type"].startswith("application/json"))
                saved_payload = json.loads(payload)
                self.assertEqual(saved_payload["status"], "saved")
                self.assertEqual(saved_payload["revision"], 1)
                self.assertEqual(saved_payload["row"]["id"], 2)
                self.assertEqual(saved_payload["stats"]["verified"], 2)
                self.assertEqual(saved_payload["stats"]["pending"], 1)

                _, page, _ = get()
                self.assertRegex(page, rb'name="row_id" value="2"')
                self.assertRegex(page, rb'name="revision" value="1"')

                with self.assertRaises(urllib.error.HTTPError) as stale:
                    post(
                        {
                            "action": "save",
                            "row_id": "1",
                            "revision": "0",
                            "mode": "queue",
                            "label": "999",
                        }
                    )
                self.assertEqual(stale.exception.code, 409)
                self.assertIn(b"stale", stale.exception.read())
                stale.exception.close()

                with self.assertRaises(urllib.error.HTTPError) as escaped_image:
                    get("?action=image&id=2")
                self.assertEqual(escaped_image.exception.code, 404)
                escaped_image.exception.close()

                status, payload, _ = post(
                    {
                        "action": "skip",
                        "row_id": "2",
                        "revision": "1",
                    },
                    accept_json=True,
                )
                self.assertEqual(status, 200)
                skipped_payload = json.loads(payload)
                self.assertEqual(skipped_payload["status"], "skipped")
                self.assertEqual(skipped_payload["revision"], 2)
                self.assertEqual(skipped_payload["row"]["id"], 2)

                _, page, _ = get()
                self.assertRegex(page, rb'name="row_id" value="2"')

                _, edit_page, _ = get("?edit=1")
                revision = re.search(rb'name="revision" value="(\d+)"', edit_page)
                self.assertIsNotNone(revision)
                _, corrected_page, _ = post(
                    {
                        "action": "save",
                        "row_id": "1",
                        "revision": revision.group(1).decode(),
                        "mode": "edit",
                        "label": "4567",
                    }
                )
                self.assertIn(b"corrected", corrected_page)

                state = sqlite3.connect(dataset / "verification.sqlite")
                try:
                    saved = state.execute(
                        "SELECT label, verified, dirty FROM labels WHERE id = 1"
                    ).fetchone()
                    pending = state.execute(
                        "SELECT value FROM metadata WHERE key = 'pending_count'"
                    ).fetchone()
                finally:
                    state.close()
                self.assertEqual(saved, ("4567", 1, 1))
                self.assertEqual(pending, ("1",))

                _, synced_page, _ = post({"action": "sync"})
                self.assertIn(b"updated atomically", synced_page)
                with csv_path.open(newline="", encoding="utf-8") as source:
                    rows = list(csv.DictReader(source))
                self.assertEqual(rows[1]["label"], "4567")
                self.assertEqual(rows[1]["verified"], "1")
                self.assertEqual(rows[2]["verified"], "0")

                state = sqlite3.connect(dataset / "verification.sqlite")
                try:
                    pending = state.execute(
                        "SELECT value FROM metadata WHERE key = 'pending_count'"
                    ).fetchone()
                finally:
                    state.close()
                self.assertEqual(pending, ("0",))

                with self.assertRaises(urllib.error.HTTPError) as missing:
                    get("?action=image&id=999")
                self.assertEqual(missing.exception.code, 404)
                missing.exception.close()

                with csv_path.open("a", encoding="utf-8") as output:
                    output.write("\n")
                with self.assertRaises(urllib.error.HTTPError) as conflict:
                    get()
                self.assertEqual(conflict.exception.code, 409)
                self.assertIn(b"The source CSV changed", conflict.exception.read())
                conflict.exception.close()
            finally:
                server.terminate()
                try:
                    server.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait(timeout=5)
                if server.stderr is not None:
                    server.stderr.close()


if __name__ == "__main__":
    unittest.main()
