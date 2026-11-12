"""The three API calls, over HTTPS with certificate verification, standard library only."""

import http.client
import json
import ssl
import urllib.error
import urllib.request

from . import VERSION


class ApiUnavailable(Exception):
    """No answer: the office internet, Cloudflare or the server. Nothing is lost; the next run tries again."""


class ApiBlocked(ApiUnavailable):
    """A 403 that isn't the API's own answer: something in front of it (Cloudflare, an office proxy) refused the request.
    The token isn't the problem, and the block has to be lifted where it is set. Meanwhile punches wait on the device, as
    in any outage."""


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
                # The agent, like the staff admin, is English only: refusals come back in English whatever the API's default.
                "X-Locale": "en",
                # A plain, honest agent name. If Cloudflare blocks it all the same (Bot Fight Mode can't be skipped by a WAF
                # rule), README.md says what to change there.
                "User-Agent": f"BhabaghureAttendanceAgent/{VERSION}",
            },
        )
        try:
            with self._opener.open(request, timeout=self._timeout) as response:
                answer = json.loads(response.read().decode("utf-8") or "{}")
        except urllib.error.HTTPError as error:
            payload = _json(error)
            # The API refuses in JSON with a code and a message. A 403 without them is Cloudflare's challenge or block
            # page, or a proxy's: told apart so nobody goes rotating a token that was never the problem.
            if error.code == 403 and not _from_the_api(payload):
                raise ApiBlocked(f"HTTP 403 from {path}, and not from the API: something in front of it (Cloudflare or a web "
                                 'proxy) blocked this PC. See README.md, "When something is wrong".') from error
            if error.code in (401, 403, 409, 410, 422):
                raise ApiRejected(error.code, str(payload.get("code") or error.reason), str(payload.get("message") or "")) from error
            raise ApiUnavailable(f"HTTP {error.code} from {path}") from error
        # http.client.HTTPException: a reply cut short or garbled on the way (a proxy, a dropped line). Not an OSError, so
        # without it the run would crash with nothing in agent.log; like any outage, the next run tries again.
        except (urllib.error.URLError, http.client.HTTPException, TimeoutError, OSError, ValueError) as error:
            raise ApiUnavailable(f"{path}: {error}") from error
        # Every answer of these three calls is {"data": {...}}. Anything else came from something in between (a captive
        # portal, a proxy), and taking it for a reply would mark punches acknowledged that the server never stored.
        data = answer.get("data") if isinstance(answer, dict) else None
        if not isinstance(data, dict):
            raise ApiUnavailable(f"{path}: the answer isn't the API's")
        return data


def _json(error: urllib.error.HTTPError) -> dict:
    try:
        payload = json.loads(error.read().decode("utf-8"))
    except Exception:
        return {}
    finally:
        error.close()
    return payload if isinstance(payload, dict) else {}


def _from_the_api(payload: dict) -> bool:
    return "code" in payload or "message" in payload


def _https_opener():
    # The default context verifies the certificate and the host name; on Windows it trusts the system store.
    return urllib.request.build_opener(urllib.request.HTTPSHandler(context=ssl.create_default_context()))
