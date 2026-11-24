"""Browser tests (test cases 22-27, 39, 40 and 44) with Playwright and Chromium.

Server state is still checked through the independent IMAP connection.
Console errors, failed plugin requests and error log entries make every
test fail (fixtures in conftest.py).
"""

import pytest

from esmail import load_fixture
from esui import row_id
from eschecks import assert_replaced, assert_same_message
from esimap import mutf7_encode

pytestmark = pytest.mark.e2e


def mail(subject, msgid):
    raw = load_fixture("simple.eml")
    raw = raw.replace(b"Subject: Quarterly report", b"Subject: " + subject.encode())
    return raw.replace(b"<simple-1@example.test>", f"<{msgid}@example.test>".encode())


def seed_three(env, folder="INBOX"):
    return [env.seed(mail(s, f"m{i}"), folder=folder, flags=[r"\Seen"])
            for i, s in enumerate(["First mail", "Middle mail", "Last mail"])]


def save(ui, value, page=None):
    p = page or ui.page
    p.fill("#edit-subject-input", value)
    p.press("#edit-subject-input", "Enter")
    p.wait_for_selector("#edit-subject-input", state="detached")


# -- test case 22 --------------------------------------------------------------

def test_menu_entry_only_for_exactly_one_message(env, ui):
    a, b, c = seed_three(env)
    p = ui.login()

    assert ui.menu_state() == "disabled"
    ui.open_menu()
    assert p.is_visible("#message-menu a.edit-subject")
    assert p.inner_text("#message-menu a.edit-subject") == "Edit subject"
    p.keyboard.press("Escape")
    p.wait_for_selector("#message-menu a.edit-subject", state="hidden")
    # A row clicked within 200 ms after closing the menu leaves all entries of
    # this menu disabled, Roundcube's own "View source" as well (measured 7/8
    # runs with 1.6.21, 4/8 with 1.7.6, 0/8 with a 1 s pause). See esui.py.
    ui.wait_menu_settled()

    ui.select(b)
    assert ui.menu_state() == "active"

    p.click(row_id(c) + " td.subject span.fromto", modifiers=["Control"])
    p.wait_for_function("rcmail.message_list.get_selection().length == 2")
    assert ui.menu_state() == "disabled"

    p.click(row_id(a) + " td.subject span.fromto")
    p.wait_for_function("rcmail.message_list.get_selection().length == 1")
    assert ui.menu_state() == "active"


# -- test case 23 --------------------------------------------------------------

def test_edit_from_the_list(env, ui):
    imap = env.imap()
    a, b, c = seed_three(env)
    p = ui.login()
    order = ui.rows()
    position = order.index(row_id(b))

    ui.select(b)

    # Esc cancels, nothing happens
    ui.open_dialog()
    assert p.input_value("#edit-subject-input") == "Middle mail"
    assert p.evaluate("document.activeElement.id") == "edit-subject-input"
    assert p.is_visible("#edit-subject-notice")
    p.keyboard.press("Escape")
    p.wait_for_selector("#edit-subject-input", state="detached")
    assert imap.exists("INBOX", b)

    before = imap.snapshot("INBOX", b)
    new = imap.uidnext("INBOX")

    ui.open_dialog()
    save(ui, "Mittlere Mail – geändert")
    p.wait_for_selector(row_id(new))
    ui.wait_idle()

    # same place, still selected, old row gone, new subject in list and preview
    rows = ui.rows()
    assert rows.index(row_id(new)) == position
    assert row_id(b) not in rows
    assert len(rows) == len(order)
    assert ui.selection() == [str(new)]
    assert ui.subject_text(new) == "Mittlere Mail – geändert"
    p.frame_locator("#messagecontframe").locator("h2.subject").filter(has_text="Mittlere Mail – geändert").wait_for()
    assert f"_uid={new}" in p.frame_locator("#messagecontframe").locator("body").evaluate("() => location.href")

    # no error about the old UID, now or a moment later
    p.wait_for_timeout(1500)
    assert ui.error_messages() == []

    assert_replaced(before, imap.snapshot("INBOX", new), "Mittlere Mail – geändert")


def test_unchanged_subject_closes_without_request(env, ui):
    imap = env.imap()
    a, b, c = seed_three(env)
    p = ui.login()
    ui.select(b)
    ui.wait_idle()
    before = imap.snapshot("INBOX", b)

    requests = []
    p.on("request", lambda r: requests.append(r.url) if "plugin.edit_subject&" in r.url or r.url.endswith("plugin.edit_subject") else None)
    ui.open_dialog()
    p.press("#edit-subject-input", "Enter")
    p.wait_for_selector("#edit-subject-input", state="detached")
    p.wait_for_timeout(500)

    assert requests == []
    assert_same_message(before, imap.snapshot("INBOX", b))


# -- test case 24 --------------------------------------------------------------

def test_edit_from_the_opened_message(env, ui):
    imap = env.imap()
    uid = env.seed("attachments.eml", flags=[r"\Seen"])
    p = ui.login()
    p.goto(f"http://rc/?_task=mail&_action=show&_mbox=INBOX&_uid={uid}")
    p.wait_for_selector("h2.subject")
    ui.wait_idle()
    before = imap.snapshot("INBOX", uid)
    new = imap.uidnext("INBOX")

    ui.open_dialog()
    assert p.input_value("#edit-subject-input") == "Unterlagen für die Sitzung"
    save(ui, "Unterlagen, neu")
    p.wait_for_url(f"**_uid={new}**")
    p.wait_for_selector("h2.subject")
    ui.wait_idle()

    assert "Unterlagen, neu" in p.inner_text("h2.subject")
    assert ui.error_messages() == []
    assert_replaced(before, imap.snapshot("INBOX", new), "Unterlagen, neu")


def test_edit_in_a_separate_window_updates_the_list(env, ui):
    imap = env.imap()
    a, b, c = seed_three(env)
    p = ui.login()
    ui.select(b)
    ui.wait_idle()
    new = imap.uidnext("INBOX")
    before = imap.snapshot("INBOX", b)

    ui.open_menu("a.extwin")
    with ui.context.expect_page() as popup_info:
        p.click("#message-menu a.extwin")
    win = ui.watch(popup_info.value)
    win.wait_for_selector("h2.subject")
    ui.wait_idle(win)
    assert "_extwin=1" in win.url

    ui.open_dialog(win)
    save(ui, "From the other window", page=win)
    win.wait_for_url(f"**_uid={new}**")
    win.wait_for_selector("h2.subject")
    assert "From the other window" in win.inner_text("h2.subject")

    # the list in the main window shows the new message, the old one is gone
    p.wait_for_selector(row_id(new))
    ui.wait_idle()
    assert row_id(b) not in ui.rows()
    assert ui.subject_text(new) == "From the other window"
    assert ui.error_messages() == []
    assert imap.snapshot("INBOX", b) is None
    assert_replaced(before, imap.snapshot("INBOX", new), "From the other window")
    win.close()


# -- test case 25 --------------------------------------------------------------

def search(ui, text, scope=None):
    p = ui.page
    if scope:
        p.evaluate(f"rcmail.set_searchscope('{scope}')")
    p.fill("#mailsearchform", text)
    p.press("#mailsearchform", "Enter")
    p.wait_for_function("rcmail.env.search_request")
    ui.wait_idle()
    p.wait_for_load_state("networkidle")


def test_edit_with_active_search(env, ui):
    imap = env.imap()
    a = env.seed(mail("Report Alpha", "ra"), flags=[r"\Seen"])
    b = env.seed(mail("Report Beta", "rb"), flags=[r"\Seen"])
    env.seed(mail("Something else", "se"), flags=[r"\Seen"])
    p = ui.login()

    search(ui, "Report")
    assert sorted(ui.rows()) == sorted([row_id(a), row_id(b)])

    ui.select(a)
    ui.wait_idle()
    new = imap.uidnext("INBOX")
    ui.open_dialog()
    save(ui, "Report Alpha 2")
    p.wait_for_selector(row_id(new))
    ui.wait_idle()

    assert sorted(ui.rows()) == sorted([row_id(new), row_id(b)])
    assert ui.selection() == [str(new)]
    assert p.evaluate("rcmail.env.search_request") is not None

    # paging through the result is served from the refreshed search set
    p.evaluate("rcmail.list_mailbox(rcmail.env.mailbox, 1)")
    ui.wait_idle()
    p.wait_for_selector(row_id(new))
    assert sorted(ui.rows()) == sorted([row_id(new), row_id(b)])
    assert ui.error_messages() == []


def test_edit_in_search_across_folders(env, ui):
    imap = env.imap()
    folder = "Archiv 2019"
    mbox = mutf7_encode(folder)
    a = env.seed(mail("Report Alpha", "ra"), flags=[r"\Seen"])
    b = env.seed(mail("Report Beta", "rb"), folder=folder, flags=[r"\Seen"])
    p = ui.login()

    search(ui, "Report", scope="all")
    assert sorted(ui.rows()) == sorted([row_id(f"{a}-INBOX"), row_id(f"{b}-{mbox}")])

    ui.select(f"{b}-{mbox}")
    ui.wait_idle()
    before = imap.snapshot(folder, b)
    new = imap.uidnext(folder)
    ui.open_dialog()
    save(ui, "Report Beta (archiviert)")
    p.wait_for_selector(row_id(f"{new}-{mbox}"))
    ui.wait_idle()

    assert sorted(ui.rows()) == sorted([row_id(f"{a}-INBOX"), row_id(f"{new}-{mbox}")])
    assert ui.selection() == [f"{new}-{mbox}"]
    assert ui.error_messages() == []
    # the folder of the message counts, not the folder shown
    assert_replaced(before, imap.snapshot(folder, new), "Report Beta (archiviert)")
    assert imap.snapshot("INBOX", a).subjects() == ["Report Alpha"]


def test_edit_in_thread_view(env, ui):
    imap = env.imap()
    t1, t2, t3 = [env.seed(f"thread-{i}.eml", flags=[r"\Seen"]) for i in (1, 2, 3)]
    p = ui.login()

    p.evaluate("rcmail.set_list_options(null, null, null, 1)")
    p.wait_for_function("rcmail.env.threading")
    ui.wait_idle()
    p.evaluate("rcmail.message_list.expand_all()")
    p.wait_for_selector(row_id(t2), state="visible")

    ui.select(t2)
    ui.wait_idle()
    before = imap.snapshot("INBOX", t2)
    new = imap.uidnext("INBOX")
    ui.open_dialog()
    assert p.input_value("#edit-subject-input") == "Re: Projekt Kickoff"
    save(ui, "Re: Projekt Kickoff (Notizen)")

    p.wait_for_selector(row_id(new), state="visible")
    ui.wait_idle()
    p.wait_for_function(f"rcmail.message_list.get_selection().join() == '{new}'")

    rows = ui.rows()
    assert row_id(t2) not in rows
    assert {row_id(t1), row_id(new), row_id(t3)} <= set(rows)
    # still one thread: the edited message keeps its place below the root
    assert str(p.evaluate(f"rcmail.message_list.rows['{new}'].parent_uid")) == str(t1)
    assert ui.subject_text(new) == "Re: Projekt Kickoff (Notizen)"
    assert ui.error_messages() == []
    assert_replaced(before, imap.snapshot("INBOX", new), "Re: Projekt Kickoff (Notizen)")


# -- test case 26 --------------------------------------------------------------

@pytest.mark.parametrize("language, menu, title, label, notice", [
    ("de-DE", "Betreff bearbeiten", "Betreff bearbeiten", "Betreff",
     "Die Mail wird auf dem Server durch eine geänderte Fassung ersetzt."),
    ("en-US", "Edit subject", "Edit subject", "Subject",
     "The message is replaced on the server by a modified copy."),
])
def test_labels_in_german_and_english(env, ui_factory, language, menu, title, label, notice):
    ui = ui_factory(language)
    uid = env.seed("simple.eml", flags=[r"\Seen"])
    p = ui.login()
    ui.select(uid)
    ui.wait_idle()

    ui.open_menu()
    assert p.inner_text("#message-menu a.edit-subject") == menu
    p.click("#message-menu a.edit-subject")
    p.wait_for_selector("#edit-subject-input", state="visible")

    assert p.inner_text(".ui-dialog .ui-dialog-title") == title
    assert p.inner_text(".edit-subject-dialog label") == label
    assert p.inner_text("#edit-subject-notice") == notice


def test_error_message_in_the_users_language(env, ui_factory):
    ui = ui_factory("de-DE")
    uid = env.seed("simple.eml", flags=[r"\Seen"])
    p = ui.login()
    ui.select(uid)
    ui.wait_idle()

    ui.open_dialog()
    p.fill("#edit-subject-input", "x" * 501)
    p.press("#edit-subject-input", "Enter")

    # checked in the browser already, the dialog stays open
    p.wait_for_selector("#messagestack div.error")
    assert "höchstens 500 Zeichen" in ui.error_messages()[0]
    assert p.is_visible("#edit-subject-input")


# -- test case 39 --------------------------------------------------------------

def hold_load_request(page):
    """Keep the answer to the next load request back until release() is called."""
    held = {}

    def handler(route):
        if "plugin.edit_subject_load" in route.request.url and "route" not in held:
            held["route"] = route
        else:
            route.continue_()

    page.route("**/*", handler)

    def wait():
        for _ in range(100):
            if "route" in held:
                return
            page.wait_for_timeout(50)
        raise AssertionError("no load request was sent")

    def release():
        with page.expect_response(lambda r: "plugin.edit_subject_load" in r.url):
            held["route"].continue_()
        # time for the client to act on the answer
        page.wait_for_timeout(700)

    return wait, release


def test_answer_after_a_folder_switch_opens_no_dialog(env, ui):
    """With 1.0.0 the dialog opened with the subject of the message in the
    folder left behind, and saving changed the message that has the same
    UID in the folder shown now (measured with a delayed answer)."""
    imap = env.imap()
    a = env.seed(mail("Inbox mail", "ia"), flags=[r"\Seen"])
    a2 = env.seed(mail("Second inbox mail", "ib"), flags=[r"\Seen"])
    b = env.seed(mail("Archive mail", "ar"), folder="Archiv", flags=[r"\Seen"])
    assert a == b, "both folders start with the same UID"
    before = {("INBOX", a): imap.snapshot("INBOX", a), ("Archiv", b): imap.snapshot("Archiv", b)}
    p = ui.login()
    wait, release = hold_load_request(p)

    ui.select(a)
    ui.open_menu()
    p.click("#message-menu a.edit-subject")
    wait()

    # the user does not wait: another row (its preview ends Roundcube's busy
    # state), then another folder
    p.click(row_id(a2) + " td.subject span.fromto")
    ui.wait_preview(a2)
    p.click('#mailboxlist a[rel="Archiv"]')
    p.wait_for_function("rcmail.env.mailbox == 'Archiv'")
    p.wait_for_selector(row_id(b))
    ui.wait_idle()

    release()

    assert p.locator("#edit-subject-input").count() == 0, "a dialog for a message of another folder"
    assert ui.error_messages() == []
    for (folder, uid), snap in before.items():
        assert_same_message(snap, imap.snapshot(folder, uid))


def test_dialog_stays_bound_to_its_message(env, ui):
    """The answer arrives after another row was selected: the dialog still
    edits the message it was opened for, whatever is selected by then."""
    imap = env.imap()
    a, b, c = seed_three(env)
    before = imap.snapshot("INBOX", a)
    untouched = imap.snapshot("INBOX", b)
    new = imap.uidnext("INBOX")
    p = ui.login()
    wait, release = hold_load_request(p)

    ui.select(a)
    ui.open_menu()
    p.click("#message-menu a.edit-subject")
    wait()
    p.click(row_id(b) + " td.subject span.fromto")
    ui.wait_preview(b)

    release()
    p.wait_for_selector("#edit-subject-input", state="visible")
    assert p.input_value("#edit-subject-input") == "First mail"
    save(ui, "First mail, edited")
    p.wait_for_selector(row_id(new))
    ui.wait_idle()

    assert_replaced(before, imap.snapshot("INBOX", new), "First mail, edited")
    assert_same_message(untouched, imap.snapshot("INBOX", b))
    assert row_id(a) not in ui.rows()
    assert ui.subject_text(new) == "First mail, edited"
    assert ui.selection() == [str(b)], "the selection is the user's"
    assert ui.error_messages() == []


# -- test case 40 --------------------------------------------------------------

def test_forged_channel_messages_are_ignored(env, ui):
    """Every page of the same origin can write to the channel that tells
    other windows about a replaced message. With 1.0.0 names like "__proto__"
    counted as rows: the list was reloaded, the selection became
    ['__proto__'], an object as folder name ended in a script error."""
    a, b, c = seed_three(env)
    p = ui.login()
    ui.select(b)
    ui.wait_idle()
    user = p.evaluate("rcmail.env.user_id")
    assert isinstance(user, str) and user

    lists = []
    p.on("request", lambda r: lists.append(r.url) if "_action=list" in r.url else None)

    def post(message):
        p.evaluate("""m => { const c = new BroadcastChannel('roundcube.edit_subject'); c.postMessage(m); c.close(); }""", message)
        p.wait_for_timeout(300)
        ui.wait_idle()

    for forged in (
        {"user": user, "old_uid": "__proto__", "uid": "__proto__", "mbox": "INBOX"},
        {"user": user, "old_uid": "constructor", "uid": a, "mbox": "INBOX"},
        {"user": user, "old_uid": b, "uid": "__proto__", "mbox": "INBOX"},
        {"user": user, "old_uid": b, "uid": a, "mbox": {"toString": "INBOX"}},
        {"user": [user], "old_uid": b, "uid": a, "mbox": "INBOX"},
        {"user": user, "old_uid": [b], "uid": a, "mbox": "INBOX"},
        {"user": "someone-else", "old_uid": b, "uid": a, "mbox": "INBOX"},
        {"user": user, "old_uid": b, "uid": a, "mbox": "Other"},
        "text", 5, None,
    ):
        post(forged)

    assert lists == [], "no forged message may reload the list"
    assert ui.selection() == [str(b)]

    # the check can fail: a well-formed message about a row of this list is acted on
    post({"user": user, "old_uid": b, "uid": a, "mbox": "INBOX"})
    p.wait_for_function(f"rcmail.message_list.get_selection().join() == '{a}'")
    assert len(lists) == 1


def test_length_check_does_not_block_the_page(env, ui):
    """The clean-up before counting took quadratic time for white space in
    the middle: 4 s for 80000 spaces, and the dialog is filled with whatever
    subject a sender chose."""
    env.seed("simple.eml", flags=[r"\Seen"])
    p = ui.login()

    ms, length = p.evaluate("""() => {
        const s = 'a' + ' '.repeat(80000) + 'b', t = performance.now(), n = rcmail.edit_subject_length(s);
        return [performance.now() - t, n];
    }""")

    assert length == 80002
    assert ms < 300, f"{ms:.0f} ms for 80002 characters"
    # same result as before for ordinary input
    assert p.evaluate(r"rcmail.edit_subject_length('    Grüße 😀\t\n')") == 7
    assert p.evaluate("rcmail.edit_subject_length('')") == 0


# -- test case 44 --------------------------------------------------------------

def hold_save_request(page):
    """Keep the next save request back until release() is called."""
    held = {}

    def is_save(request):
        return (request.method == "POST" and "_action=plugin.edit_subject" in request.url
                and "plugin.edit_subject_load" not in request.url)

    def handler(route):
        if is_save(route.request) and "route" not in held:
            held["route"] = route
        else:
            route.continue_()

    page.route("**/*", handler)

    def wait():
        for _ in range(100):
            if "route" in held:
                return
            page.wait_for_timeout(50)
        raise AssertionError("no save request was sent")

    def release():
        with page.expect_response(lambda r: is_save(r.request)):
            held["route"].continue_()
        # time for the client to act on the answer - and for the list reload
        # and the preview that must not follow
        page.wait_for_timeout(2500)

    return wait, release


@pytest.mark.parametrize("old_uid_in_the_other_folder", [True, False])
def test_answer_to_saving_after_a_folder_switch_leaves_the_other_list_alone(env, ui, old_uid_in_the_other_folder):
    """The save request takes a while and the user moves on to another
    folder. With 1.0.1 the answer was applied to the list shown by then: the
    row of the message with the old UID number vanished from it, or - if
    there was none - the message with the new UID number was selected,
    previewed and thereby marked as read (measured with a held request)."""
    imap = env.imap()
    a = env.seed(mail("Inbox one", "i1"), flags=[r"\Seen"])
    a2 = env.seed(mail("Inbox two", "i2"), flags=[r"\Seen"])
    new = imap.uidnext("INBOX")
    others = [env.seed(mail(f"Archive {i}", f"ar{i}"), folder="Archiv") for i in (1, 2, 3)]  # unread
    assert others[0] == a and others[2] == new, "the folders share their UID numbers"
    if not old_uid_in_the_other_folder:
        imap.store("Archiv", others[0], "+FLAGS", [r"\Deleted"])
        imap.conn.expunge()
        others = others[1:]
    before = {u: imap.snapshot("Archiv", u) for u in others}
    p = ui.login()
    wait, release = hold_save_request(p)

    ui.select(a)
    ui.open_dialog()
    p.fill("#edit-subject-input", "Inbox one, edited")
    p.press("#edit-subject-input", "Enter")
    wait()

    # the user does not wait: another row (its preview ends Roundcube's busy
    # state), then another folder
    p.click(row_id(a2) + " td.subject span.fromto")
    ui.wait_preview(a2)
    p.click('#mailboxlist a[rel="Archiv"]')
    p.wait_for_function("rcmail.env.mailbox == 'Archiv'")
    p.wait_for_selector(row_id(others[-1]))
    ui.wait_idle()
    rows = ui.rows()

    release()
    ui.wait_idle()

    assert ui.rows() == rows, "rows of the other folder"
    assert ui.selection() == []
    for uid, snap in before.items():
        assert_same_message(snap, imap.snapshot("Archiv", uid))  # in particular still unread
    assert imap.snapshot("INBOX", a) is None
    assert imap.snapshot("INBOX", new).subjects() == ["Inbox one, edited"]


def test_answer_to_saving_after_a_new_search_does_not_select_in_its_context(env, ui):
    """A late answer must not select the edited message in a new search."""
    a = env.seed(mail("Report old", "old"), flags=[r"\Seen"])
    other = env.seed(mail("Report other", "report"), flags=[r"\Seen"])
    imap = env.imap()
    new = imap.uidnext("INBOX")
    before_other = imap.snapshot("INBOX", other)
    p = ui.login()
    wait, release = hold_save_request(p)

    ui.select(a)
    ui.open_dialog()
    p.fill("#edit-subject-input", "Report edited")
    p.press("#edit-subject-input", "Enter")
    wait()

    # Another preview ends Roundcube's busy state. In the new search the old
    # row is selected deliberately: an unbound answer would move that
    # selection to the new UID, a bound answer must not do that.
    p.click(row_id(other) + " td.subject span.fromto")
    ui.wait_preview(other)
    p.fill("#mailsearchform", "Report")
    p.press("#mailsearchform", "Enter")
    p.wait_for_function("rcmail.env.search_request")
    ui.wait_idle()
    p.wait_for_selector(row_id(other))
    p.wait_for_selector(row_id(a))
    p.click(row_id(a) + " td.subject span.fromto")
    ui.wait_preview(a)
    assert ui.selection() == [str(a)]

    release()
    ui.wait_idle()

    assert ui.selection() == [], "the late answer must not select in the new search"
    assert_same_message(before_other, imap.snapshot("INBOX", other))
    assert imap.snapshot("INBOX", a) is None
    assert imap.snapshot("INBOX", new).subjects() == ["Report edited"]


def test_pending_selection_is_not_used_in_a_new_search(env, ui):
    a = env.seed(mail("Report old", "old"), flags=[r"\Seen"])
    env.seed(mail("Report other", "other"), flags=[r"\Seen"])
    p = ui.login()
    search(ui, "Report")

    p.evaluate(f"rcmail.edit_subject_remember('{a}')")
    search(ui, "Report old")
    assert ui.rows() == [row_id(a)]
    assert ui.selection() == []

    p.evaluate("rcmail.edit_subject_select_pending()")

    assert ui.selection() == [], "a pending selection from another search must be discarded"
