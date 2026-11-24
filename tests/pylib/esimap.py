"""Independent IMAP control connection for the edit_subject tests.

The tests never trust Roundcube for the server state: everything that is
compared before and after an edit is read through this module.
"""

import base64
import email
import email.policy
import hashlib
import imaplib
import re
from datetime import datetime

imaplib._MAXLINE = 10_000_000  # allow long untagged responses


def mutf7_encode(name):
    """Encode a folder name as IMAP modified UTF-7 (RFC 3501, 5.1.3)."""
    out, buf = [], []

    def flush():
        if buf:
            raw = "".join(buf).encode("utf-16-be")
            b64 = base64.b64encode(raw).decode("ascii").rstrip("=").replace("/", ",")
            out.append("&" + b64 + "-")
            buf.clear()

    for ch in name:
        if 0x20 <= ord(ch) <= 0x7E:
            flush()
            out.append("&-" if ch == "&" else ch)
        else:
            buf.append(ch)
    flush()
    return "".join(out)


def quote(name):
    enc = mutf7_encode(name)
    return '"' + enc.replace("\\", "\\\\").replace('"', '\\"') + '"'


def split_header_fields(header_block):
    """Split a raw header block into fields, each with its continuation lines.

    The blank line that terminates the block is not part of the result.
    Line terminators are kept so that fields can be compared byte by byte.
    """
    lines = re.split(rb"(?<=\n)", header_block)
    fields = []
    for line in lines:
        if line in (b"", b"\r\n", b"\n"):
            if line:
                break
            continue
        if line[:1] in (b" ", b"\t") and fields:
            fields[-1] += line
        else:
            fields.append(line)
    return fields


def field_name(field):
    return field.split(b":", 1)[0].strip().lower().decode("ascii", "replace")


def decoded_subject(header_block):
    msg = email.message_from_bytes(header_block + b"\r\n", policy=email.policy.default)
    values = msg.get_all("Subject")
    return None if values is None else [str(v) for v in values]


class Snapshot:
    """Everything the tests compare about one message."""

    def __init__(self, uid, flags, internaldate, size, header, text):
        self.uid = uid
        self.flags = flags
        self.internaldate = internaldate
        self.size = size
        self.header = header
        self.text = text
        self.body_sha256 = hashlib.sha256(text).hexdigest()
        self.fields = split_header_fields(header)

    @property
    def raw(self):
        return self.header + self.text

    def subjects(self):
        return decoded_subject(self.header) or []

    def fields_without(self, *names):
        names = {n.lower() for n in names}
        return [f for f in self.fields if field_name(f) not in names]


class Imap:
    def __init__(self, host, port, user, password):
        self.user = user
        self.conn = imaplib.IMAP4(host, port)
        self.conn.login(user, password)
        self.selected = None

    def close(self):
        try:
            self.conn.logout()
        except Exception:
            pass

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        self.close()

    # -- helpers -----------------------------------------------------------

    def _ok(self, typ, data, what):
        if typ != "OK":
            raise AssertionError(f"IMAP {what} failed: {typ} {data!r}")
        return data

    def capabilities(self):
        typ, data = self.conn.capability()
        return set(self._ok(typ, data, "CAPABILITY")[0].decode().upper().split())

    def create(self, folder, subscribe=True):
        typ, data = self.conn.create(quote(folder))
        self._ok(typ, data, f"CREATE {folder}")
        if subscribe:
            typ, data = self.conn.subscribe(quote(folder))
            self._ok(typ, data, f"SUBSCRIBE {folder}")

    def rename(self, old, new):
        typ, data = self.conn.rename(quote(old), quote(new))
        self._ok(typ, data, f"RENAME {old} {new}")
        self.conn.subscribe(quote(new))

    def select(self, folder, readonly=True):
        typ, data = self.conn.select(quote(folder), readonly=readonly)
        self._ok(typ, data, f"SELECT {folder}")
        self.selected = folder
        return int(data[0])

    def count(self, folder):
        # STATUS on the selected folder is not reliable on every server;
        # a fresh EXAMINE always reports the current EXISTS value.
        return self.select(folder)

    def append(self, folder, raw, flags=(), date=None):
        """APPEND a message and return its UID (from APPENDUID)."""
        flag_str = "(" + " ".join(flags) + ")" if flags else None
        when = imaplib.Time2Internaldate(date) if date is not None else None
        typ, data = self.conn.append(quote(folder), flag_str, when, raw)
        self._ok(typ, data, f"APPEND {folder}")
        m = re.search(rb"\[APPENDUID \d+ (\d+)\]", data[0] or b"")
        if not m:
            raise AssertionError(f"no APPENDUID in {data!r}")
        return int(m.group(1))

    def uids(self, folder):
        self.select(folder)
        typ, data = self.conn.uid("SEARCH", None, "ALL")
        self._ok(typ, data, "UID SEARCH")
        return [int(x) for x in data[0].split()]

    def store(self, folder, uid, op, flags):
        self.select(folder, readonly=False)
        typ, data = self.conn.uid("STORE", str(uid), op, "(" + " ".join(flags) + ")")
        self._ok(typ, data, "UID STORE")

    def _fetch_literal(self, uid, item):
        typ, data = self.conn.uid("FETCH", str(uid), f"(BODY.PEEK[{item}])")
        self._ok(typ, data, f"UID FETCH {item}")
        for part in data:
            if isinstance(part, tuple):
                return part[1]
        # empty part: server answers with "" instead of a literal
        for part in data:
            if isinstance(part, bytes) and re.search(rb'BODY\[' + item.encode() + rb'\] ""', part):
                return b""
        return None

    def snapshot(self, folder, uid):
        """Fetch flags, INTERNALDATE, size, header block and body of one UID.

        Returns None if the UID does not exist in the folder.
        """
        self.select(folder)
        typ, data = self.conn.uid("FETCH", str(uid), "(UID FLAGS INTERNALDATE RFC822.SIZE)")
        self._ok(typ, data, "UID FETCH meta")
        # The whole line in the order Dovecot sends it. Looking for "UID n"
        # or "RFC822.SIZE n" anywhere in the line would also find keywords
        # of these names in the list of flags (test case 30).
        meta = None
        for line in data:
            m = isinstance(line, bytes) and re.fullmatch(
                rb'\d+ \(UID (\d+) FLAGS \(([^)]*)\) INTERNALDATE "([^"]+)" RFC822\.SIZE (\d+)\)', line)
            if m and int(m.group(1)) == uid:
                meta = m
        if meta is None:
            return None
        flags = set(meta.group(2).decode().split())
        flags.discard("\\Recent")
        internaldate = meta.group(3).decode()
        size = int(meta.group(4))
        header = self._fetch_literal(uid, "HEADER")
        text = self._fetch_literal(uid, "TEXT")
        return Snapshot(uid, flags, internaldate, size, header, text)

    def uidnext(self, folder):
        typ, data = self.conn.status(quote(folder), "(UIDNEXT)")
        self._ok(typ, data, "STATUS UIDNEXT")
        return int(re.search(rb"UIDNEXT (\d+)", data[0]).group(1))

    def quota(self, folder="INBOX"):
        """(used, limit) in bytes of the STORAGE resource."""
        typ, data = self.conn.getquotaroot(quote(folder))
        self._ok(typ, data, "GETQUOTAROOT")
        for line in data[1]:
            m = re.search(rb"STORAGE (\d+) (\d+)", line)
            if m:
                return int(m.group(1)) * 1024, int(m.group(2)) * 1024
        raise AssertionError(f"no STORAGE quota in {data!r}")

    def exists(self, folder, uid):
        self.select(folder)
        typ, data = self.conn.uid("SEARCH", None, "UID", str(uid))
        self._ok(typ, data, "UID SEARCH UID")
        return str(uid).encode() in data[0].split()


def internaldate_epoch(value):
    """INTERNALDATE string -> seconds since epoch (the instant, not the zone)."""
    return datetime.strptime(value.strip(), "%d-%b-%Y %H:%M:%S %z").timestamp()
