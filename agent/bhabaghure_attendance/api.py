"""The three API calls, over HTTPS with certificate verification, standard library only."""

import json
import ssl
import urllib.error
import urllib.request

from . import VERSION


class ApiUnavailable(Exception):
    """No answer: the office internet, Cloudflare or the server. Nothing is lost; the next run tries again."""


class ApiRejected(Exception):
    """The API answered with a refusal the agent can't fix by retrying (a revoked token, a replaced device)."""

    def __init__(self, status: int, code: str, message: str):
        super().__init__(f"{status} {code}: {message}")
        self.status = status
        self.code = code


class Api:
    def __init__(self, base_url: str, token: str, timeout: int = 20, opener=None):
        self._base = base_url.rstrip("/") + "/api/v1/attendance-agent"
        self._token = token
        self._timeout = timeout
        self._opener = opener or _https_opener()

    def check_in(self, last_pull: dict | None) -> dict:
        return self._post("check-in", {"agent_version": VERSION, "last_pull": last_pull})

    def report(self, body: dict) -> dict:
        return self._post("device", body)

    def punches(self, serial: str, punches: list[dict]) -> dict:
        return self._post("punches", {"serial": serial, "punches": punches})

    def _post(self, path: str, body: dict) -> dict:
        request = urllib.request.Request(
            f"{self._base}/{path}",
            data=json.dumps(body).encode("utf-8"),
            method="POST",
            headers={
                "Authorization": f"Bearer {self._token}",
                "Content-Type": "application/json",
                "Accept": "application/json",
                # A plain, honest agent name: if Cloudflare challenges it, the fix is a WAF skip rule for this path.
                "User-Agent": f"BhabaghureAttendanceAgent/{VERSION}",
            },
        )
        try:
            with self._opener.open(request, timeout=self._timeout) as response:
                return json.loads(response.read().decode("utf-8") or "{}").get("data", {})
        except urllib.error.HTTPError as error:
            payload = _json(error)
            if error.code in (401, 403, 409, 410, 422):
                raise ApiRejected(error.code, str(payload.get("code") or error.reason), str(payload.get("message") or "")) from error
            raise ApiUnavailable(f"HTTP {error.code} from {path}") from error
        except (urllib.error.URLError, TimeoutError, OSError, ValueError) as error:
            raise ApiUnavailable(f"{path}: {error}") from error


def _json(error: urllib.error.HTTPError) -> dict:
    try:
        return json.loads(error.read().decode("utf-8"))
    except Exception:
        return {}
    finally:
        error.close()


def _https_opener():
    # The default context verifies the certificate and the host name; on Windows it trusts the system store.
    return urllib.request.build_opener(urllib.request.HTTPSHandler(context=ssl.create_default_context()))
