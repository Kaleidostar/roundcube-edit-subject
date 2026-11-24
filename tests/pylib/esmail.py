"""Test mails from tests/fixtures."""

from pathlib import Path

FIXTURES = Path(__file__).resolve().parents[1] / "fixtures"


def load_fixture(name):
    """Fixture mail with CRLF line endings, as an IMAP server stores it."""
    raw = (FIXTURES / name).read_bytes()
    return raw.replace(b"\r\n", b"\n").replace(b"\n", b"\r\n")
