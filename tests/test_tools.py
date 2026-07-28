from __future__ import annotations

import csv
import hashlib
import http.cookiejar
import http.server
import json
import os
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import threading
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path


PROJECT = Path(__file__).resolve().parents[1]
PNG = b"\x89PNG\r\n\x1a\n" + b"test-image-payload"
CSV_COLUMNS = ["filename", "label", "batch_id", "verified", "hash"]


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
            writer = csv.DictWriter(output, fieldnames=CSV_COLUMNS)
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
                    "--url",
                    url,
                    "--output",
                    str(dataset),
                    "--count",
                    "3",
                    "--workers",
                    "2",
                    "--flush-every",
                    "1",
                ],
                text=True,
                capture_output=True,
            )
            self.assertEqual(first.returncode, 0, first.stderr)
            second = subprocess.run(
                [
                    sys.executable,
                    str(PROJECT / "download_dataset.py"),
                    "--url",
                    url,
                    "--output",
                    str(dataset),
                    "--count",
                    "5",
                    "--workers",
                    "2",
                ],
                text=True,
                capture_output=True,
            )
            self.assertEqual(second.returncode, 0, second.stderr)

            with (dataset / "labels.csv").open(newline="", encoding="utf-8") as source:
                rows = list(csv.DictReader(source))
            self.assertEqual(len(rows), 5)
            self.assertEqual(list(rows[0]), CSV_COLUMNS)
            self.assertEqual(rows[0]["hash"], hashlib.sha256(PNG).hexdigest())
            self.assertTrue(all((dataset / row["filename"]).is_file() for row in rows))

            report = dataset / "duplicates.csv"
            result = subprocess.run(
                [
                    sys.executable,
                    str(PROJECT / "find_duplicates.py"),
                    "--csv",
                    str(dataset / "labels.csv"),
                    "--output",
                    str(report),
                ],
                text=True,
                capture_output=True,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            with report.open(newline="", encoding="utf-8") as source:
                duplicates = list(csv.DictReader(source))
            self.assertEqual(len(duplicates), 4)

    def test_verifier_normalizes_persian_digits_and_saves_on_quit(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            image = root / "images" / "000000" / "000000000.png"
            image.parent.mkdir(parents=True)
            image.write_bytes(PNG)
            csv_path = root / "labels.csv"
            with csv_path.open("w", newline="", encoding="utf-8") as output:
                writer = csv.DictWriter(output, fieldnames=CSV_COLUMNS)
                writer.writeheader()
                for suffix in ("0", "1"):
                    writer.writerow(
                        {
                            "filename": image.relative_to(root).as_posix(),
                            "label": "",
                            "batch_id": "0",
                            "verified": "0",
                            "hash": hashlib.sha256(
                                PNG + suffix.encode()
                            ).hexdigest(),
                        }
                    )

            binary_dir = root / "bin"
            binary_dir.mkdir()
            fake_kitten = binary_dir / "kitten"
            fake_kitten.write_text("#!/bin/sh\nexit 0\n", encoding="utf-8")
            fake_kitten.chmod(0o755)
            environment = os.environ.copy()
            environment["PATH"] = (
                f"{binary_dir}{os.pathsep}{environment.get('PATH', '')}"
            )
            result = subprocess.run(
                [
                    sys.executable,
                    str(PROJECT / "verify_labels.py"),
                    "--csv",
                    str(csv_path),
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
                    "--output",
                    str(destination),
                    "--batch-size",
                    "1",
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

    def test_php_panel_collector_validates_and_appends_samples(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP is not installed")

        class PanelHandler(http.server.BaseHTTPRequestHandler):
            sessions: dict[str, dict[str, object]] = {}
            sequence = 0
            latest_code = ""
            reject_credentials = False
            next_fixture: tuple[str, bytes] | None = None

            def log_message(self, *_args):
                return

            def send_body(
                self,
                status: int,
                body: bytes,
                *,
                content_type: str = "text/html; charset=UTF-8",
                headers: dict[str, str] | None = None,
            ) -> None:
                self.send_response(status)
                self.send_header("Content-Type", content_type)
                self.send_header("Content-Length", str(len(body)))
                for name, value in (headers or {}).items():
                    self.send_header(name, value)
                self.end_headers()
                self.wfile.write(body)

            def session_id(self) -> str:
                cookie = self.headers.get("Cookie", "")
                for part in cookie.split(";"):
                    name, separator, value = part.strip().partition("=")
                    if separator and name == "PHPSESSID":
                        return value
                return ""

            def render_login(self, session_id: str) -> bytes:
                type(self).sequence += 1
                nonce = type(self).sequence
                session = type(self).sessions[session_id]
                message = session.pop("message", "")
                notification = (
                    "<script>UIkit.notification("
                    f"{json.dumps(message, ensure_ascii=False)}"
                    ");</script>"
                    if message
                    else ""
                )
                host = self.headers["Host"]
                return (
                    f"{notification}"
                    f'<form action="http://{host}/panel/login/{nonce}" method="POST">'
                    '<input type="hidden" name="redirect" value="">'
                    '<input name="username"><input name="password">'
                    '<img id="loginCaptchaImage" '
                    f'src="http://{host}/panel/captcha/?r={nonce}">'
                    '<input name="captcha"><button name="LoginFromWeb"></button>'
                    "</form>"
                ).encode()

            def do_GET(self):
                path = urllib.parse.urlsplit(self.path).path
                if path in {"/panel", "/panel/"}:
                    session_id = self.session_id()
                    session = type(self).sessions.get(session_id)
                    headers = None
                    if session is None:
                        type(self).sequence += 1
                        session_id = f"session-{type(self).sequence}"
                        session = {}
                        type(self).sessions[session_id] = session
                        headers = {
                            "Set-Cookie": (
                                f"PHPSESSID={session_id}; Path=/; HttpOnly"
                            )
                        }
                    if session.get("authenticated"):
                        body = '<a href="/panel/logout">خروج</a>'.encode()
                    else:
                        body = self.render_login(session_id)
                    self.send_body(
                        200,
                        body,
                        headers=headers,
                    )
                    return

                if path == "/panel/captcha/":
                    session = type(self).sessions.get(self.session_id())
                    if session is None:
                        self.send_body(400, b"missing session")
                        return
                    type(self).sequence += 1
                    fixture = type(self).next_fixture
                    type(self).next_fixture = None
                    if fixture is None:
                        code = str(10000 + type(self).sequence)
                        image = PNG + b"-" + code.encode()
                    else:
                        code, image = fixture
                    session["code"] = code
                    session["image"] = image
                    type(self).latest_code = code
                    self.send_body(
                        200,
                        image,
                        content_type="image/png",
                        headers={"Set-Cookie": "captcha_seen=1; Path=/"},
                    )
                    return

                self.send_body(404, b"not found")

            def do_POST(self):
                path = urllib.parse.urlsplit(self.path).path
                if not path.startswith("/panel/login/"):
                    self.send_body(404, b"not found")
                    return

                length = int(self.headers.get("Content-Length", "0"))
                values = urllib.parse.parse_qs(
                    self.rfile.read(length).decode(),
                    keep_blank_values=True,
                )
                session = type(self).sessions.get(self.session_id())
                if (
                    session is None
                    or "captcha_seen=1" not in self.headers.get("Cookie", "")
                    or values.get("redirect") != [""]
                    or values.get("LoginFromWeb") != ["1"]
                ):
                    self.send_body(400, b"invalid login request")
                    return
                if values.get("captcha", [""])[0] != session.get("code"):
                    session["message"] = "کد امنیتی وارد شده نادرست است."
                    self.send_body(
                        302,
                        b"",
                        headers={"Location": "/panel/"},
                    )
                    return
                if (
                    type(self).reject_credentials
                    or values.get("username", [""])[0] != "username"
                    or values.get("password", [""])[0] != "password"
                ):
                    session["message"] = "نام کاربری یا گذرواژه نادرست است."
                    self.send_body(
                        302,
                        b"",
                        headers={"Location": "/panel/"},
                    )
                    return
                session["authenticated"] = True
                self.send_body(302, b"", headers={"Location": "/panel/"})

        panel_server = http.server.ThreadingHTTPServer(
            ("127.0.0.1", 0),
            PanelHandler,
        )
        panel_thread = threading.Thread(
            target=panel_server.serve_forever,
            daemon=True,
        )
        panel_thread.start()

        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            dataset = root / "dataset"
            old_hash = hashlib.sha256(PNG).hexdigest()
            old_image = self.make_dataset(
                dataset,
                label="123",
                verified="1",
                digest=old_hash,
            )
            csv_path = dataset / "labels.csv"

            with socket.socket() as listener:
                listener.bind(("127.0.0.1", 0))
                collector_port = listener.getsockname()[1]

            environment = os.environ.copy()
            for name in (
                "PANEL_LOGIN_USERNAME",
                "PANEL_LOGIN_PASSWORD",
                "PANEL_USERNAME",
                "PANEL_PASSWORD",
            ):
                environment.pop(name, None)
            environment["OCR_DATASET_DIR"] = str(dataset)
            environment["PANEL_BASE_URL"] = (
                f"http://127.0.0.1:{panel_server.server_port}"
            )
            environment["PANEL_REQUEST_TIMEOUT"] = "2"
            collector = subprocess.Popen(
                [
                    php,
                    "-S",
                    f"127.0.0.1:{collector_port}",
                    "-t",
                    str(PROJECT / "web"),
                ],
                stdout=subprocess.DEVNULL,
                stderr=subprocess.PIPE,
                text=True,
                env=environment,
            )
            base_url = f"http://127.0.0.1:{collector_port}/"
            opener = urllib.request.build_opener(
                urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
            )

            def get(path: str = "") -> tuple[int, bytes, dict[str, str]]:
                with opener.open(base_url + path, timeout=10) as response:
                    return response.status, response.read(), dict(response.headers)

            def post(
                values: dict[str, str],
            ) -> tuple[int, bytes, dict[str, str]]:
                request = urllib.request.Request(
                    base_url,
                    data=urllib.parse.urlencode(values).encode(),
                    method="POST",
                    headers={"Accept": "application/json"},
                )
                with opener.open(request, timeout=15) as response:
                    return response.status, response.read(), dict(response.headers)

            try:
                for _ in range(60):
                    try:
                        status, page, headers = get()
                        if status == 200:
                            break
                    except urllib.error.URLError:
                        time.sleep(0.05)
                else:
                    self.fail("PHP collector server did not start")

                self.assertIn(b"Captcha collector", page)
                self.assertIn(b'inputmode="numeric"', page)
                self.assertIn(b'enterkeyhint="go"', page)
                self.assertIn("no-store", headers["Cache-Control"])
                self.assertIn("default-src 'self'", headers["Content-Security-Policy"])

                csrf = re.search(
                    rb'name="csrf_token" value="([a-f0-9]{64})"',
                    page,
                )
                challenge = re.search(
                    rb'name="challenge_id" value="([a-f0-9]{32})"',
                    page,
                )
                self.assertIsNotNone(csrf)
                self.assertIsNotNone(challenge)
                csrf_token = csrf.group(1).decode()
                first_challenge = challenge.group(1).decode()

                _, first_image, image_headers = get(
                    f"?action=captcha&id={first_challenge}"
                )
                self.assertEqual(image_headers["Content-Type"], "image/png")

                try:
                    _, rejected_body, _ = post(
                        {
                            "action": "submit",
                            "csrf_token": csrf_token,
                            "challenge_id": first_challenge,
                            "label": "99999",
                        }
                    )
                except urllib.error.HTTPError as problem:
                    details = problem.read().decode(errors="replace")
                    problem.close()
                    self.fail(f"captcha rejection returned {problem.code}: {details}")
                rejected = json.loads(rejected_body)
                self.assertEqual(rejected["outcome"], "rejected")
                self.assertNotEqual(rejected["challenge"]["id"], first_challenge)
                with csv_path.open(newline="", encoding="utf-8") as source:
                    self.assertEqual(len(list(csv.DictReader(source))), 1)

                with self.assertRaises(urllib.error.HTTPError) as stale:
                    post(
                        {
                            "action": "submit",
                            "csrf_token": csrf_token,
                            "challenge_id": first_challenge,
                            "label": "99999",
                        }
                    )
                self.assertEqual(stale.exception.code, 409)
                stale.exception.close()

                accepted_challenge = rejected["challenge"]["id"]
                _, accepted_image, _ = get(
                    f"?action=captcha&id={accepted_challenge}"
                )
                ascii_code = PanelHandler.latest_code
                persian_code = ascii_code.translate(
                    str.maketrans("0123456789", "۰۱۲۳۴۵۶۷۸۹")
                )
                PanelHandler.next_fixture = (ascii_code, accepted_image)
                _, saved_body, _ = post(
                    {
                        "action": "submit",
                        "csrf_token": csrf_token,
                        "challenge_id": accepted_challenge,
                        "label": persian_code,
                    }
                )
                saved = json.loads(saved_body)
                self.assertEqual(saved["outcome"], "saved")
                self.assertEqual(saved["saved_this_session"], 1)

                with csv_path.open(newline="", encoding="utf-8") as source:
                    rows = list(csv.DictReader(source))
                self.assertEqual(len(rows), 2)
                self.assertEqual(rows[0]["filename"], old_image.relative_to(dataset).as_posix())
                self.assertEqual(rows[1]["label"], ascii_code)
                self.assertEqual(rows[1]["batch_id"], "panel")
                self.assertEqual(rows[1]["verified"], "1")
                self.assertEqual(
                    rows[1]["hash"],
                    hashlib.sha256(accepted_image).hexdigest(),
                )
                self.assertEqual(
                    (dataset / rows[1]["filename"]).read_bytes(),
                    accepted_image,
                )

                duplicate_challenge = saved["challenge"]["id"]
                _, duplicate_image, _ = get(
                    f"?action=captcha&id={duplicate_challenge}"
                )
                self.assertEqual(duplicate_image, accepted_image)
                _, duplicate_body, _ = post(
                    {
                        "action": "submit",
                        "csrf_token": csrf_token,
                        "challenge_id": duplicate_challenge,
                        "label": ascii_code,
                    }
                )
                duplicate = json.loads(duplicate_body)
                self.assertEqual(duplicate["outcome"], "duplicate")
                self.assertEqual(duplicate["saved_this_session"], 1)
                with csv_path.open(newline="", encoding="utf-8") as source:
                    self.assertEqual(len(list(csv.DictReader(source))), 2)

                # Removing the old dataset must not require resetting collector state.
                next_challenge = duplicate["challenge"]["id"]
                _, recreated_image, _ = get(
                    f"?action=captcha&id={next_challenge}"
                )
                recreate_code = PanelHandler.latest_code
                shutil.rmtree(dataset)
                _, recreated_body, _ = post(
                    {
                        "action": "submit",
                        "csrf_token": csrf_token,
                        "challenge_id": next_challenge,
                        "label": recreate_code,
                    }
                )
                recreated = json.loads(recreated_body)
                self.assertEqual(recreated["outcome"], "saved")
                with csv_path.open(newline="", encoding="utf-8") as source:
                    recreated_rows = list(csv.DictReader(source))
                self.assertEqual(len(recreated_rows), 1)
                self.assertEqual(
                    (dataset / recreated_rows[0]["filename"]).read_bytes(),
                    recreated_image,
                )

                PanelHandler.reject_credentials = True
                credential_challenge = recreated["challenge"]["id"]
                credential_code = PanelHandler.latest_code
                with self.assertRaises(urllib.error.HTTPError) as credentials:
                    post(
                        {
                            "action": "submit",
                            "csrf_token": csrf_token,
                            "challenge_id": credential_challenge,
                            "label": credential_code,
                        }
                    )
                self.assertEqual(credentials.exception.code, 502)
                credential_payload = json.loads(credentials.exception.read())
                self.assertIn("username or password", credential_payload["error"])
                self.assertIsNone(credential_payload["challenge"])
                credentials.exception.close()
                with csv_path.open(newline="", encoding="utf-8") as source:
                    self.assertEqual(len(list(csv.DictReader(source))), 1)

                node = shutil.which("node")
                if node is not None:
                    syntax = subprocess.run(
                        [node, "--check", str(PROJECT / "web" / "app.js")],
                        text=True,
                        capture_output=True,
                    )
                    self.assertEqual(syntax.returncode, 0, syntax.stderr)
            finally:
                collector.terminate()
                try:
                    collector.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    collector.kill()
                    collector.wait(timeout=5)
                if collector.stderr is not None:
                    collector.stderr.close()
                panel_server.shutdown()
                panel_server.server_close()
                panel_thread.join(timeout=5)


if __name__ == "__main__":
    unittest.main()
