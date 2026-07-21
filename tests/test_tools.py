from __future__ import annotations

import csv
import hashlib
import os
import subprocess
import sys
import tempfile
import unittest
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


if __name__ == "__main__":
    unittest.main()
