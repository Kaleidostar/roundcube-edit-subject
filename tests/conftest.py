"""Shared fixtures for the integration and browser tests.

Every test gets its own IMAP account (Dovecot accepts any user name with
the test password), so tests never see each other's messages.
"""

import json
import re
import uuid

import pytest

from esenv import CTL_DIR, LOG_DIR, PASSWORD, TEMP_DIR
from esimap import Imap
from esmail import load_fixture
from esrc import Roundcube


def new_account(request, prefix=""):
    name = re.sub(r"[^a-z0-9]+", "-", request.node.name.lower()).strip("-")[:28]
    return f"{prefix}{name}-{uuid.uuid4().hex[:8]}@example.test"


class Env:
    """Account plus helpers bound to one test."""

    def __init__(self, request):
        self.request = request
        self.user = new_account(request)
        self._imap = {}
        self._rc = {}
        self._folders = {}

    def imap(self, server="dovecot"):
        if server not in self._imap:
            self._imap[server] = Imap(server, 31143, self.user, PASSWORD)
        return self._imap[server]

    def rc(self, host="rc", language="en-US"):
        key = (host, language)
        if key not in self._rc:
            self._rc[key] = Roundcube(host, self.user, PASSWORD, language)
        return self._rc[key]

    def seed(self, raw, folder="INBOX", flags=(), date=None, server="dovecot"):
        """APPEND a message (bytes or fixture name) and return its UID."""
        if isinstance(raw, str):
            raw = load_fixture(raw)
        imap = self.imap(server)
        if folder != "INBOX" and folder not in self._folders.setdefault(server, set()):
            imap.create(folder)
            self._folders[server].add(folder)
        return imap.append(folder, raw, flags=flags, date=date)

    def control(self, **settings):
        """Write the control file read by the edit_subject_testkit plugin."""
        name = re.sub(r"[^a-z0-9@._-]", "_", self.user, flags=re.I)
        (CTL_DIR / f"{name}.json").write_text(json.dumps(settings))

    def observed(self):
        name = re.sub(r"[^a-z0-9@._-]", "_", self.user, flags=re.I)
        f = CTL_DIR / f"{name}.observed.json"
        return json.loads(f.read_text()) if f.exists() else []

    def close(self):
        for c in self._imap.values():
            c.close()


@pytest.fixture
def env(request):
    e = Env(request)
    yield e
    e.close()


# -- checks that apply to every test ------------------------------------------

def errorlog_size():
    f = LOG_DIR / "errors.log"
    return f.stat().st_size if f.exists() else 0


def errorlog_since(offset):
    f = LOG_DIR / "errors.log"
    if not f.exists():
        return ""
    with f.open("rb") as fh:
        fh.seek(offset)
        return fh.read().decode("utf-8", "replace")


def plugin_temp_files():
    return sorted(p.name for p in TEMP_DIR.iterdir() if "edit_subject" in p.name) if TEMP_DIR.exists() else []


@pytest.fixture
def errorlog():
    """Access to the Roundcube error log lines written during the test."""

    class Log:
        def __init__(self):
            self.offset = errorlog_size()

        def new(self):
            return errorlog_since(self.offset)

    return Log()


@pytest.fixture(autouse=True)
def _global_checks(request):
    """No error log entries and no plugin temp files after any test.

    Tests that expect a logged error declare it with
    @pytest.mark.expect_errorlog("regex").
    """
    offset = errorlog_size()
    yield
    leftovers = plugin_temp_files()
    assert not leftovers, f"temp files left behind: {leftovers}"
    new = errorlog_since(offset)
    marker = request.node.get_closest_marker("expect_errorlog")
    if marker is None:
        assert new == "", f"unexpected Roundcube error log entries:\n{new}"
    else:
        patterns = marker.args
        for pattern in patterns:
            assert re.search(pattern, new), f"expected error log entry /{pattern}/, got:\n{new}"
        unrelated = [l for l in new.splitlines() if l.strip()
                     and not any(re.search(pattern, l) for pattern in patterns)
                     and not l.startswith((" ", "\t", "#", "Stack trace", "PHP Stack"))]
        assert not unrelated, "unexpected additional Roundcube error log entries:\n" + "\n".join(unrelated)


def pytest_configure(config):
    config.addinivalue_line("markers", "expect_errorlog(pattern): test expects matching error log entries")
