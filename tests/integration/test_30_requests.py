"""Request validation (test case 21): token, method, UID and folder.

Requests without a valid token are rejected by Roundcube itself (HTTP 403,
"Request security check failed" in the error log) before the plugin runs;
the plugin checks method and token once more on its own.
"""

import pytest

from eschecks import assert_same_message, done


def unchanged(env, uid, before, uidnext):
    imap = env.imap()
    assert_same_message(before, imap.snapshot("INBOX", uid))
    assert imap.uidnext("INBOX") == uidnext, "nothing may be appended"


@pytest.fixture
def message(env):
    uid = env.seed("simple.eml", flags=[r"\Seen"])
    imap = env.imap()
    return uid, imap.snapshot("INBOX", uid), imap.uidnext("INBOX")


@pytest.mark.expect_errorlog(r"Request security check failed")
def test_post_without_token_is_rejected(env, message):
    uid, before, uidnext = message
    rc = env.rc()

    r = rc.ajax("plugin.edit_subject", {"_uid": uid, "_mbox": "INBOX", "_subject": "no token"}, token=False)

    assert r.status_code == 403
    assert not r.callbacks("plugin.edit_subject_done")
    unchanged(env, uid, before, uidnext)


@pytest.mark.expect_errorlog(r"Request security check failed")
def test_post_with_wrong_token_is_rejected(env, message):
    uid, before, uidnext = message
    rc = env.rc()
    rc.token = "0" * len(rc.token)

    r = rc.ajax("plugin.edit_subject", {"_uid": uid, "_mbox": "INBOX", "_subject": "wrong token"})

    assert r.status_code == 403
    unchanged(env, uid, before, uidnext)


def test_get_changes_nothing(env, message):
    uid, before, uidnext = message
    rc = env.rc()

    # as AJAX call with a valid token, and as a plain page request like a
    # followed link (no X-Roundcube-Request header, which Roundcube 1.7
    # alone already treats as AJAX)
    for remote in (True, False):
        r = rc.ajax("plugin.edit_subject", {"_uid": uid, "_mbox": "INBOX", "_subject": "via GET"},
                    method="GET", remote=remote, token=remote)
        if remote:
            assert r.status_code == 200
            data = done(r)
            assert data["status"] == "error" and data["code"] == "invalidrequest"
            assert any(kind == "error" for _, kind in r.messages())
        else:
            assert r.status_code == 405
            assert r.http.headers.get("Allow") == "POST"

    unchanged(env, uid, before, uidnext)


@pytest.mark.parametrize("bad_uid", ["abc", "0", "-1", "1:5", "*", "5,6", "", "4294967296", "1e3"])
def test_invalid_uid_gives_a_clean_error(env, message, bad_uid):
    uid, before, uidnext = message

    r = env.rc().edit_subject(bad_uid, "INBOX", "invalid uid")
    data = done(r)

    assert data["status"] == "error" and data["code"] == "errorinvaliduid", data
    assert ("Invalid message ID. Nothing was changed.", "error") in r.messages()
    unchanged(env, uid, before, uidnext)


def test_unknown_uid_gives_a_clean_error(env, message):
    uid, before, uidnext = message

    r = env.rc().edit_subject(uid + 1000, "INBOX", "unknown uid")
    data = done(r)

    assert data["code"] == "errornotfound", data
    assert any("not found" in text for text, _ in r.messages())
    unchanged(env, uid, before, uidnext)


@pytest.mark.parametrize("folder", ["DoesNotExist", "INBOX/../..", "", "Other Users/someone/INBOX", "shared/INBOX"])
def test_foreign_folder_gives_a_clean_error(env, message, folder):
    uid, before, uidnext = message

    r = env.rc().edit_subject(uid, folder, "foreign folder")
    data = done(r)

    assert data["code"] == "errorinvalidfolder", data
    assert ("The folder does not exist or is not accessible. Nothing was changed.", "error") in r.messages()
    unchanged(env, uid, before, uidnext)


def test_too_long_subject_changes_nothing(env, message):
    uid, before, uidnext = message

    r = env.rc("rc", language="de-DE").edit_subject(uid, "INBOX", "x" * 501)
    data = done(r)

    assert data["code"] == "errortoolong", data
    assert ("Der Betreff ist zu lang (höchstens 500 Zeichen). Es wurde nichts geändert.", "error") in r.messages()
    unchanged(env, uid, before, uidnext)


def test_missing_subject_parameter(env, message):
    uid, before, uidnext = message

    r = env.rc().ajax("plugin.edit_subject", {"_uid": uid, "_mbox": "INBOX"})

    assert done(r)["code"] == "errorinvalidsubject"
    unchanged(env, uid, before, uidnext)


@pytest.mark.expect_errorlog(r"Request security check failed")
def test_load_requires_post_and_token(env, message):
    uid, before, uidnext = message
    rc = env.rc()

    r = rc.ajax("plugin.edit_subject_load", {"_uid": uid, "_mbox": "INBOX"}, method="GET")
    assert r.callback("plugin.edit_subject_loaded")["code"] == "invalidrequest"

    r = rc.ajax("plugin.edit_subject_load", {"_uid": uid, "_mbox": "INBOX"}, token=False)
    assert r.status_code == 403
