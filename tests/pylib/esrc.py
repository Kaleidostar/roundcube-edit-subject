"""Minimal Roundcube HTTP client for the integration tests.

Logs in like a browser, keeps the session cookie and the request token and
calls Roundcube actions the way rcmail.http_post() does.
"""

import json
import re

import requests


class RoundcubeError(AssertionError):
    pass


ASK_SERVER = object()


def _js_args(exec_code, method):
    """Extract the JSON arguments of this.<method>(...) calls in an exec string."""
    calls = []
    for m in re.finditer(r"this\.%s\((.*)\);\s*$" % re.escape(method), exec_code or "", re.M):
        try:
            calls.append(json.loads("[" + m.group(1) + "]"))
        except ValueError:
            calls.append([m.group(1)])
    return calls


class Response:
    """Parsed AJAX response of a plugin action."""

    def __init__(self, r):
        self.http = r
        self.status_code = r.status_code
        self.text = r.text
        self.json = None
        if "json" in r.headers.get("Content-Type", ""):
            try:
                self.json = r.json()
            except ValueError:
                self.json = None

    @property
    def exec_code(self):
        return (self.json or {}).get("exec", "")

    def callbacks(self, name=None):
        cbs = (self.json or {}).get("callbacks") or []
        return [c for c in cbs if name is None or c[0] == name]

    def callback(self, name):
        cbs = self.callbacks(name)
        if len(cbs) != 1:
            raise RoundcubeError(f"expected exactly one callback {name}, got {cbs!r}; body: {self.text[:500]}")
        return cbs[0][1] if len(cbs[0]) > 1 else None

    def messages(self):
        """[(text, type), ...] of all display_message() calls."""
        return [(a[0], a[1] if len(a) > 1 else None) for a in _js_args(self.exec_code, "display_message")]

    def commands(self, method):
        return _js_args(self.exec_code, method)


class Roundcube:
    def __init__(self, host, user, password="password", language="en-US"):
        self.host = host
        self.base = f"http://{host}/"
        self.user = user
        self.session = requests.Session()
        # Tests use only fixed internal hosts; ambient proxy/netrc credentials
        # must never be picked up or forwarded.
        self.session.trust_env = False
        self.session.headers["Accept-Language"] = language
        self.token = None
        self.testkit = None
        self.page = None
        self.login(user, password)

    def login(self, user, password):
        r = self.session.get(self.base, params={"_task": "login"}, timeout=60)
        r.raise_for_status()
        m = re.search(r'name="_token" value="([^"]+)"', r.text)
        if not m:
            raise RoundcubeError(f"no login token on {self.base}")
        data = {
            "_token": m.group(1),
            "_task": "login",
            "_action": "login",
            "_timezone": "Europe/Berlin",
            "_url": "",
            "_user": user,
            "_pass": password,
        }
        r = self.session.post(self.base + "?_task=login", data=data, timeout=120)
        m = re.search(r'"request_token":"([^"]+)"', r.text)
        if r.status_code != 200 or not m or "_task=login" in r.url:
            raise RoundcubeError(f"login of {user} at {self.base} failed: HTTP {r.status_code} {r.url}")
        self.token = m.group(1)
        self.page = r.text
        self.testkit = r.headers.get("X-Edit-Subject-Testkit")

    def get(self, params, **kw):
        return self.session.get(self.base, params=params, timeout=kw.pop("timeout", 120), **kw)

    def ajax(self, action, data=None, method="POST", token=True, remote=True, task="mail"):
        headers = {"X-Roundcube-Request": self.token} if token else {}
        params = {"_task": task, "_action": action}
        data = dict(data or {})
        if remote:
            data.setdefault("_remote", "1")
        if method == "POST":
            r = self.session.post(self.base, params=params, data=data, headers=headers, timeout=600)
        else:
            params.update(data)
            r = self.session.get(self.base, params=params, headers=headers, timeout=600)
        return Response(r)

    # -- plugin actions ----------------------------------------------------

    def load_subject(self, uid, mbox, **extra):
        return self.ajax("plugin.edit_subject_load", {"_uid": str(uid), "_mbox": mbox, **extra})

    def edit_subject(self, uid, mbox, subject, expected=ASK_SERVER, **extra):
        """Save a new subject the way the dialog does.

        The dialog sends the subject it was opened with (_expected); unless
        one is given here, it is asked from the server first. None leaves
        the parameter out.
        """
        if expected is ASK_SERVER:
            loaded = self.load_subject(uid, mbox).callbacks("plugin.edit_subject_loaded")
            data = loaded[0][1] if loaded and len(loaded[0]) > 1 and isinstance(loaded[0][1], dict) else {}
            expected = data.get("subject") if isinstance(data.get("subject"), str) else ""
        post = {"_uid": str(uid), "_mbox": mbox, "_subject": subject, **extra}
        if expected is not None:
            post["_expected"] = expected
        return self.ajax("plugin.edit_subject", post)

    def list_messages(self, mbox, **extra):
        return self.ajax("list", {"_mbox": mbox, "_page": "1", **extra}, method="GET")

    def search(self, query, mbox="INBOX", scope="base", headers="subject", filter="ALL"):
        """Run a quick search; returns (search_request, response)."""
        params = {"_q": query, "_mbox": mbox, "_scope": scope, "_headers": headers, "_filter": filter}
        r = self.ajax("search", params, method="GET")
        # multi-folder searches may continue in further requests
        for _ in range(20):
            cont = r.commands("continue_search")
            if not cont:
                break
            r = self.ajax("search", {**params, "_continue": cont[0][0]}, method="GET")
        req = ((r.json or {}).get("env") or {}).get("search_request")
        if not req:
            raise RoundcubeError(f"search for {query!r} gave no search_request: {r.text[:300]}")
        return req, r

    @staticmethod
    def row_ids(response):
        """List IDs of all message rows a response adds."""
        return [args[0] for args in response.commands("add_message_row")]
