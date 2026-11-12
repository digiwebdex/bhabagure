import http.client
import io
import os
import tempfile
import unittest
import urllib.error
from pathlib import Path
from unittest import mock

from bhabaghure_attendance import config
from bhabaghure_attendance.api import Api, ApiBlocked, ApiRejected, ApiUnavailable


class ConfigTest(unittest.TestCase):
    def test_settings_round_trip_and_only_https_is_accepted(self):
        with tempfile.TemporaryDirectory() as folder, mock.patch.dict(os.environ, {"BHABAGHURE_AGENT_HOME": folder}):
            config.write(config.Settings("https://api.example.test", "192.0.2.10", 4370, 0))
            settings = config.load()
            self.assertEqual("https://api.example.test", settings.api_url)
            self.assertEqual("192.0.2.10", settings.device_address)
            self.assertEqual(Path(folder) / "agent.ini", settings.ini_path)

            with self.assertRaises(config.ConfigError):
                config.write(config.Settings("http://api.example.test", "192.0.2.10"))
            # Plain HTTP only for a local development API.
            config.check_api_url("http://127.0.0.1:8000")

    def test_without_a_device_address_the_settings_still_load(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "agent.ini"
            config.write(config.Settings("https://api.example.test"), path)
            self.assertIsNone(config.load(path).device_address)

            # And an old agent.ini with no [device] section at all.
            path.write_text("[api]\nurl = https://api.example.test\n", encoding="utf-8")
            settings = config.load(path)
            self.assertEqual((None, 4370, 0), (settings.device_address, settings.device_port, settings.comm_key))

    def test_agent_ini_saved_with_a_byte_order_mark_still_loads(self):
        # What Notepad or Windows PowerShell 5.1 leave after a hand edit.
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "agent.ini"
            path.write_bytes(b"\xef\xbb\xbf[api]\r\nurl = https://api.example.test\r\n[device]\r\naddress = 192.168.10.116\r\ncomm_key = 1234\r\n")
            settings = config.load(path)
        self.assertEqual(("https://api.example.test", "192.168.10.116", 1234), (settings.api_url, settings.device_address, settings.comm_key))

    def test_an_agent_ini_that_cannot_be_read_is_not_ready_rather_than_a_crash(self):
        broken = {
            "not UTF-8": b"[api]\nurl = https://api.example.test\n[device]\naddress = caf\xe9\xff\n",
            "no section": b"url = https://api.example.test\n",
            "a port that isn't a number": b"[api]\nurl = https://api.example.test\n[device]\nport = forty\n",
            "a port out of range": b"[api]\nurl = https://api.example.test\n[device]\nport = 70000\n",
            "a key twice": b"[api]\nurl = https://api.example.test\nurl = https://other.example.test\n",
        }
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "agent.ini"
            for problem, content in broken.items():
                with self.subTest(problem):
                    path.write_bytes(content)
                    with self.assertRaises(config.ConfigError):
                        config.load(path)

    def test_write_replaces_the_file_whole_in_utf8_without_a_byte_order_mark(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "agent.ini"
            path.write_bytes(b"\xef\xbb\xbf[api]\nurl = https://old.example.test\n")
            config.write(config.Settings("https://api.example.test", "10.1.2.3", 4370, 55), path)

            self.assertFalse(path.read_bytes().startswith(b"\xef\xbb\xbf"))
            self.assertEqual(["agent.ini"], sorted(entry.name for entry in Path(folder).iterdir()))
            self.assertEqual(55, config.load(path).comm_key)


class SetTokenTest(unittest.TestCase):
    def test_a_token_piped_from_powershell_with_a_byte_order_mark_is_accepted(self):
        from bhabaghure_attendance import __main__ as cli

        token = "bhatt_" + "5a" * 32
        piped = io.TextIOWrapper(io.BytesIO(b"\xef\xbb\xbf" + token.encode("ascii") + b"\r\n"), encoding="cp1252")
        with tempfile.TemporaryDirectory() as folder, mock.patch.dict(os.environ, {"BHABAGHURE_AGENT_HOME": folder}), \
                mock.patch.object(cli.sys, "stdin", piped), mock.patch.object(cli.token_store, "save") as save, \
                mock.patch.object(cli, "_logging"):
            self.assertEqual(0, cli.main(["set-token"]))
        save.assert_called_once_with(token)


class _Response:
    def __init__(self, body: bytes):
        self._body = body

    def read(self):
        return self._body

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False


class Opener:
    def __init__(self, outcome, seen: dict | None = None):
        self.outcome = outcome
        self.seen = seen if seen is not None else {}

    def open(self, request, timeout):
        self.seen["url"] = request.full_url
        self.seen["auth"] = request.get_header("Authorization")
        self.seen["locale"] = request.get_header("X-locale")
        if isinstance(self.outcome, Exception):
            raise self.outcome
        return _Response(self.outcome)


def refusal(status: int, reason: str, body: bytes, content_type: str = "application/json"):
    return urllib.error.HTTPError("u", status, reason, {"Content-Type": content_type}, io.BytesIO(body))


class ApiTest(unittest.TestCase):
    def test_requests_carry_the_token_and_ask_for_english_and_refusals_are_told_apart_from_outages(self):
        seen = {}
        api = Api("https://api.example.test/", "bhatt_" + "0" * 64, opener=Opener(b'{"data": {"stored": 3}}', seen))
        self.assertEqual({"stored": 3}, api.punches("K40", []))
        self.assertEqual("https://api.example.test/api/v1/attendance-agent/punches", seen["url"])
        self.assertEqual("Bearer bhatt_" + "0" * 64, seen["auth"])
        self.assertEqual("en", seen["locale"])

        refused = urllib.error.HTTPError("u", 409, "Conflict", {}, io.BytesIO(b'{"code": "device_changed", "message": "no"}'))
        with self.assertRaises(ApiRejected) as caught:
            Api("https://api.example.test", "t", opener=Opener(refused)).report({})
        self.assertEqual("device_changed", caught.exception.code)

        busy = urllib.error.HTTPError("u", 503, "Unavailable", {}, io.BytesIO(b""))
        with self.assertRaises(ApiUnavailable):
            Api("https://api.example.test", "t", opener=Opener(busy)).check_in(None)
        with self.assertRaises(ApiUnavailable):
            Api("https://api.example.test", "t", opener=Opener(urllib.error.URLError("no route"))).check_in(None)
        # A reply cut short or garbled on the way is an outage too, not a crash with nothing in agent.log.
        for broken in (http.client.IncompleteRead(b"{"), http.client.BadStatusLine("HTTP/1.1 ???")):
            with self.assertRaises(ApiUnavailable):
                Api("https://api.example.test", "t", opener=Opener(broken)).check_in(None)

    def test_a_403_that_is_not_the_apis_answer_is_a_block_in_front_of_it_not_a_token_problem(self):
        challenge = refusal(403, "Forbidden", b"<!DOCTYPE html><title>Just a moment...</title>", "text/html; charset=UTF-8")
        with self.assertRaises(ApiBlocked) as caught:
            Api("https://api.example.test", "t", opener=Opener(challenge)).check_in(None)
        self.assertIn("not from the API", str(caught.exception))
        self.assertIn("Cloudflare", str(caught.exception))

        # Cloudflare's firewall answers in plain text.
        with self.assertRaises(ApiBlocked):
            Api("https://api.example.test", "t", opener=Opener(refusal(403, "Forbidden", b"error code: 1020", "text/plain"))).check_in(None)

        # A 403 from the API itself, in its JSON, stays a refusal.
        own = refusal(403, "Forbidden", b'{"code": "forbidden", "message": "Not for this device."}')
        with self.assertRaises(ApiRejected) as caught:
            Api("https://api.example.test", "t", opener=Opener(own)).check_in(None)
        self.assertNotIsInstance(caught.exception, ApiBlocked)

    def test_a_refused_token_stays_the_token_message(self):
        refused = refusal(401, "Unauthorized", b'{"message": "This device token isn\'t valid.", "code": "token_refused"}')
        with self.assertRaises(ApiRejected) as caught:
            Api("https://api.example.test", "t", opener=Opener(refused)).check_in(None)
        self.assertEqual(("token_refused", 401), (caught.exception.code, caught.exception.status))
        self.assertIn("token", str(caught.exception))

        # Even when something in between swallowed the body.
        bare = refusal(401, "Unauthorized", b"", "text/html")
        with self.assertRaises(ApiRejected):
            Api("https://api.example.test", "t", opener=Opener(bare)).check_in(None)

    def test_a_2xx_that_is_not_the_apis_answer_is_no_answer(self):
        # A captive portal or a proxy page must never count as stored punches.
        for body in (b"<html>Sign in to the Wi-Fi</html>", b"[]", b'{"stored": 500}', b""):
            with self.subTest(body=body):
                with self.assertRaises(ApiUnavailable):
                    Api("https://api.example.test", "t", opener=Opener(body)).punches("K40", [])


if __name__ == "__main__":
    unittest.main()
