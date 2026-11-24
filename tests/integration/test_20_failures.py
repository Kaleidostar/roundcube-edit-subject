"""Failure paths (test cases 18-20): in every case the original survives.

Real failures come from Dovecot (quota, missing UIDPLUS, ACL rights), the
rest is simulated by the edit_subject_testkit storage driver (host rc-sim).
"""

import pytest

from esenv import rc_major_minor
from eschecks import assert_same_message, done
from esimap import mutf7_encode


def edit(env, uid, subject, folder="INBOX", host="rc", language="en-US"):
    return env.rc(host, language).edit_subject(uid, mutf7_encode(folder), subject)


def folder_state(imap, folder):
    return {uid: imap.snapshot(folder, uid) for uid in imap.uids(folder)}


def assert_folder_unchanged(imap, folder, before):
    after = folder_state(imap, folder)
    assert sorted(after) == sorted(before), "messages added or removed"
    for uid, snap in before.items():
        assert_same_message(snap, after[uid])


def seed_into_restricted(env, folder, fixture, flags):
    """Message with flags in a folder whose ACL would drop them on APPEND.

    The message is stored in an unrestricted folder first, which is then
    renamed; the ACL of the configuration applies by name.
    """
    staging = folder + "-staging"
    uid = env.seed(fixture, folder=staging, flags=flags)
    env.imap().rename(staging, folder)
    env._folders["dovecot"].add(folder)
    snap = env.imap().snapshot(folder, uid)
    assert set(flags) <= snap.flags, snap.flags
    return uid


def filler(size):
    """A message of exactly size bytes (CRLF line endings)."""
    head = b"Message-ID: <filler@example.test>\r\nSubject: filler\r\n\r\n"
    body_len = size - len(head)
    lines, rest = divmod(body_len, 78)
    body = (b"x" * 76 + b"\r\n") * lines
    if rest:
        assert rest >= 2
        body += b"y" * (rest - 2) + b"\r\n"
    raw = head + body
    assert len(raw) == size
    return raw


# -- test case 18: APPEND fails ---------------------------------------------------

@pytest.mark.expect_errorlog(r"edit_subject: APPEND to INBOX failed: .*[Qq]uota")
def test_append_fails_over_quota(env):
    imap = env.imap("dovecot-quota")
    uid = env.seed("simple.eml", flags=[r"\Seen", "$label1"], server="dovecot-quota")
    size = imap.snapshot("INBOX", uid).size
    used, limit = imap.quota()
    assert limit == 256 * 1024

    # fill the mailbox up to 10 bytes below the limit
    env.seed(filler(limit - size - 10), server="dovecot-quota")
    before = folder_state(imap, "INBOX")
    uidnext = imap.uidnext("INBOX")

    r = edit(env, uid, "Over quota", host="rc-quota")
    data = done(r)

    assert data["status"] == "error" and data["code"] == "errorappend", data
    assert ("The mailbox is full, the changed message could not be stored. The original message is unchanged.",
            "error") in r.messages()
    assert_folder_unchanged(imap, "INBOX", before)
    assert imap.uidnext("INBOX") == uidnext, "no message was stored"
    # no temp files left: checked after every test (conftest)


@pytest.mark.expect_errorlog(r"edit_subject: APPEND to INBOX failed")
def test_append_fails_message_in_german(env):
    imap = env.imap("dovecot-quota")
    uid = env.seed("simple.eml", server="dovecot-quota")
    size = imap.snapshot("INBOX", uid).size
    env.seed(filler(256 * 1024 - size - 10), server="dovecot-quota")
    before = folder_state(imap, "INBOX")
    uidnext = imap.uidnext("INBOX")

    r = edit(env, uid, "Postfach voll", host="rc-quota", language="de-DE")

    assert ("Das Postfach ist voll, die geänderte Fassung konnte nicht gespeichert werden. "
            "Die ursprüngliche Mail ist unverändert.", "error") in r.messages()
    assert_folder_unchanged(imap, "INBOX", before)
    assert imap.uidnext("INBOX") == uidnext, "no message was stored"


# -- test case 19: no UIDPLUS -----------------------------------------------------------

@pytest.mark.expect_errorlog(r"edit_subject: server does not support UIDPLUS")
def test_server_without_uidplus(env):
    imap = env.imap("dovecot-nouidplus")
    assert "UIDPLUS" not in imap.capabilities()
    # a neighbour flagged \Deleted: a fallback to CLOSE would expunge it
    env.seed("simple.eml", flags=[r"\Deleted"], server="dovecot-nouidplus")
    uid = env.seed("attachments.eml", flags=[r"\Seen"], server="dovecot-nouidplus")
    before = folder_state(imap, "INBOX")
    uidnext = imap.uidnext("INBOX")

    r = edit(env, uid, "No UIDPLUS", host="rc-nouidplus")
    data = done(r)

    assert data["code"] == "errornouidplus", data
    assert any("UIDPLUS" in text and kind == "error" for text, kind in r.messages())
    assert_folder_unchanged(imap, "INBOX", before)
    assert imap.uidnext("INBOX") == uidnext


# -- test case 20: deleting the original fails --------------------------------------

@pytest.mark.expect_errorlog(r"could not delete the original UID")
def test_refused_delete_rolls_back_the_copy(env):
    imap = env.imap()
    uid = env.seed("simple.eml", flags=[r"\Seen", "$label1"])
    before = folder_state(imap, "INBOX")
    copy_uid = imap.uidnext("INBOX")
    env.control(refuse_delete_uids=[uid])

    data = done(edit(env, uid, "Refused delete", host="rc-sim"))

    assert data["code"] == "errordelete", data
    assert_folder_unchanged(imap, "INBOX", before)
    assert not imap.exists("INBOX", copy_uid), "the copy must have been removed"


@pytest.mark.expect_errorlog(r"could not delete the original UID")
def test_expunge_without_effect_rolls_back_and_restores_flags(env):
    imap = env.imap()
    uid = env.seed("simple.eml", flags=[r"\Seen"])
    before = folder_state(imap, "INBOX")
    env.control(noop_expunge_uids=[uid])

    data = done(edit(env, uid, "No-op expunge", host="rc-sim"))

    assert data["code"] == "errordelete", data
    # in particular: the original is not left flagged \Deleted
    assert_folder_unchanged(imap, "INBOX", before)


@pytest.mark.expect_errorlog(r"could not delete the original UID", r"both versions exist")
def test_original_and_copy_cannot_be_removed(env):
    imap = env.imap()
    uid = env.seed("simple.eml", flags=[r"\Seen", "$label1"])
    before = imap.snapshot("INBOX", uid)
    copy_uid = imap.uidnext("INBOX")
    env.control(noop_expunge_uids=[uid, copy_uid])

    r = edit(env, uid, "Both stay", host="rc-sim")
    data = done(r)

    assert data["code"] == "errorbothexist", data
    assert any("both versions" in text for text, _ in r.messages())
    assert sorted(imap.uids("INBOX")) == [uid, copy_uid]
    assert_same_message(before, imap.snapshot("INBOX", uid))
    copy = imap.snapshot("INBOX", copy_uid)
    assert copy.subjects() == ["Both stay"]
    assert copy.flags == before.flags, "the copy is not left flagged \\Deleted"


@pytest.mark.skipif(rc_major_minor() < (1, 7), reason="the message_delete hook exists since Roundcube 1.7")
@pytest.mark.expect_errorlog(r"could not delete the original UID")
def test_other_plugin_aborts_the_delete_hook(env):
    imap = env.imap()
    uid = env.seed("simple.eml", flags=[r"\Seen"])
    before = folder_state(imap, "INBOX")
    env.control(abort_hook_uids=[uid])

    data = done(edit(env, uid, "Hook aborted"))

    assert data["code"] == "errordelete", data
    assert_folder_unchanged(imap, "INBOX", before)


@pytest.mark.expect_errorlog(r"verification of the copy UID \d+ in INBOX failed: size")
def test_copy_stored_incorrectly_is_removed(env):
    imap = env.imap()
    uid = env.seed("attachments.eml", flags=[r"\Seen"])
    before = folder_state(imap, "INBOX")
    copy_uid = imap.uidnext("INBOX")
    env.control(corrupt_append=True)

    data = done(edit(env, uid, "Corrupted copy", host="rc-sim"))

    assert data["code"] == "errorverify", data
    assert_folder_unchanged(imap, "INBOX", before)
    assert not imap.exists("INBOX", copy_uid)


# -- real Dovecot ACLs ----------------------------------------------------------------

@pytest.mark.parametrize("folder, flags, missing", [
    ("Acl-NoDelete", [r"\Seen"], "te"),
    ("Acl-NoExpunge", [r"\Seen"], "e"),
    ("Acl-NoWrite", [r"\Seen", r"\Flagged", "$label1"], "w"),
])
@pytest.mark.expect_errorlog(r"edit_subject: missing rights")
def test_rights_are_checked_before_anything_is_written(env, folder, flags, missing):
    imap = env.imap()
    uid = seed_into_restricted(env, folder, "simple.eml", flags)
    before = folder_state(imap, folder)
    uidnext = imap.uidnext(folder)

    r = edit(env, uid, "Rights", folder)
    data = done(r)

    assert data["code"] == "errornorights", data
    assert any(f"missing rights: {missing})" in text for text, _ in r.messages()), r.messages()
    assert_folder_unchanged(imap, folder, before)
    assert imap.uidnext(folder) == uidnext


def test_rights_without_write_are_enough_for_seen_only(env):
    imap = env.imap()
    uid = env.seed("simple.eml", folder="Acl-NoWrite", flags=[r"\Seen"])
    before = imap.snapshot("Acl-NoWrite", uid)

    data = done(edit(env, uid, "Only seen", "Acl-NoWrite"))

    assert data["status"] == "ok", data
    assert imap.snapshot("Acl-NoWrite", data["uid"]).flags == before.flags


@pytest.mark.parametrize("folder", ["Acl-NoDelete", "Acl-NoExpunge"])
@pytest.mark.expect_errorlog(r"could not delete the original UID", r"both versions exist")
def test_dovecot_refuses_deletion_without_visible_acl(env, folder):
    """Roundcube does not see the ACL, so the server answers OK and does nothing."""
    imap = env.imap()
    uid = env.seed("simple.eml", folder=folder, flags=[r"\Seen"])
    before = imap.snapshot(folder, uid)
    copy_uid = imap.uidnext(folder)

    r = edit(env, uid, "Server refuses", folder, host="rc-noacl")
    data = done(r)

    assert data["code"] == "errorbothexist", data
    assert sorted(imap.uids(folder)) == [uid, copy_uid]
    assert_same_message(before, imap.snapshot(folder, uid))
    copy = imap.snapshot(folder, copy_uid)
    assert copy.subjects() == ["Server refuses"]
    assert r"\Deleted" not in copy.flags


@pytest.mark.expect_errorlog(r"verification of the copy UID \d+ in Acl-NoWrite failed: flag")
def test_dovecot_drops_flags_without_write_right(env):
    imap = env.imap()
    uid = seed_into_restricted(env, "Acl-NoWrite", "simple.eml", [r"\Seen", r"\Flagged", "$label1"])
    before = folder_state(imap, "Acl-NoWrite")

    data = done(edit(env, uid, "Flags dropped", "Acl-NoWrite", host="rc-noacl"))

    assert data["code"] == "errorverify", data
    assert_folder_unchanged(imap, "Acl-NoWrite", before)
