"""Third audit (test cases 49 and 50): what the audit of 23.11.2026 found.

The working tree after 1.0.2 kept the copy when another client removed or
moved the original during the edit, and reported success: the message
another client had deleted came back, a moved one existed twice. Measured
against Dovecot with a second IMAP client. Here the other client acts
through the testkit right after the APPEND, so the order is always the same.
Both tests fail against that working tree and pass with 1.0.2.

The size limit (case 49) is written with a unit or as something that is no
size at all. The state on the server is read through an independent IMAP
connection, as everywhere.
"""

import pytest

from eschecks import assert_replaced, assert_same_message, done
from esmail import load_fixture

CHANGED = ("The message on the server is no longer the one shown in the dialog: it has been changed, "
           "moved or deleted in the meantime. Nothing was changed.", "error")


# -- test case 50: another client removes or moves the original after the APPEND --

@pytest.mark.expect_errorlog(r"edit_subject: the original UID \d+ in INBOX was changed or removed during the edit")
def test_original_deleted_by_another_client_after_the_append_stays_deleted(env):
    imap = env.imap()
    other = env.seed(b"Subject: Other\r\n\r\ny\r\n")
    uid = env.seed("simple.eml", flags=[r"\Seen"])
    kept = imap.snapshot("INBOX", other)
    uidnext = imap.uidnext("INBOX")
    env.control(expunge_after_append_uids=[uid])

    r = env.rc("rc-sim").edit_subject(uid, "INBOX", "Must not come back")
    result = done(r)

    assert result["status"] == "error" and result["code"] == "errorchanged", result
    assert CHANGED in r.messages()
    assert imap.uids("INBOX") == [other], "the deletion of the other client stands, no copy brings the message back"
    assert imap.uidnext("INBOX") == uidnext + 1, "the copy had been stored and was taken back"
    assert_same_message(kept, imap.snapshot("INBOX", other))


@pytest.mark.expect_errorlog(r"edit_subject: the original UID \d+ in INBOX was changed or removed during the edit")
def test_original_moved_by_another_client_after_the_append_is_not_duplicated(env):
    imap = env.imap()
    imap.create("Archiv")
    uid = env.seed("simple.eml", flags=[r"\Seen", "$label1"])
    before = imap.snapshot("INBOX", uid)
    env.control(move_after_append_uids=[uid], move_to="Archiv")

    r = env.rc("rc-sim").edit_subject(uid, "INBOX", "Would be a duplicate")
    result = done(r)

    assert result["status"] == "error" and result["code"] == "errorchanged", result
    assert CHANGED in r.messages()
    assert imap.uids("INBOX") == [], "no copy stays behind in the old folder"
    moved = imap.uids("Archiv")
    assert len(moved) == 1
    after = imap.snapshot("Archiv", moved[0])
    assert after.raw == before.raw, "the moved message is the untouched original"
    assert after.flags == before.flags


# -- test case 49: the size limit as written in the configuration ---------------------

@pytest.mark.expect_errorlog(r"edit_subject: edit_subject_max_message_size '1\.5M' is no size, using the default of 67108864 bytes")
def test_limit_that_is_no_size_falls_back_to_the_default(env):
    """'1.5M' as an integer would be 1 byte; the plugin uses the default
    of 64 MiB instead and logs why."""
    imap = env.imap()
    uid = env.seed("simple.eml", flags=[r"\Seen"])
    before = imap.snapshot("INBOX", uid)

    result = done(env.rc("rc-badsize").edit_subject(uid, "INBOX", "Edited despite the odd limit"))

    assert result["status"] == "ok", result
    assert_replaced(before, imap.snapshot("INBOX", result["uid"]), "Edited despite the odd limit")
    assert len(load_fixture("simple.eml")) > 1, "the message is larger than 1 byte"
