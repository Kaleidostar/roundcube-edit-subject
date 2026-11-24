"""Hardening (test cases 28-38): what the security audit of 22.11.2026 found.

Every test case here failed against version 1.0.0 - with a lost message, a
changed message that was not meant, an HTTP 500 or a duplicate - and
describes the behaviour that is expected instead. Four parametrised variants
pass on both versions and guard against regressions. As everywhere, the state
on the server is read through an independent IMAP connection.
"""

import threading

import pytest

from eschecks import assert_replaced, assert_same_message, done
from esmail import load_fixture
from esrc import Roundcube


def mail(subject, msgid=None):
    raw = load_fixture("simple.eml").replace(b"Subject: Quarterly report", b"Subject: " + subject.encode())
    if msgid:
        raw = raw.replace(b"<simple-1@example.test>", f"<{msgid}@example.test>".encode())
    return raw


def folder_state(imap, folder):
    return {uid: imap.snapshot(folder, uid) for uid in imap.uids(folder)}


def assert_folder_unchanged(imap, folder, before):
    after = folder_state(imap, folder)
    assert sorted(after) == sorted(before), "messages added or removed"
    for uid, snap in before.items():
        assert_same_message(snap, after[uid])


def loaded(response):
    return response.callback("plugin.edit_subject_loaded")


# -- test case 28: imap_force_caps ------------------------------------------------

def test_force_caps_neighbours_flagged_deleted_survive(env):
    """Roundcube remembers UIDPLUS in the session, the connection has an empty
    capability list after login: from the second edit of a session on a plain
    EXPUNGE was sent and removed every message flagged \\Deleted."""
    imap = env.imap()
    neighbour = env.seed(mail("Neighbour", "n"), flags=[r"\Deleted", r"\Seen"])
    a = env.seed(mail("One", "a"), flags=[r"\Seen"])
    b = env.seed(mail("Two", "b"))
    c = env.seed(mail("Three", "c"), flags=[r"\Deleted", r"\Flagged"])
    kept = imap.snapshot("INBOX", neighbour)
    rc = env.rc("rc-forcecaps")

    for uid, subject in ((a, "One, edited"), (b, "Two, edited")):
        data = done(rc.edit_subject(uid, "INBOX", subject))
        assert data["status"] == "ok", data
        assert_same_message(kept, imap.snapshot("INBOX", neighbour))

    # a message that is flagged \Deleted itself: its copy carries the flag
    # and was expunged together with the original, "Subject changed."
    before = imap.snapshot("INBOX", c)
    data = done(rc.edit_subject(c, "INBOX", "Three, edited"))
    assert data["status"] == "ok", data
    assert_replaced(before, imap.snapshot("INBOX", data["uid"]), "Three, edited")
    assert_same_message(kept, imap.snapshot("INBOX", neighbour))
    assert len(imap.uids("INBOX")) == 4


# -- test case 29: UIDPLUS goes away while a session lives --------------------------

@pytest.mark.expect_errorlog(r"edit_subject: server does not support UIDPLUS")
def test_uidplus_lost_during_the_session(env):
    imap = env.imap()
    env.seed(mail("Neighbour", "n"), flags=[r"\Deleted"])
    a = env.seed(mail("One", "a"))
    b = env.seed(mail("Two", "b"), flags=[r"\Seen"])
    rc = env.rc()

    # from here on Roundcube's session says "UIDPLUS: yes"
    assert done(rc.edit_subject(a, "INBOX", "One, edited"))["status"] == "ok"

    env.control(disable_uidplus=True)
    before = folder_state(imap, "INBOX")
    uidnext = imap.uidnext("INBOX")

    r = rc.edit_subject(b, "INBOX", "Two, edited")
    data = done(r)

    assert "disabled_caps=UIDPLUS" in r.http.headers["X-Edit-Subject-Testkit"], "the switch did not apply"
    assert data["code"] == "errornouidplus", data
    assert_folder_unchanged(imap, "INBOX", before)
    assert imap.uidnext("INBOX") == uidnext


# -- test case 30: keywords named like FETCH items ---------------------------------

def test_keywords_named_like_fetch_items(env):
    """A message with the keywords "RFC822.SIZE" and "5" could not be edited:
    the size was read from the list of flags."""
    imap = env.imap()
    flags = ("RFC822.SIZE", "5", "UID", "999", "FLAGS", "INTERNALDATE", "NIL", r"\Seen")
    uid = env.seed("simple.eml", flags=flags)
    before = imap.snapshot("INBOX", uid)
    assert set(flags) <= before.flags, before.flags

    for subject in ("Keywords like items", "And once more"):
        data = done(env.rc().edit_subject(before.uid, "INBOX", subject))
        assert data["status"] == "ok", data
        after = imap.snapshot("INBOX", data["uid"])
        assert_replaced(before, after, subject)
        assert not imap.exists("INBOX", before.uid)
        before = after


# -- test case 31: the subject the dialog was opened with ---------------------------

def test_expected_subject_binds_the_request_to_the_message(env):
    imap = env.imap()
    uid = env.seed("simple.eml", flags=[r"\Seen"])
    before = folder_state(imap, "INBOX")
    uidnext = imap.uidnext("INBOX")
    rc = env.rc()

    r = rc.edit_subject(uid, "INBOX", "New", expected="Not what the message says")
    assert done(r)["code"] == "errorchanged", done(r)
    assert any("no longer the one shown in the dialog" in text and kind == "error" for text, kind in r.messages())

    # without the parameter, or with something that is no string
    assert done(rc.edit_subject(uid, "INBOX", "New", expected=None))["code"] == "invalidrequest"
    r = rc.ajax("plugin.edit_subject", {"_uid": uid, "_mbox": "INBOX", "_subject": "New", "_expected[]": "Quarterly report"})
    assert done(r)["code"] == "invalidrequest"

    assert_folder_unchanged(imap, "INBOX", before)
    assert imap.uidnext("INBOX") == uidnext

    assert done(rc.edit_subject(uid, "INBOX", "New", expected="Quarterly report"))["status"] == "ok"


def test_error_text_for_a_changed_message_in_german(env):
    uid = env.seed("simple.eml")
    r = env.rc("rc", language="de-DE").edit_subject(uid, "INBOX", "Neu", expected="Etwas anderes")
    assert ("Die Mail auf dem Server ist nicht mehr die, die im Dialog stand: Sie wurde inzwischen geändert, "
            "verschoben oder gelöscht. Es wurde nichts geändert.", "error") in r.messages()


def test_same_uid_in_another_folder_is_not_edited(env):
    """What the browser sent after the folder was switched while the dialog
    was still loading: the UID of one message with the folder of another."""
    imap = env.imap()
    a = env.seed(mail("In the inbox", "a"))
    b = env.seed(mail("In the archive", "b"), folder="Archiv")
    assert a == b, "both folders start with the same UID"
    inbox, archive = imap.snapshot("INBOX", a), imap.snapshot("Archiv", b)

    r = env.rc().edit_subject(a, "Archiv", "Meant for the inbox", expected="In the inbox")

    assert done(r)["code"] == "errorchanged", done(r)
    assert_same_message(inbox, imap.snapshot("INBOX", a))
    assert_same_message(archive, imap.snapshot("Archiv", b))
    assert imap.uids("Archiv") == [b]


# -- test case 32: a cookie named _uid ----------------------------------------------

def test_uid_cookie_cannot_choose_the_message(env):
    """Roundcube reads _uid from cookies too and rewrites the POST data with
    it: the edit went to the message (and folder) named by the cookie."""
    imap = env.imap()
    a = env.seed(mail("Mail A", "a"))
    env.seed(mail("Mail B", "b"))
    c = env.seed(mail("Mail C", "c"), folder="Other")
    before = {f: folder_state(imap, f) for f in ("INBOX", "Other")}
    rc = env.rc()
    domain = next(iter(rc.session.cookies)).domain

    for cookie in (f"{a + 1}-INBOX", f"{c}-Other"):
        rc.session.cookies.set("_uid", cookie, domain=domain, path="/")

        r = rc.edit_subject(a, "INBOX", "Redirected", expected="Mail A")
        assert f"_uid={cookie}" in r.http.request.headers.get("Cookie", ""), "the cookie was not sent"
        assert done(r)["code"] == "invalidrequest", done(r)

        r = rc.load_subject(a, "INBOX")
        assert loaded(r)["code"] == "invalidrequest"

    for folder, state in before.items():
        assert_folder_unchanged(imap, folder, state)


# -- test case 33: requests that are not from the plugin's client --------------------

def test_plain_form_post_with_a_valid_token_changes_nothing(env):
    """Without the AJAX marker the message was changed and the answer was an
    HTTP 404 with an entry in the error log."""
    imap = env.imap()
    uid = env.seed("simple.eml")
    before = folder_state(imap, "INBOX")
    rc = env.rc()

    for action in ("plugin.edit_subject", "plugin.edit_subject_load"):
        r = rc.session.post(rc.base, params={"_task": "mail", "_action": action}, timeout=60, data={
            "_uid": str(uid), "_mbox": "INBOX", "_subject": "Form post", "_expected": "Quarterly report",
            "_token": rc.token,
        })
        assert r.status_code == 400, (action, r.status_code, r.text[:200])
        assert r.headers["Content-Type"].startswith("text/plain")

    assert_folder_unchanged(imap, "INBOX", before)


def test_post_without_token_and_without_data_is_forbidden(env):
    uid = env.seed("simple.eml")
    rc = env.rc()

    # Roundcube lets a POST without data pass; the method is right, so 403 and not 405
    r = rc.session.post(rc.base, timeout=60, params={
        "_task": "mail", "_action": "plugin.edit_subject", "_uid": str(uid), "_mbox": "INBOX",
        "_subject": "In the URL", "_expected": "Quarterly report", "_token": rc.token,
    })
    assert r.status_code == 403
    assert "Allow" not in r.headers
    assert env.imap().snapshot("INBOX", uid).subjects() == ["Quarterly report"]


@pytest.mark.parametrize("suffix", ["\n", "\r\n", " ", "\t"])
def test_uid_followed_by_white_space_is_invalid(env, suffix):
    imap = env.imap()
    uid = env.seed("simple.eml")
    before = folder_state(imap, "INBOX")

    r = env.rc().edit_subject(f"{uid}{suffix}", "INBOX", "Line feed", expected="Quarterly report")

    assert done(r)["code"] == "errorinvaliduid", done(r)
    assert_folder_unchanged(imap, "INBOX", before)


# -- test case 34: header blocks beyond the limits -----------------------------------

@pytest.mark.parametrize("kind", ["150000 short lines", "more than 4 MiB"])
@pytest.mark.expect_errorlog(r"edit_subject: header of UID \d+ in INBOX is too large")
def test_oversized_header_gives_a_clean_error(env, kind):
    """With 150000 short header lines both actions ended with an HTTP 500
    (memory limit); the load action did not check the size at all."""
    filler = b"a:\r\n" * 150000 if kind.startswith("150000") else (b"X-Fill: " + b"a" * 990 + b"\r\n") * 4400
    raw = b"Subject: Too much header\r\n" + filler + b"\r\nBody\r\n"
    assert (len(raw) > 4 * 1024 * 1024) == kind.startswith("more")
    imap = env.imap()
    uid = env.seed(raw)
    uidnext = imap.uidnext("INBOX")
    rc = env.rc()

    r = rc.load_subject(uid, "INBOX")
    assert r.status_code == 200
    assert loaded(r)["code"] == "errorheadertoolarge"

    r = rc.edit_subject(uid, "INBOX", "Shorter", expected="Too much header")
    assert r.status_code == 200
    assert done(r)["code"] == "errorheadertoolarge"
    assert ("The header of this message is too large to be edited. Nothing was changed.", "error") in r.messages()

    assert imap.uids("INBOX") == [uid]
    assert imap.uidnext("INBOX") == uidnext


# -- test case 35: white space in the new subject -------------------------------------

@pytest.mark.parametrize("spaces", [20000, 1100000])
def test_white_space_run_in_the_subject_gives_a_clean_error(env, spaces):
    """More than 1000000 spaces in a row ended with an HTTP 500: the regular
    expression gave up and the result was not checked."""
    imap = env.imap()
    uid = env.seed("simple.eml")
    before = folder_state(imap, "INBOX")

    r = env.rc().edit_subject(uid, "INBOX", "x" + " " * spaces + "y", expected="Quarterly report")

    assert r.status_code == 200
    assert done(r)["code"] == "errortoolong", done(r)
    assert_folder_unchanged(imap, "INBOX", before)


# -- test case 36: two requests for the same message at the same time ----------------

def test_two_requests_at_the_same_time_leave_one_message(env):
    """Both requests reported success and each left its copy (12 of 12 runs)."""
    imap = env.imap()
    clients = [env.rc(), Roundcube("rc", env.user)]

    for run in range(6):
        uid = env.seed(mail(f"Race {run}", f"race{run}"))
        count = imap.count("INBOX")
        barrier = threading.Barrier(2)
        results = [None, None]

        def work(n):
            barrier.wait()
            results[n] = done(clients[n].edit_subject(uid, "INBOX", f"Race {run}, client {n}", expected=f"Race {run}"))

        threads = [threading.Thread(target=work, args=(n,)) for n in (0, 1)]
        for t in threads:
            t.start()
        for t in threads:
            t.join()

        assert sorted(r["status"] for r in results) == ["error", "ok"], results
        loser = next(r for r in results if r["status"] == "error")
        assert loser["code"] in ("errorbusy", "errornotfound", "errorchanged"), loser
        winner = next(r for r in results if r["status"] == "ok")

        assert imap.count("INBOX") == count, "exactly one version of the message"
        assert not imap.exists("INBOX", uid)
        assert imap.snapshot("INBOX", winner["uid"]).subjects() == [winner["subject"]]


# -- test case 37: the answer to APPEND gets lost -------------------------------------

@pytest.mark.expect_errorlog(r"edit_subject: APPEND to INBOX was not confirmed \(no answer\)")
def test_append_without_an_answer_is_reported_as_unknown(env):
    """Reported as "could not be stored, unchanged" although the copy was there."""
    imap = env.imap()
    uid = env.seed("simple.eml", flags=[r"\Seen", "$label1"])
    before = imap.snapshot("INBOX", uid)
    copy_uid = imap.uidnext("INBOX")
    env.control(lose_append_answer=True)

    r = env.rc("rc-sim").edit_subject(uid, "INBOX", "Answer lost")
    data = done(r)

    assert data["code"] == "errorappendunknown", data
    assert any("did not confirm" in text and kind == "error" for text, kind in r.messages())
    assert sorted(imap.uids("INBOX")) == [uid, copy_uid]
    assert_same_message(before, imap.snapshot("INBOX", uid))
    assert imap.snapshot("INBOX", copy_uid).subjects() == ["Answer lost"]


# -- test case 38: several Subject fields ----------------------------------------------

def test_dialog_gets_the_subject_roundcube_shows(env):
    """The dialog was filled with the first Subject field, the list shows the last."""
    imap = env.imap()
    uid = env.seed("two-subjects.eml")
    rc = env.rc("rc-keep")

    rows = rc.list_messages("INBOX").commands("add_message_row")
    shown = [row[1]["subject"] for row in rows if str(row[0]) == str(uid)]
    assert shown == ["Second subject line"], rows

    assert loaded(rc.load_subject(uid, "INBOX"))["subject"] == "Second subject line"

    data = done(rc.edit_subject(uid, "INBOX", "Just one"))
    assert data["status"] == "ok", data
    after = imap.snapshot("INBOX", data["uid"])
    assert after.subjects() == ["Just one"]
    assert [f for f in after.fields if f.lower().startswith(b"x-original-subject:")] == [
        b"X-Original-Subject: Second subject line\r\n"]
    assert b"First subject line" not in after.header
