"""Browser helpers for the end-to-end tests (Playwright, Chromium).

Every page collects console errors, page errors and failed requests for
files of the plugin; a test fails if any of them occurred (test case 27).
The error log check of the main conftest applies as well.
"""

import base64

from playwright.sync_api import TimeoutError as PlaywrightTimeout

TIMEOUT = 15000


def row_id(uid):
    """DOM id of a message row (rcmail.html_identifier(uid, true))."""
    return "#rcmrow" + base64.urlsafe_b64encode(str(uid).encode()).decode().rstrip("=")


class UI:
    def __init__(self, browser, env, language):
        self.env = env
        self.context = browser.new_context(viewport={"width": 1400, "height": 900}, locale=language,
                                           extra_http_headers={"Accept-Language": language})
        self.context.set_default_timeout(TIMEOUT)
        self.problems = []
        self.page = self.watch(self.context.new_page())

    def watch(self, page):
        page.on("console", lambda m: self.problems.append(f"console {m.type}: {m.text}") if m.type == "error" else None)
        page.on("pageerror", lambda e: self.problems.append(f"page error: {e}"))
        page.on("requestfailed", lambda r: self.problems.append(f"request failed: {r.url}")
                if "plugins/edit_subject" in r.url else None)
        page.on("response", lambda r: self.problems.append(f"HTTP {r.status}: {r.url}")
                if r.status >= 400 and "edit_subject" in r.url else None)
        return page

    def login(self, host="rc"):
        p = self.page
        p.goto(f"http://{host}/?_task=login")
        p.fill("#rcmloginuser", self.env.user)
        p.fill("#rcmloginpwd", "password")
        p.click("#rcmloginsubmit")
        p.wait_for_selector("#messagelist")
        self.wait_idle()
        # Roundcube's session is written by the request that ends last; a
        # search fired while the initial "getunread" request is still running
        # can lose its search set (measured with 1.7.6). Wait like a user would.
        p.wait_for_load_state("networkidle")
        return p

    def wait_idle(self, page=None):
        """Wait until Roundcube has no pending requests."""
        (page or self.page).wait_for_function("window.rcmail && !rcmail.busy")

    def rows(self, page=None):
        return (page or self.page).eval_on_selector_all("#messagelist tbody tr", "els => els.filter(e => e.style.display != 'none').map(e => '#' + e.id)")

    def select(self, uid, page=None):
        """Select one message and wait until the preview shows it."""
        p = page or self.page
        # the sender is no link, a click there only selects the row
        p.click(row_id(uid) + " td.subject span.fromto")
        p.wait_for_function("(e => !!e && e.classList.contains('active'))(document.querySelector('#message-menu a.edit-subject'))")
        self.wait_preview(str(uid).split("-")[0], p)

    def wait_preview(self, uid, page=None):
        (page or self.page).wait_for_function("""uid => {
            var f = document.getElementById('messagecontframe'),
                w = f && f.contentWindow;
            return w && w.rcmail && String(w.rcmail.env.uid) == uid && !w.rcmail.busy
                && !window.rcmail.busy;
        }""", arg=str(uid))

    def selection(self, page=None):
        return [str(x) for x in (page or self.page).evaluate("rcmail.message_list.get_selection()")]

    def menu_state(self, page=None):
        cls = (page or self.page).get_attribute("#message-menu a.edit-subject", "class")
        return "active" if "active" in cls.split() else "disabled"

    # Elastic hides a closed menu with a 200 ms timer (skins/elastic/ui.js,
    # "hide.bs.popover" handler, 1.6.21 and 1.7.6); until then Bootstrap has
    # detached the menu from the DOM. A menu reopened within that time gets
    # its content hidden by the old timer, and button states set meanwhile
    # are lost (Roundcube's set_button() cannot find the detached entries).
    # Humans rarely act that fast, the tests always do: wait for it to pass.
    MENU_SETTLE_MS = 400

    def watch_menu(self, page=None):
        (page or self.page).evaluate("""() => {
            const b = $('#messagemenulink');
            if (!b.data('es-watch')) {
                b.data('es-watch', 1)
                    .on('shown.bs.popover', () => { window.__es_menu = 'shown'; })
                    .on('hide.bs.popover', () => {
                        window.__es_menu = 'hiding';
                        window.__es_hide_at = performance.now();
                    });
            }
        }""")

    def wait_menu_settled(self, page=None):
        (page or self.page).wait_for_function(
            "ms => !window.__es_hide_at || performance.now() - window.__es_hide_at > ms",
            arg=self.MENU_SETTLE_MS)

    def open_menu(self, item="a.edit-subject", page=None):
        """Open the "More" menu and wait until the entry can really be clicked.

        Measured with Elastic in 1.7.6: an entry clicked before Bootstrap's
        show transition has ended ("shown.bs.popover", about 155 ms after
        the click) does not close the menu - it stays open behind the
        dialog. So wait for that event, then check that the popover is on
        top at the entry. If the menu did not open, click "More" again - at
        most three times, every retry is printed.
        """
        p = page or self.page
        self.watch_menu(p)
        self.wait_menu_settled(p)
        ready = """sel => {
            if (window.__es_menu !== 'shown') {
                return false;
            }
            const a = document.querySelector('#message-menu ' + sel),
                pop = a && a.closest('.popover');
            if (!pop || !pop.classList.contains('show')) {
                return false;
            }
            // nothing else (e.g. the preview frame) may cover the entry;
            // disabled entries have pointer-events: none, the hit is their LI
            const r = a.getBoundingClientRect(),
                hit = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
            return !!hit && pop.contains(hit);
        }"""
        for attempt in range(3):
            p.evaluate("() => { window.__es_menu = 'clicked'; }")
            p.click("#messagemenulink")
            try:
                p.wait_for_function(ready, arg=item, timeout=3000)
                return p
            except PlaywrightTimeout:
                print(f"More menu not open after click {attempt + 1}, clicking again")
        raise AssertionError("the More menu did not open")

    def open_dialog(self, page=None):
        p = page or self.page
        self.open_menu("a.edit-subject", p)
        p.click("#message-menu a.edit-subject")
        p.wait_for_selector("#edit-subject-input", state="visible")
        return p

    def subject_text(self, uid, page=None):
        return (page or self.page).inner_text(row_id(uid) + " span.subject a")

    def error_messages(self, page=None):
        return (page or self.page).eval_on_selector_all("#messagestack div.error", "els => els.map(e => e.innerText)")

    def close(self):
        self.context.close()
