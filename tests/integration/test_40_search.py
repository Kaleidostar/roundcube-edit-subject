"""Active search on the HTTP level: the saved search result follows the edit.

The browser side (selection, rows) is covered in the e2e tests; here it is
checked that the session's search set holds the new UID, not the old one.
"""

from eschecks import done
from esmail import load_fixture
from esimap import mutf7_encode
from esrc import Roundcube


def subjects_fixture(subject):
    return load_fixture("simple.eml").replace(b"Subject: Quarterly report", b"Subject: " + subject.encode())


def test_search_in_one_folder(env):
    a = env.seed(subjects_fixture("Report Alpha"))
    b = env.seed(subjects_fixture("Report Beta"))
    env.seed(subjects_fixture("Something else"))
    rc = env.rc()

    req, r = rc.search("Report")
    assert sorted(map(str, Roundcube.row_ids(r))) == sorted(map(str, [a, b]))

    data = done(rc.edit_subject(a, "INBOX", "Report Alpha 2", _search=req, _list="1"))
    assert data["status"] == "ok"
    new = data["uid"]
    assert str(data["list_uid"]) == str(new)

    rows = Roundcube.row_ids(rc.list_messages("INBOX", _search=req))
    assert sorted(map(str, rows)) == sorted(map(str, [new, b]))


def test_search_result_no_longer_matching(env):
    a = env.seed(subjects_fixture("Report Alpha"))
    b = env.seed(subjects_fixture("Report Beta"))
    rc = env.rc()
    req, _ = rc.search("Report")

    data = done(rc.edit_subject(a, "INBOX", "Renamed", _search=req))
    assert data["status"] == "ok"

    # the refreshed search no longer finds the message, no orphan entry
    rows = Roundcube.row_ids(rc.list_messages("INBOX", _search=req))
    assert [str(x) for x in rows] == [str(b)]


def test_search_across_folders(env):
    folder = "Archiv 2019"
    a = env.seed(subjects_fixture("Report Alpha"))
    b = env.seed(subjects_fixture("Report Beta"), folder=folder)
    rc = env.rc()
    mbox = mutf7_encode(folder)

    req, r = rc.search("Report", scope="all")
    ids = sorted(map(str, Roundcube.row_ids(r)))
    assert ids == sorted([f"{a}-INBOX", f"{b}-{mbox}"]), ids

    # like the client: list ID with folder, _mbox is the current folder
    data = done(rc.edit_subject(f"{b}-{mbox}", "INBOX", "Report Beta 2", _search=req, _list="1"))
    assert data["status"] == "ok", data
    assert data["mbox"] == mbox
    assert data["list_uid"] == f"{data['uid']}-{mbox}"

    imap = env.imap()
    assert not imap.exists(folder, b)
    assert imap.snapshot(folder, data["uid"]).subjects() == ["Report Beta 2"]
    assert imap.snapshot("INBOX", a).subjects() == ["Report Alpha"]

    rows = sorted(map(str, Roundcube.row_ids(rc.list_messages("INBOX", _search=req))))
    assert rows == sorted([f"{a}-INBOX", f"{data['uid']}-{mbox}"]), rows
