"""Second audit (test cases 41-43 and 46): what the audit of 22./23.11.2026 found.

Each of the cases 41 to 43 failed against version 1.0.1 - with a lost
message, an HTTP 500, a subject changed into something nobody typed - and
describes the behaviour that is expected instead. Five tests pass on both
versions and guard against regressions: three parametrised variants and the
two for case 46, which fails in the unit tests (the server side of it cannot
be staged with a real server). The cases 44, 45, 47 and 48 are browser and
unit tests. As everywhere, the state on the server is read through an
independent IMAP connection.
"""

import re

import pytest

from eschecks import assert_replaced, assert_same_message, done
from esmail import load_fixture


def mail(subject, msgid=None, ctype=None):
    """simple.eml with another Subject (bytes), optionally another Content-Type"""
    raw = load_fixture("simple.eml").replace(b"Subject: Quarterly report", b"Subject: " + subject)
    assert b"Subject: " + subject in raw
    if msgid:
        raw = raw.replace(b"<simple-1@example.test>", f"<{msgid}@example.test>".encode())
    if ctype is not None:
        raw, n = re.subn(rb"(?m)^Content-Type: [^\r\n]*\r\n", b"Content-Type: " + ctype + b"\r\n" if ctype else b"", raw, count=1)
        assert n == 1
    return raw


def loaded(response):
    return response.callback("plugin.edit_subject_loaded")


def listed_subject(rc, uid, folder="INBOX"):
    """Subject of a message as Roundcube's list shows it."""
    rows = rc.list_messages(folder).commands("add_message_row")
    for args in rows:
        if str(args[0]) == str(uid):
            return re.sub(r"<[^>]+>", "", str(args[1].get("subject", "")))
    raise AssertionError(f"UID {uid} is not in the list of {folder}")


# -- test case 41: the rollback and the expunge of another client ------------------

@pytest.mark.expect_errorlog(r"edit_subject: (could not delete the original|the original UID \d+ in INBOX was removed by someone else)")
def test_expunge_of_another_client_during_the_rollback_loses_nothing(env):
    """UID EXPUNGE of the original has no effect, so the plugin takes the
    edit back. While the original still carries the \\Deleted flag of the
    plugin, another client expunges the folder. With 1.0.1 the plugin then
    removed the copy as well and reported "the message is unchanged"."""
    imap = env.imap()
    neighbour = env.seed(b"Subject: Neighbour\r\n\r\nx\r\n", flags=[r"\Deleted"])
    uid = env.seed("simple.eml", flags=[r"\Seen", "$label1"])
    kept = imap.snapshot("INBOX", neighbour)
    before = imap.snapshot("INBOX", uid)
    env.control(noop_expunge_uids=[uid], vanish_before_unflag_uids=[uid])

    r = env.rc("rc-sim").edit_subject(uid, "INBOX", "Edited after all")
    data = done(r)

    versions = [u for u in imap.uids("INBOX") if u != neighbour]
    assert len(versions) == 1, f"exactly one version must be left, got UIDs {versions}"
    assert versions[0] != uid
    assert_replaced(before, imap.snapshot("INBOX", versions[0]), "Edited after all")
    assert data["status"] == "ok", data
    assert data["uid"] == versions[0]
    assert_same_message(kept, imap.snapshot("INBOX", neighbour))


# -- test case 42: the subject of the stored message is prepared --------------------

@pytest.mark.parametrize("unit, size", [
    (b"=?a?q??=", 600 * 1024),
    (b"=?UTF-8?B?QQ==?= ", 1100 * 1024),
    (b"=?a?B?x", 600 * 1024),
])
def test_very_long_subject_of_the_message_is_no_http_500(env, unit, size):
    """Roundcube cuts a subject after 2048 bytes before it decodes it, the
    plugin handed up to 4 MiB to the decoder: about 145 bytes of memory for
    every byte of the shortest encoded-words (HTTP 500 at the memory limit)
    and, without PCRE JIT, quadratic time."""
    imap = env.imap()
    value = (unit * (size // len(unit))).rstrip()
    uid = env.seed(mail(value, "long"), flags=[r"\Seen"])
    before = imap.snapshot("INBOX", uid)
    rc = env.rc("rc-keep")

    r = rc.load_subject(uid, "INBOX")
    assert r.status_code == 200, r.text[:300]
    data = loaded(r)
    assert data["status"] == "ok", data
    assert len(data["subject"].encode()) <= 8192

    r = rc.edit_subject(uid, "INBOX", "Short again", expected=data["subject"])
    assert r.status_code == 200, r.text[:300]
    result = done(r)
    assert result["status"] == "ok", result

    after = imap.snapshot("INBOX", result["uid"])
    assert_replaced(before, after, "Short again", keep_original=True)
    # complete, apart from the one line break the plugin may add to a first
    # line that the longer field name pushed over 998 characters
    kept = [f for f in after.fields if f.lower().startswith(b"x-original-subject:")]
    assert len(kept) == 1
    assert re.sub(rb"\r\n(?=[ \t])", b"", kept[0]) == b"X-Original-Subject: " + value + b"\r\n"
    assert imap.snapshot("INBOX", uid) is None


# -- test case 43: raw 8-bit subjects ------------------------------------------------

@pytest.mark.parametrize("raw, ctype", [
    ("Grüße aus Köln".encode("utf-8"), b"text/plain; charset=UTF-8"),
    ("Grüße aus Köln".encode("latin-1"), b"text/plain; charset=ISO-8859-1"),
    ("Привет из Москвы".encode("koi8-r"), b"text/plain; charset=koi8-r"),
    ("Grüße aus Köln".encode("utf-8"), b"text/plain"),
])
def test_dialog_shows_the_subject_roundcube_shows(env, raw, ctype):
    """Roundcube reads raw 8-bit header text with the character set of the
    message. The plugin did not: for a message with "charset=UTF-8" the list
    showed "Grüße", the dialog "GrÃ¼ÃŸe" - and saved it that way."""
    imap = env.imap()
    uid = env.seed(mail(raw, "raw", ctype), flags=[r"\Seen"])
    rc = env.rc()

    shown = listed_subject(rc, uid)
    data = loaded(rc.load_subject(uid, "INBOX"))
    assert data["subject"] == shown

    # the user only adds something at the end
    result = done(rc.edit_subject(uid, "INBOX", shown + " (2)", expected=data["subject"]))
    assert result["status"] == "ok", result
    assert imap.snapshot("INBOX", result["uid"]).subjects() == [shown + " (2)"]
    assert listed_subject(rc, result["uid"]) == shown + " (2)"


@pytest.mark.parametrize("subject", ["1+1-2 and A+AOQ-B", "Plain words"])
def test_ascii_subject_is_read_back_as_typed_whatever_the_message_names(env, subject):
    """A message that names UTF-7: Roundcube reads raw header text as UTF-7
    then, "+AOQ-" becomes "ä". Such a subject is written as an encoded-word,
    which says its character set itself."""
    imap = env.imap()
    uid = env.seed(mail(b"Old", "utf7", b"text/plain; charset=UTF-7"), flags=[r"\Seen"])
    rc = env.rc()

    result = done(rc.edit_subject(uid, "INBOX", subject))

    assert result["status"] == "ok", result
    assert imap.snapshot("INBOX", result["uid"]).subjects() == [subject]
    assert listed_subject(rc, result["uid"]) == subject
    assert loaded(rc.load_subject(result["uid"], "INBOX"))["subject"] == subject


# -- test case 46: another plugin talks to the server in the message_saved hook ------

def test_hook_that_talks_to_the_server_does_not_disturb_the_edit(env):
    """After such a hook the answer of the APPEND is no longer the last one
    Roundcube has; the plugin cannot read from it for which folder the UID
    was named. The edit still works: the copy is recognised by its header."""
    imap = env.imap()
    uid = env.seed("simple.eml", flags=[r"\Seen"])
    before = imap.snapshot("INBOX", uid)
    env.control(noop_in_saved_hook=True)

    result = done(env.rc("rc-sim").edit_subject(uid, "INBOX", "Edited with a hook"))

    assert result["status"] == "ok", result
    assert_replaced(before, imap.snapshot("INBOX", result["uid"]), "Edited with a hook")
    assert imap.uids("INBOX") == [result["uid"]]


@pytest.mark.expect_errorlog(r"edit_subject: verification of the copy UID \d+ in INBOX failed")
def test_copy_is_taken_back_after_such_a_hook_only_as_the_copy(env):
    """The copy is stored with one byte too many. Its header block is the
    one that was written, so it is known to be the copy and is removed."""
    imap = env.imap()
    other = env.seed(b"Subject: Other\r\n\r\ny\r\n")
    uid = env.seed("simple.eml", flags=[r"\Seen"])
    state = {u: imap.snapshot("INBOX", u) for u in (other, uid)}
    env.control(noop_in_saved_hook=True, corrupt_append=True)

    r = env.rc("rc-sim").edit_subject(uid, "INBOX", "Does not arrive")

    assert done(r)["code"] == "errorverify"
    assert imap.uids("INBOX") == [other, uid]
    for u, snap in state.items():
        assert_same_message(snap, imap.snapshot("INBOX", u))
