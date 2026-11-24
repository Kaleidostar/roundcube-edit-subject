"""Constants of the test run (versions, paths), shared by all test modules.

Test modules import from here, never from conftest: pytest registers every
conftest.py under the same module name, so "from conftest import ..." may
pick up tests/e2e/conftest.py instead of tests/conftest.py.
"""

import os
from pathlib import Path

LOG_DIR = Path("/rc/logs")
TEMP_DIR = Path("/rc/temp")
CTL_DIR = Path("/rc/ctl")
PASSWORD = "password"

RC_TAG = os.environ.get("RC_TAG", "1.7.6-apache")
RC_VERSION = RC_TAG.split("-")[0]
MESSAGES_CACHE = os.environ.get("MESSAGES_CACHE", "0") == "1"


def rc_major_minor():
    return tuple(int(x) for x in RC_VERSION.split(".")[:2])
