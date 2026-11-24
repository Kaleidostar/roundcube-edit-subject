"""Comparisons of the server state before and after an edit.

Control principle of the specification: the raw message is fetched through
an own IMAP connection before and after every edit and compared - SHA-256
of the body, header lines one by one, flags, INTERNALDATE, number of
messages in the folder.
"""

from esimap import internaldate_epoch


def assert_same_message(before, after):
    """Nothing about the message changed."""
    assert after is not None, "message is gone"
    assert after.uid == before.uid
    assert after.raw == before.raw
    assert after.flags == before.flags
    assert after.internaldate == before.internaldate


def assert_replaced(before, after, subject, keep_original=False):
    """after is the edited copy of before with the given subject."""
    assert after is not None, "edited copy not found"
    assert after.uid != before.uid, "the copy must have a new UID"

    # body byte-exact
    assert after.body_sha256 == before.body_sha256
    assert after.text == before.text

    # all other header lines byte-exact and in the same order
    others = ("subject", "x-original-subject")
    assert after.fields_without(*others) == before.fields_without(*others)

    # exactly one Subject field with the new subject
    assert after.subjects() == [subject]

    # flags, keywords and INTERNALDATE
    assert after.flags == before.flags
    assert after.internaldate == before.internaldate
    assert internaldate_epoch(after.internaldate) == internaldate_epoch(before.internaldate)

    # size as computed in step 8
    assert after.size == before.size - len(before.header) + len(after.header)

    if keep_original:
        assert any(f.lower().startswith(b"x-original-subject:") for f in after.fields)


def done(response):
    """Result data of the save action (callback plugin.edit_subject_done)."""
    return response.callback("plugin.edit_subject_done")
