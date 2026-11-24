<?php

/**
 * Edit subject - mail store on top of Roundcube's rcube_imap
 *
 * Works with Roundcube 1.6 and 1.7. Where Roundcube's high level API loses
 * information, the IMAP connection object (rcube_imap::$conn) is used:
 * - FETCH FLAGS is parsed by Roundcube into upper case names without "$"
 *   and "\", so "$label1" would come back as a different keyword "LABEL1";
 *   the flags are therefore read from the raw FETCH response.
 * - rcube_imap_generic::flagsToStr() reads an undefined array key for every
 *   flag it does not know (a warning under PHP 8); during the APPEND the
 *   flag map holds Roundcube's names plus the flags of the message.
 * - Roundcube caches the capabilities of the server in the session, its
 *   IMAP layer decides by the connection of the request; see has_uidplus().
 *
 * @license GNU GPLv3+
 */
class edit_subject_storage implements edit_subject_store
{
    /** @var rcube_imap */
    protected $storage;

    /** UIDVALIDITY of every folder at its first selection */
    protected $uidvalidity = [];

    /** Whether the UID of the last append() was named for the selected folder */
    protected $confirmed = false;

    public function __construct($storage)
    {
        $this->storage = $storage;
    }

    /**
     * Roundcube asks for a capability once and keeps the answer in the
     * session. When it expunges, rcube_imap looks at that cached answer and
     * rcube_imap_generic at the capability list of the current connection;
     * if UIDPLUS is missing in either, all messages flagged \Deleted are
     * removed (CLOSE or a plain EXPUNGE). The two differ with
     * imap_force_caps (the list is emptied after every login) and when the
     * server changes its capabilities while a session lives. So both are
     * asked; getCapability() loads the list of the connection if needed.
     * A request never reconnects, the answer holds until its end.
     */
    public function has_uidplus()
    {
        if (!$this->storage->get_capability('UIDPLUS') || !$this->storage->check_connection()) {
            return false;
        }

        return (bool) $this->storage->conn->getCapability('UIDPLUS');
    }

    public function rights($folder)
    {
        if (!$this->storage->get_capability('ACL')) {
            return null;
        }

        $rights = $this->storage->my_rights($folder);

        // ACL announced but MYRIGHTS failed: assume no rights
        return is_array($rights) ? $rights : [];
    }

    public function rfc4314_rights()
    {
        $values = $this->storage->get_capability('RIGHTS');

        if (!is_array($values)) {
            return false;
        }

        $letters = strtolower(implode('', $values));

        return strpos($letters, 't') !== false && strpos($letters, 'e') !== false;
    }

    public function read_only($folder)
    {
        $conn = $this->conn($folder);

        return $conn ? empty($conn->data['READ-WRITE']) : null;
    }

    public function message_info($folder, $uid)
    {
        $conn = $this->conn($folder);

        if (!$conn) {
            return null;
        }

        [$code, $response] = $conn->execute('UID FETCH', [(int) $uid, '(UID FLAGS INTERNALDATE RFC822.SIZE)'],
            0, '/^\* [0-9]+ FETCH /');

        if ($code != rcube_imap_generic::ERROR_OK) {
            return null;
        }

        foreach (self::fetch_items($response) as $items) {
            if (!self::is_uid($items, $uid)) {
                continue;
            }

            $flags = $items['FLAGS'] ?? null;
            $date = $items['INTERNALDATE'] ?? null;
            $size = $items['RFC822.SIZE'] ?? null;

            if (is_array($flags) && self::are_flags($flags) && is_string($date) && $date !== ''
                && is_string($size) && preg_match('/^[0-9]+$/D', $size)
            ) {
                return [
                    'flags' => array_values($flags),
                    'internaldate' => $date,
                    'size' => (int) $size,
                ];
            }
        }

        return null;
    }

    public function fetch_header($folder, $uid, $max_bytes = 0)
    {
        $conn = $this->conn($folder);

        if (!$conn) {
            return false;
        }

        // with a limit the server sends at most that many bytes (partial fetch)
        $header = $conn->handlePartBody($folder, (int) $uid, true, 'HEADER', null, false, null, false, (int) $max_bytes);

        // Roundcube returns what it has read when the connection breaks
        // within the data: a header block cut off somewhere
        return $this->storage->is_connected() ? $header : false;
    }

    public function fetch_body($folder, $uid, $fp, $max_bytes = 0)
    {
        $conn = $this->conn($folder);

        if (!$conn) {
            return false;
        }

        $max_bytes = max(0, (int) $max_bytes);

        // The partial fetch only asks: the server decides how long the
        // literal is. The write filter makes the limit hold for the file,
        // Roundcube stops writing at the first refused chunk.
        $cap = $max_bytes ? edit_subject_write_cap::append($fp, $max_bytes) : null;

        if ($cap === false) {
            // no limit, no fetch: the caller finds the body missing
            return false;
        }

        try {
            // streams the literal into the file in chunks of 1 MB
            return $conn->handlePartBody($folder, (int) $uid, true, 'TEXT', null, false, $fp, false, $max_bytes);
        } finally {
            if ($cap) {
                stream_filter_remove($cap);
            }
        }
    }

    public function append($folder, $path, $flags, $internaldate)
    {
        $conn = $this->conn($folder);
        $this->confirmed = false;

        if (!$conn) {
            return false;
        }

        // Keep Roundcube's own names (other plugins may set flags in the
        // message_saved hook), the flags of the message itself map to
        // themselves.
        $saved_map = $conn->flags;
        $map = $saved_map;

        foreach ($flags as $flag) {
            $map[strtoupper($flag)] = $flag;
        }

        $conn->flags = $map;

        // A DateTime object keeps the time zone offset of the original,
        // rcube_imap::date_format() would convert a string to +0000.
        $date = DateTime::createFromFormat('!j-M-Y H:i:s O', trim((string) $internaldate)) ?: $internaldate;

        try {
            $result = $this->storage->save_message($folder, $path, '', true, $flags, $date);
            $answer = (string) $conn->result;
        } finally {
            $conn->flags = $saved_map;
        }

        if ($result === false || $result === null) {
            // NO is the answer of the server to this command: nothing was
            // stored. Anything else leaves it open. That goes for BAD as
            // well - Roundcube also reports it when the server sends an
            // untagged "* BAD" while the command is still running - and for
            // an answer that never arrived.
            return $this->storage->get_error_code() === rcube_imap_generic::ERROR_NO ? false : null;
        }

        if (!is_numeric($result)) {
            return $result;
        }

        // RFC 4315: the UID is only valid together with the UIDVALIDITY of
        // the answer. If that is not the one of the selected folder, the
        // copy went into another folder of the same name. If it is, the UID
        // is known to be the copy. The answer may be gone by now (another
        // plugin has talked to the server in the message_saved hook): then
        // the UID is passed on unconfirmed, see append_confirmed().
        if (preg_match('/^\[APPENDUID ([0-9]+) ([0-9]+)\]/i', $answer, $m) && (int) $m[2] === (int) $result
            && isset($this->uidvalidity[$folder])
        ) {
            if ((string) $this->uidvalidity[$folder] !== $m[1]) {
                return true;
            }

            $this->confirmed = true;
        }

        return (int) $result;
    }

    public function append_confirmed()
    {
        return $this->confirmed;
    }

    public function delete($folder, $uid)
    {
        // never without UIDPLUS on this connection, never in another folder
        if (!$this->conn($folder) || !$this->has_uidplus()) {
            return false;
        }

        return (bool) $this->storage->delete_message((int) $uid, $folder);
    }

    public function unflag_deleted($folder, $uid)
    {
        // never in another folder of the same name
        if (!$this->conn($folder)) {
            return false;
        }

        return (bool) $this->storage->unset_flag((int) $uid, 'DELETED', $folder);
    }

    public function exists($folder, $uid)
    {
        $conn = $this->conn($folder);

        if (!$conn) {
            return null;
        }

        [$code, $response] = $conn->execute('UID FETCH', [(int) $uid, '(UID)'], 0, '/^\* [0-9]+ FETCH /');

        if ($code != rcube_imap_generic::ERROR_OK) {
            return null;
        }

        foreach (self::fetch_items($response, $complete) as $items) {
            if (self::is_uid($items, $uid)) {
                return true;
            }
        }

        // "not there" only if every line of the answer was understood
        return $complete ? false : null;
    }

    public function decode_header($value, $charset = null)
    {
        // Like Roundcube's own list: raw 8-bit text is read with the
        // character set of the message, if it names one. Without invalid
        // byte sequences: the client gets the value as JSON and sends it
        // back, both sides must see the same string.
        return (string) rcube_charset::clean(rcube_mime::decode_header($value, $charset ?: null));
    }

    public function last_error()
    {
        return (string) $this->storage->get_error_str();
    }

    /**
     * Data items of every "* n FETCH (...)" line of a response.
     *
     * Only the items of the top level are returned by name; parenthesized
     * lists (FLAGS, MODSEQ) are values. Read this way, a keyword that is
     * named like an item ("UID", "RFC822.SIZE") cannot be taken for one, no
     * matter in which order the server sends the items, and a line that is
     * not understood completely is ignored.
     *
     * A response that carries a literal ("{n}" followed by data) is not read
     * at all: the data may hold anything, also text that looks like a FETCH
     * line. The items asked for here never come as literals.
     *
     * @param string $response Lines of a FETCH response
     * @param bool   $complete Set to FALSE if a FETCH line (or the whole
     *                         response) was not understood, TRUE otherwise
     *
     * @return array[] One array per line: item name (upper case) => value,
     *                 a string or (for a list) an array
     */
    public static function fetch_items($response, &$complete = null)
    {
        $result = [];
        $complete = true;

        if (preg_match('/\{[0-9]+\+?\}\r?\n/', (string) $response)) {
            $complete = false;

            return $result;
        }

        foreach (preg_split('/\r?\n/', (string) $response) as $line) {
            if (!preg_match('/^\* [0-9]+ FETCH \(/', $line, $m)) {
                continue;
            }

            $pos = strlen($m[0]);
            $list = self::parse_list($line, $pos);

            // the list must be closed, nothing may follow, name/value pairs
            if ($list === null || trim(substr($line, $pos)) !== '' || count($list) % 2) {
                $complete = false;
                continue;
            }

            $items = [];

            for ($i = 0; $i < count($list); $i += 2) {
                $name = is_string($list[$i]) ? strtoupper($list[$i]) : null;

                if ($name === null || array_key_exists($name, $items)) {
                    $complete = false;
                    continue 2;
                }

                $items[$name] = $list[$i + 1];
            }

            $result[] = $items;
        }

        return $result;
    }

    /**
     * Whether every element of a FLAGS list is a flag as RFC 3501 defines
     * it: an atom, with a leading backslash for system flags. Roundcube
     * writes the flags into the APPEND command as they are; anything else
     * (a quoted string with spaces or parentheses) must not get there.
     */
    protected static function are_flags($flags)
    {
        foreach ($flags as $flag) {
            if (!is_string($flag) || !preg_match('/^\\\\?[^\x00-\x20\x7F-\xFF(){%*"\\\\\]]+$/D', $flag)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Read the elements of a parenthesized list up to its closing ")".
     *
     * @param string $line Response line
     * @param int    $pos  Offset behind the opening "(", moved behind the ")"
     *
     * @return array|null Atoms and quoted strings as strings, lists as
     *                    arrays; NULL if the list is not closed
     */
    protected static function parse_list($line, &$pos)
    {
        $list = [];
        $len = strlen($line);

        while ($pos < $len) {
            $char = $line[$pos];

            if ($char === ' ') {
                $pos++;
            } elseif ($char === ')') {
                $pos++;

                return $list;
            } elseif ($char === '(') {
                $pos++;
                $sub = self::parse_list($line, $pos);

                if ($sub === null) {
                    return null;
                }

                $list[] = $sub;
            } elseif ($char === '"') {
                $value = '';

                for ($pos++; $pos < $len && $line[$pos] !== '"'; $pos++) {
                    if ($line[$pos] === '\\') {
                        $pos++;
                    }

                    $value .= $line[$pos] ?? '';
                }

                if ($pos >= $len) {
                    return null;
                }

                $pos++;
                $list[] = $value;
            } else {
                $atom = strcspn($line, ' ()"', $pos);
                $list[] = substr($line, $pos, $atom);
                $pos += $atom;
            }
        }

        return null;
    }

    /**
     * Whether the items of a FETCH line belong to the given UID.
     */
    protected static function is_uid($items, $uid)
    {
        $value = $items['UID'] ?? null;

        return is_string($value) && preg_match('/^[0-9]+$/D', $value) && (int) $value === (int) $uid;
    }

    /**
     * Connected IMAP connection with the folder selected. NULL as well if
     * the folder is no longer the one it was at its first selection.
     */
    protected function conn($folder)
    {
        if (!$this->storage->check_connection()) {
            return null;
        }

        $conn = $this->storage->conn;

        if (!$conn->select($folder)) {
            return null;
        }

        // UIDs mean nothing in a folder that was deleted and created again
        $validity = $conn->data['UIDVALIDITY'] ?? null;

        if (!array_key_exists($folder, $this->uidvalidity)) {
            $this->uidvalidity[$folder] = $validity;
        } elseif ($this->uidvalidity[$folder] !== $validity) {
            return null;
        }

        return $conn;
    }
}

/**
 * Write filter that refuses everything beyond a number of bytes.
 *
 * A write that would go beyond the limit is refused as a whole (fwrite()
 * returns FALSE), so the file never grows beyond it, whatever length the
 * server announces for its literal (measured with PHP 8.1 and 8.4).
 */
class edit_subject_write_cap extends php_user_filter
{
    const NAME = 'edit_subject.write_cap';

    /** @var int Bytes still allowed */
    protected $left = 0;

    /**
     * Put the filter on a stream for writing.
     *
     * @return resource|false The filter, for stream_filter_remove()
     */
    public static function append($fp, $max_bytes)
    {
        if (!in_array(self::NAME, stream_get_filters(), true) && !stream_filter_register(self::NAME, self::class)) {
            return false;
        }

        return @stream_filter_append($fp, self::NAME, STREAM_FILTER_WRITE, (int) $max_bytes);
    }

    public function onCreate(): bool
    {
        $this->left = (int) $this->params;

        return $this->left > 0;
    }

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            if ($bucket->datalen > $this->left) {
                return PSFS_ERR_FATAL;
            }

            $this->left -= $bucket->datalen;
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
