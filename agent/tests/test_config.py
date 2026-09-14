import os
import tempfile
import unittest
from pathlib import Path
from unittest import mock

from bhabaghure_attendance import config
from bhabaghure_attendance.api import Api, ApiRejected, ApiUnavailable


class ConfigTest(unittest.TestCase):
    def test_settings_round_trip_and_only_https_is_accepted(self):
        with tempfile.TemporaryDirectory() as folder, mock.patch.dict(os.environ, {"BHABAGHURE_AGENT_HOME": folder}):
            config.write(config.Settings("https://api.example.test", "192.0.2.10", 4370, 0))
            settings = config.load()
            self.assertEqual("https://api.example.test", settings.api_url)
            self.assertEqual(Path(folder) / "agent.ini", settings.ini_path)

            with self.assertRaises(config.ConfigError):
                config.write(config.Settings("http://api.example.test", "192.0.2.10"))
            # Plain HTTP only for a local development API.
            config.check_api_url("http://127.0.0.1:8000")


class SetTokenTest(unittest.TestCase):
    def test_a_token_piped_from_powershell_with_a_byte_order_mark_is_accepted(self):
        import io

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


class ApiTest(unittest.TestCase):
    def test_requests_carry_the_token_and_refusals_are_told_apart_from_outages(self):
        import io
        import urllib.error

        seen = {}

        class Opener:
            def __init__(self, outcome):
                self.outcome = outcome

            def open(self, request, timeout):
                seen["url"] = request.full_url
                seen["auth"] = request.get_header("Authorization")
                if isinstance(self.outcome, Exception):
                    raise self.outcome
                return _Response(self.outcome)

        api = Api("https://api.example.test/", "bhatt_" + "0" * 64, opener=Opener(b'{"data": {"stored": 3}}'))
        self.assertEqual({"stored": 3}, api.punches("K40", []))
        self.assertEqual("https://api.example.test/api/v1/attendance-agent/punches", seen["url"])
        self.assertEqual("Bearer bhatt_" + "0" * 64, seen["auth"])

        refused = urllib.error.HTTPError("u", 409, "Conflict", {}, io.BytesIO(b'{"code": "device_changed", "message": "no"}'))
        with self.assertRaises(ApiRejected) as caught:
            Api("https://api.example.test", "t", opener=Opener(refused)).report({})
        self.assertEqual("device_changed", caught.exception.code)

        busy = urllib.error.HTTPError("u", 503, "Unavailable", {}, io.BytesIO(b""))
        with self.assertRaises(ApiUnavailable):
            Api("https://api.example.test", "t", opener=Opener(busy)).check_in(None)
        with self.assertRaises(ApiUnavailable):
            Api("https://api.example.test", "t", opener=Opener(urllib.error.URLError("no route"))).check_in(None)


if __name__ == "__main__":
    unittest.main()
