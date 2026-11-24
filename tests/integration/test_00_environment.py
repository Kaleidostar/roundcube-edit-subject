"""The environment itself: versions, servers, scenarios, plugin assets.

These checks bind every run to what is actually running, so a green result
cannot come from a stale image or a scenario that silently did not apply.
"""

import re
from pathlib import Path

import pytest

from esenv import MESSAGES_CACHE, RC_VERSION
from esrc import Roundcube

REPO = Path(__file__).resolve().parents[2]


def parse_testkit(header):
    return dict(part.strip().split("=", 1) for part in (header or "").split(";") if "=" in part)


def test_running_versions_match_the_run(env):
    info = parse_testkit(env.rc("rc").testkit)
    assert info["rc"] == RC_VERSION
    assert info["php"].startswith("8.")
    assert info["messages_cache"] == ("1" if MESSAGES_CACHE else "0")
    # test 14 relies on the image default, it must not be raised anywhere
    assert info["memory_limit"] == "64M"


@pytest.mark.parametrize("host, expected", [
    ("rc", {"storage": "imap", "imap_host": "dovecot:31143", "keep_original": "0", "disabled_caps": "", "force_caps": "0"}),
    ("rc-sim", {"storage": "estest", "imap_host": "dovecot:31143"}),
    ("rc-noacl", {"storage": "imap", "disabled_caps": "ACL"}),
    ("rc-nouidplus", {"imap_host": "dovecot-nouidplus:31143"}),
    ("rc-quota", {"imap_host": "dovecot-quota:31143"}),
    ("rc-keep", {"keep_original": "1"}),
    # imap_debug would hide what this scenario is for, see the configuration
    ("rc-forcecaps", {"force_caps": "1", "imap_debug": "0", "imap_host": "dovecot:31143"}),
])
def test_web_host_selects_scenario(env, host, expected):
    info = parse_testkit(env.rc(host).testkit)
    for key, value in expected.items():
        assert info[key] == value, (host, key, info)


def test_imap_servers_offer_what_the_tests_expect(env):
    main = env.imap("dovecot").capabilities()
    assert {"UIDPLUS", "ACL"} <= main
    assert "UIDPLUS" not in env.imap("dovecot-nouidplus").capabilities()
    assert "QUOTA" in env.imap("dovecot-quota").capabilities()


def test_plugin_assets_are_loaded(env):
    rc = env.rc("rc")
    page = rc.page
    for name, kind in (("edit_subject.js", "script"), ("skins/elastic/edit_subject.css", "link")):
        m = re.search(r'(?:src|href)="([^"]*plugins/edit_subject/' + re.escape(name) + r'[^"]*)"', page)
        assert m, f"{name} is not referenced by the mail page"
        r = rc.session.get(rc.base + m.group(1).lstrip("./"), timeout=30)
        assert r.status_code == 200, (name, m.group(1), r.status_code)
        assert r.content == (REPO / name).read_bytes(), f"{name} served differs from the repository"


def test_menu_entry_is_part_of_the_more_menu(env):
    page = env.rc("rc").page
    menu = re.search(r'<div id="message-menu".*?</ul>', page, re.S)
    assert menu, "message-menu not found"
    assert 'plugin.edit_subject' in menu.group(0)
