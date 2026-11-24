"""Server procedure against Dovecot (test cases 9-17 and related checks).

Every edit goes through Roundcube's HTTP interface like the browser does;
the result is checked through an independent IMAP connection.
"""

import hashlib
import random
import subprocess
from datetime import datetime, timedelta, timezone

import pytest

from esmail import load_fixture
from eschecks import assert_replaced, done
from esimap import mutf7_encode

FLAGS_ALL = (r"\Seen", r"\Flagged", r"\Answered", "$Forwarded", "$label1", "$MDNSent", "NonJunk")
DATE_2019 = datetime(2019, 3, 5, 14, 22, 1, tzinfo=timezone(timedelta(hours=1)))


def edit(env, uid, subject, folder="INBOX", host="rc"):
    return done(env.rc(host).edit_subject(uid, mutf7_encode(folder), subject))


def edited(env, folder, before, subject, host="rc", server="dovecot", keep_original=False):
    """Edit, check the result data and the server state, return the copy."""
    imap = env.imap(server)
    count = imap.count(folder)

    result = edit(env, before.uid, subject, folder, host)

    assert result["status"] == "ok", result
    assert result["old_uid"] == before.uid
    assert result["uid"] != before.uid
    assert not imap.exists(folder, before.uid), "old UID must be gone"
    assert imap.count(folder) == count, "number of messages unchanged"

    after = imap.snapshot(folder, result["uid"])
    assert_replaced(before, after, subject, keep_original)
    return after


# -- test case 9 --------------------------------------------------------------

def test_attachments_body_and_other_headers_byte_exact(env):
    uid = env.seed("attachments.eml", flags=[r"\Seen"], date=DATE_2019)
    before = env.imap().snapshot("INBOX", uid)

    edited(env, "INBOX", before, "Unterlagen für die nächste Sitzung")


# -- test case 10 -------------------------------------------------------------

def _smime_verifies(raw, tmp_path, name):
    f = tmp_path / name
    f.write_bytes(raw)
    p = subprocess.run(["openssl", "smime", "-verify", "-noverify", "-in", str(f), "-out", "/dev/null"],
                       capture_output=True, text=True)
    return p.returncode == 0, p.stderr


def test_signed_message_body_byte_exact_and_signature_still_valid(env, tmp_path):
    uid = env.seed("signed.eml", date=DATE_2019)
    before = env.imap().snapshot("INBOX", uid)
    ok, err = _smime_verifies(before.raw, tmp_path, "before.eml")
    assert ok, f"fixture signature invalid: {err}"

    after = edited(env, "INBOX", before, "Signierte Nachricht (bearbeitet)")

    ok, err = _smime_verifies(after.raw, tmp_path, "after.eml")
    assert ok, f"signature broken by the edit: {err}"


# -- test case 11 -------------------------------------------------------------

def test_flags_and_keywords_are_kept(env):
    uid = env.seed("simple.eml", flags=FLAGS_ALL, date=DATE_2019)
    before = env.imap().snapshot("INBOX", uid)
    assert set(FLAGS_ALL) <= before.flags

    edited(env, "INBOX", before, "Keywords stay")


def test_unread_message_stays_unread(env):
    uid = env.seed("simple.eml", flags=[r"\Flagged"], date=DATE_2019)
    before = env.imap().snapshot("INBOX", uid)
    assert r"\Seen" not in before.flags

    after = edited(env, "INBOX", before, "Still unread")
    assert r"\Seen" not in after.flags


def test_no_flags_at_all(env):
    uid = env.seed("simple.eml", date=DATE_2019)
    before = env.imap().snapshot("INBOX", uid)
    assert before.flags == set()

    edited(env, "INBOX", before, "No flags")


# -- test case 12 -------------------------------------------------------------

def test_internaldate_from_2019_is_kept(env):
    uid = env.seed("simple.eml", flags=[r"\Seen"], date=DATE_2019)
    before = env.imap().snapshot("INBOX", uid)
    assert before.internaldate.startswith("05-Mar-2019")

    after = edited(env, "INBOX", before, "Old message")
    assert after.internaldate == before.internaldate
    print(f"INTERNALDATE before {before.internaldate!r}, after {after.internaldate!r}")


# -- test case 13 -------------------------------------------------------------

def test_neighbours_flagged_deleted_survive(env):
    imap = env.imap()
    n1 = env.seed("simple.eml", flags=[r"\Deleted", r"\Seen"])
    uid = env.seed("attachments.eml", flags=[r"\Seen"])
    n2 = env.seed("two-subjects.eml", flags=[r"\Deleted"])
    neighbours = {n: imap.snapshot("INBOX", n) for n in (n1, n2)}
    before = imap.snapshot("INBOX", uid)
    assert imap.count("INBOX") == 3

    after = edited(env, "INBOX", before, "Neighbours")

    for n, snap in neighbours.items():
        now = imap.snapshot("INBOX", n)
        assert now is not None, f"neighbour {n} was expunged"
        assert now.raw == snap.raw and now.flags == snap.flags
    assert sorted(imap.uids("INBOX")) == sorted([n1, n2, after.uid])


# -- test case 14 -------------------------------------------------------------

def big_message(size=25 * 1024 * 1024):
    """A multipart message of the given size with a random attachment."""
    rnd = random.Random(14)
    head = (
        "Message-ID: <big-1@example.test>\r\n"
        "Date: Mon, 13 May 2019 10:00:00 +0200\r\n"
        "From: Big Sender <big@example.test>\r\n"
        "To: Bob Example <bob@example.test>\r\n"
        "Subject: Large attachment\r\n"
        "MIME-Version: 1.0\r\n"
        'Content-Type: multipart/mixed; boundary="big-boundary"\r\n'
        "\r\n"
        "--big-boundary\r\n"
        "Content-Type: text/plain; charset=UTF-8\r\n"
        "\r\n"
        "See attachment.\r\n"
        "--big-boundary\r\n"
        'Content-Type: application/octet-stream; name="big.bin"\r\n'
        "Content-Transfer-Encoding: base64\r\n"
        'Content-Disposition: attachment; filename="big.bin"\r\n'
        "\r\n"
    ).encode()
    tail = b"--big-boundary--\r\n"
    lines = []
    total = len(head) + len(tail)
    alphabet = b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/"
    line_len = 78
    while total + line_len <= size:
        lines.append(bytes(rnd.choice(alphabet) for _ in range(76)) + b"\r\n")
        total += line_len
    return head + b"".join(lines) + tail


def test_25_mb_message_within_the_memory_limit(env):
    raw = big_message()
    assert len(raw) > 24 * 1024 * 1024
    uid = env.seed(raw, flags=[r"\Seen", "$label1"])
    before = env.imap().snapshot("INBOX", uid)
    assert hashlib.sha256(before.raw).hexdigest() == hashlib.sha256(raw).hexdigest()

    edited(env, "INBOX", before, "Große Anlage")

    # streaming, not loading: the request's memory peak stays below the
    # size of the message (recorded by the testkit plugin at shutdown)
    peaks = [o["memory_peak"] for o in env.observed() if "memory_peak" in o]
    assert peaks, "memory peak not recorded"
    print(f"memory peak {peaks[-1]} bytes, message {len(raw)} bytes")
    assert peaks[-1] < len(raw), f"memory peak {peaks[-1]} >= message size {len(raw)}"
    assert all(o.get("memory_limit") == "64M" for o in env.observed() if "memory_limit" in o)


@pytest.mark.expect_errorlog(r"exceeds the message size limit")
def test_message_over_the_configured_size_limit_is_not_fetched(env):
    imap = env.imap()
    uid = env.seed(big_message(2048), flags=[r"\Seen"])
    before = imap.snapshot("INBOX", uid)
    uidnext = imap.uidnext("INBOX")

    r = env.rc("rc-small").load_subject(uid, "INBOX")
    loaded = r.callback("plugin.edit_subject_loaded")
    assert loaded["status"] == "error" and loaded["code"] == "errormessagetoolarge", loaded
    assert ("The message exceeds the configured size limit of 1024 bytes. Nothing was changed.", "error") in r.messages()

    r = env.rc("rc-small").edit_subject(uid, "INBOX", "Too large")
    result = done(r)

    assert result["status"] == "error" and result["code"] == "errormessagetoolarge", result
    assert ("The message exceeds the configured size limit of 1024 bytes. Nothing was changed.", "error") in r.messages()
    assert imap.snapshot("INBOX", uid).raw == before.raw
    assert imap.uidnext("INBOX") == uidnext, "no copy was stored"


# -- test case 15 -------------------------------------------------------------

def test_folder_with_umlaut_and_subfolder(env):
    folder = "Prüfung/Unterordner äöü"
    env.imap().create("Prüfung")
    uid = env.seed("simple.eml", folder=folder, flags=[r"\Seen"])
    before = env.imap().snapshot(folder, uid)

    edited(env, folder, before, "Im Unterordner")


# -- test case 16 -------------------------------------------------------------

def test_same_message_twice(env):
    uid = env.seed("simple.eml", flags=[r"\Seen", "$label1"], date=DATE_2019)
    first = edited(env, "INBOX", env.imap().snapshot("INBOX", uid), "First edit")
    second = edited(env, "INBOX", first, "Second edit")
    assert second.uid > first.uid


# -- test case 17 -------------------------------------------------------------

def test_unchanged_subject_keeps_the_uid(env):
    imap = env.imap()
    uid = env.seed("simple.eml", flags=[r"\Seen"])
    before = imap.snapshot("INBOX", uid)
    uidnext = imap.uidnext("INBOX")

    for same in ("Quarterly report", "  Quarterly report  "):
        result = edit(env, uid, same)
        assert result["status"] == "unchanged"
        assert result["uid"] == uid

    after = imap.snapshot("INBOX", uid)
    assert after.raw == before.raw and after.flags == before.flags
    assert imap.uidnext("INBOX") == uidnext, "nothing was appended"


# -- further checks -------------------------------------------------------------

def test_folded_encoded_original_subject(env):
    uid = env.seed("subject-folded-encoded.eml")
    before = env.imap().snapshot("INBOX", uid)
    assert before.subjects() == ["Grüße aus Köln und eine sehr lange Betreffzeile, die der Absender gefaltet hat (mit älterem Zeichensatz)"]

    after = edited(env, "INBOX", before, "Kurz")
    assert b"gefaltet" not in after.header


def test_message_without_subject(env):
    uid = env.seed("no-subject.eml")
    before = env.imap().snapshot("INBOX", uid)
    assert before.subjects() == []

    edited(env, "INBOX", before, "Added later")


def test_message_with_two_subjects(env):
    uid = env.seed("two-subjects.eml")
    before = env.imap().snapshot("INBOX", uid)
    assert len(before.subjects()) == 2

    edited(env, "INBOX", before, "Just one")


@pytest.mark.parametrize("subject", [
    "",
    "Grüße 😀 " * 22 + "Ende",
    "x" * 500,
    "<b>not html</b> & 'quotes' \"double\"",
    "Look: =?UTF-8?B?SGFsbG8=?= stays literal",
])
def test_various_subjects(env, subject):
    uid = env.seed("simple.eml", flags=[r"\Seen"])
    before = env.imap().snapshot("INBOX", uid)

    edited(env, "INBOX", before, subject)


def test_header_injection_over_http(env):
    uid = env.seed("simple.eml")
    before = env.imap().snapshot("INBOX", uid)

    result = edit(env, uid, "Hello\r\nBcc: x@example.test")
    assert result["status"] == "ok"
    after = env.imap().snapshot("INBOX", result["uid"])
    assert not any(f.lower().startswith(b"bcc:") for f in after.fields)
    assert len(after.fields) == len(before.fields)
    assert after.subjects() == ["Hello  Bcc: x@example.test"]


def test_keep_original_subject(env):
    uid = env.seed("subject-folded-encoded.eml")
    before = env.imap().snapshot("INBOX", uid)
    original = [f for f in before.fields if f.lower().startswith(b"subject:")][0]

    first = edited(env, "INBOX", before, "Erste Änderung", host="rc-keep", keep_original=True)
    second = edited(env, "INBOX", first, "Zweite Änderung", host="rc-keep", keep_original=True)

    for snap in (first, second):
        x = [f for f in snap.fields if f.lower().startswith(b"x-original-subject:")]
        assert x == [b"X-Original-Subject:" + original[len(b"Subject:"):]]


def test_keep_original_is_off_by_default(env):
    uid = env.seed("simple.eml")
    after = edited(env, "INBOX", env.imap().snapshot("INBOX", uid), "No original kept")
    assert not any(f.lower().startswith(b"x-original-subject:") for f in after.fields)


def test_temp_file_is_private_and_in_the_temp_dir(env):
    uid = env.seed("simple.eml", flags=[r"\Seen"])
    before = env.imap().snapshot("INBOX", uid)

    edited(env, "INBOX", before, "Temp file check", host="rc-sim")

    appends = [o for o in env.observed() if "perms" in o]
    assert len(appends) == 1
    assert appends[0]["dir"] == "/var/roundcube/temp"
    assert appends[0]["perms"] == "0600"
    assert appends[0]["basename"].startswith("RCMTEMPedit_subject")
    # header and body come from the same file, no header string is passed
    assert appends[0]["headers_param"] is False


@pytest.mark.parametrize("language, text", [
    # Roundcube stores the language of the first login in the account,
    # so every language needs its own account (one per test here)
    ("de-DE", "Betreff geändert."),
    ("en-US", "Subject changed."),
])
def test_success_message_in_the_users_language(env, language, text):
    uid = env.seed("simple.eml")
    r = env.rc("rc", language=language).edit_subject(uid, "INBOX", "Language check")
    assert (text, "confirmation") in r.messages()


def test_load_returns_the_decoded_subject(env):
    uid = env.seed("subject-folded-encoded.eml")
    data = env.rc().load_subject(uid, "INBOX").callback("plugin.edit_subject_loaded")
    assert data["status"] == "ok"
    assert data["subject"] == "Grüße aus Köln und eine sehr lange Betreffzeile, die der Absender gefaltet hat (mit älterem Zeichensatz)"

    uid = env.seed("no-subject.eml")
    assert env.rc().load_subject(uid, "INBOX").callback("plugin.edit_subject_loaded")["subject"] == ""
