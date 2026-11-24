"""Browser fixtures for the end-to-end tests, helpers in pylib/esui.py."""

import pytest
from playwright.sync_api import sync_playwright

from esui import UI


@pytest.fixture(scope="session")
def browser():
    pw = sync_playwright().start()
    b = pw.chromium.launch()
    yield b
    b.close()
    pw.stop()


@pytest.fixture
def ui_factory(browser, env):
    created = []

    def make(language="en-US"):
        ui = UI(browser, env, language)
        created.append(ui)
        return ui

    yield make

    problems = [p for ui in created for p in ui.problems]
    for ui in created:
        ui.close()
    assert not problems, "browser problems:\n" + "\n".join(problems)


@pytest.fixture
def ui(ui_factory):
    return ui_factory()
