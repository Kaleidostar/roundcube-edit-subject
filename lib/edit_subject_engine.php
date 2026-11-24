<?php

/**
 * Edit subject - server side procedure
 *
 * Replaces a message by a copy with a new subject. The order of the steps
 * carries the data safety: the original is only removed after the copy has
 * been checked (size, header block, subject, exact flags, date and a
 * cryptographic body comparison) and found again right before the deletion. The
 * copy is only rolled back if it is known to be the copy, and only after
 * the original has been found again, unchanged and no longer flagged for
 * deletion. If another client changes, moves or removes the original during
 * the edit, that action stands and the copy is taken back.
 *
 * The class talks to the mail store only through edit_subject_store, so it
 * can be tested with a simulated store (tests/unit).
 *
 * @license GNU GPLv3+
 */
class edit_subject_engine
{
    const STATUS_OK = 'ok';
    const STATUS_UNCHANGED = 'unchanged';
    const STATUS_ERROR = 'error';

    // Error codes, also used as localization keys
    const ERR_INVALID_SUBJECT = 'errorinvalidsubject';
    const ERR_TOO_LONG = 'errortoolong';
    const ERR_NOT_FOUND = 'errornotfound';
    const ERR_HEADER_TOO_LARGE = 'errorheadertoolarge';
    const ERR_CHANGED = 'errorchanged';
    const ERR_BUSY = 'errorbusy';
    const ERR_NO_UIDPLUS = 'errornouidplus';
    const ERR_READ_ONLY = 'errorreadonly';
    const ERR_NO_RIGHTS = 'errornorights';
    const ERR_TEMP_FILE = 'errortempfile';
    const ERR_FETCH = 'errorfetch';
    const ERR_APPEND = 'errorappend';
    const ERR_APPEND_UNKNOWN = 'errorappendunknown';
    const ERR_VERIFY = 'errorverify';
    const ERR_DELETE = 'errordelete';
    const ERR_BOTH_EXIST = 'errorbothexist';
    const ERR_UNCERTAIN = 'erroruncertain';
    const ERR_LOST = 'errorlost';
    const ERR_COPY_GONE = 'errorcopygone';
    const ERR_COPY_CHANGED = 'errorcopychanged';
    const ERR_MESSAGE_TOO_LARGE = 'errormessagetoolarge';
    const ERR_UNKNOWN = 'errorunknown';

    /** Default upper bound for the complete message, configurable in bytes. */
    const MAX_MESSAGE_SIZE = 67108864;

    /**
     * Header blocks are processed in memory. The size alone does not bound
     * the memory: every header line costs about 450 bytes while the block is
     * taken apart (measured with PHP 8.4), so the number of lines is limited
     * as well. 20000 lines are about 9 MiB.
     */
    const MAX_HEADER_SIZE = 4194304;
    const MAX_HEADER_LINES = 20000;

    /**
     * Longest raw Subject value that is decoded, the rest is cut off (as
     * Roundcube does it for its own display, there after 2048 bytes). The
     * decoder of Roundcube needs about 145 bytes of memory per byte of a
     * prepared value, and quadratic time where PCRE runs without JIT
     * (measured: 100 KB in 15 s, 500 KB not within the time limit). The
     * longest field this plugin writes itself has about 3300 bytes.
     */
    const MAX_SUBJECT_BYTES = 8192;

    protected $store;
    protected $temp_dir;
    protected $temp_prefix;
    protected $logger;
    protected $scope;
    protected $max_message_size;

    /**
     * @param edit_subject_store $store            Mail store
     * @param string             $temp_dir         Directory for temporary files
     * @param string             $temp_prefix      Prefix of temporary file names
     * @param callable|null      $logger           Receives error messages for the log
     * @param string             $scope            Identifies the mail account for its lock
     * @param int                $max_message_size Maximum complete message size in bytes
     */
    public function __construct(edit_subject_store $store, $temp_dir, $temp_prefix = 'edit_subject', $logger = null, $scope = '', $max_message_size = self::MAX_MESSAGE_SIZE)
    {
        $this->store = $store;
        $this->temp_dir = (string) $temp_dir;
        $this->temp_prefix = (string) $temp_prefix;
        $this->logger = $logger;
        $this->scope = (string) $scope;
        $this->max_message_size = (int) $max_message_size > 0 ? (int) $max_message_size : self::MAX_MESSAGE_SIZE;
    }

    /**
     * Size limit as written in the configuration: a positive integer (bytes)
     * or a string of digits with an optional unit K, M or G, as Roundcube's
     * own size options take it ("64M"). Anything else is no size: no
     * booleans, no floats, no fractions, nothing beyond PHP_INT_MAX.
     *
     * @param mixed $value Configured value
     *
     * @return int|null Bytes, NULL if the value is not a size
     */
    public static function size_limit($value)
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (!is_string($value) || !preg_match('/^\s*([0-9]+)\s*([KMG]?)B?\s*$/iD', $value, $m)) {
            return null;
        }

        $units = ['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824];
        $bytes = (float) $m[1] * $units[strtoupper($m[2])];

        // as a float PHP_INT_MAX is 2^63, which no longer fits an integer
        return $bytes >= 1 && $bytes < PHP_INT_MAX ? (int) $bytes : null;
    }

    /**
     * Decoded subject of a message as the plugin sees it: the last Subject
     * field, decoded by the store like Roundcube decodes it for its list
     * (raw 8-bit text with the character set of the message), at most
     * MAX_SUBJECT_BYTES of the raw value. Empty string if there is none.
     *
     * @return array Result: status, code, uid, subject, detail
     */
    public function load($folder, $uid)
    {
        $uid = (int) $uid;
        $info = $this->store->message_info($folder, $uid);

        if (!$info) {
            return $this->result(self::STATUS_ERROR, self::ERR_NOT_FOUND, $uid);
        }

        if ((int) $info['size'] > $this->max_message_size) {
            return $this->result(self::STATUS_ERROR, self::ERR_MESSAGE_TOO_LARGE, $uid, null, $this->max_message_size);
        }

        $header = $this->store->fetch_header($folder, $uid, self::MAX_HEADER_SIZE + 1);

        if (!is_string($header) || $header === '') {
            return $this->result(self::STATUS_ERROR, self::ERR_NOT_FOUND, $uid);
        }

        if ($this->header_too_large($header)) {
            return $this->result(self::STATUS_ERROR, self::ERR_HEADER_TOO_LARGE, $uid);
        }

        return $this->result(self::STATUS_OK, null, $uid, $this->decoded_subject($header));
    }

    /**
     * Replace the subject of one message.
     *
     * @param string      $folder        Folder of the message
     * @param int         $uid           UID of the message
     * @param string      $subject       New subject (raw user input)
     * @param bool        $keep_original Store the original subject as X-Original-Subject
     * @param string|null $expected      Subject the user saw when he started
     *                                   (as returned by load()). If the message
     *                                   has another one by now, nothing happens.
     *
     * @return array Result: status, code, uid (new UID on success), subject, detail
     */
    public function run($folder, $uid, $subject, $keep_original = false, $expected = null)
    {
        $uid = (int) $uid;

        // Clean up the input first; nothing on the server is touched for
        // invalid input.
        try {
            $subject = edit_subject_header::sanitize($subject);
        } catch (InvalidArgumentException $e) {
            $code = $e->getCode() == edit_subject_header::ERROR_TOO_LONG ? self::ERR_TOO_LONG : self::ERR_INVALID_SUBJECT;

            return $this->result(self::STATUS_ERROR, $code, $uid);
        }

        // One replacement at a time for this account (on this host)
        $lock = $this->lock($folder, $uid);

        if (is_string($lock)) {
            return $this->result(self::STATUS_ERROR, $lock, $uid, $subject);
        }

        try {
            return $this->replace($folder, $uid, $subject, $keep_original, $expected);
        } finally {
            $this->unlock($lock);
        }
    }

    /**
     * The procedure itself, see run().
     */
    protected function replace($folder, $uid, $subject, $keep_original, $expected)
    {
        $info = $this->store->message_info($folder, $uid);

        if (!$info) {
            return $this->result(self::STATUS_ERROR, self::ERR_NOT_FOUND, $uid, $subject);
        }

        // Bound the complete IMAP stream and the temporary file before any
        // message bytes are fetched. Parallel requests are serialized by the
        // account lock, so this is also the per-account temporary-file budget.
        if ((int) $info['size'] > $this->max_message_size) {
            $this->log("UID {$uid} in {$folder} exceeds the message size limit of {$this->max_message_size} bytes");

            return $this->result(self::STATUS_ERROR, self::ERR_MESSAGE_TOO_LARGE, $uid, $subject, $this->max_message_size);
        }

        $header = $this->store->fetch_header($folder, $uid, self::MAX_HEADER_SIZE + 1);

        if (!is_string($header) || $header === '') {
            $this->log("could not read the header of UID {$uid} in {$folder}");

            return $this->result(self::STATUS_ERROR, self::ERR_FETCH, $uid, $subject);
        }

        if ($this->header_too_large($header)) {
            $this->log("header of UID {$uid} in {$folder} is too large (" . strlen($header) . ' bytes or more, '
                . substr_count($header, "\n") . ' lines)');

            return $this->result(self::STATUS_ERROR, self::ERR_HEADER_TOO_LARGE, $uid, $subject);
        }

        $current = $this->decoded_subject($header);

        // The message must still be the one the user looked at
        if ($expected !== null && $current !== $expected) {
            return $this->result(self::STATUS_ERROR, self::ERR_CHANGED, $uid, $subject);
        }

        // Unchanged subject: nothing happens on the server
        if ($current === $subject) {
            return $this->result(self::STATUS_UNCHANGED, null, $uid, $subject);
        }

        // Step 2: without UIDPLUS, expunging falls back to CLOSE or to a
        // plain EXPUNGE, which remove every message flagged \Deleted in the
        // folder
        if (!$this->store->has_uidplus()) {
            $this->log('server does not support UIDPLUS, refusing to replace a message');

            return $this->result(self::STATUS_ERROR, self::ERR_NO_UIDPLUS, $uid, $subject);
        }

        // A copy in a folder opened read-only could not be removed again
        if ($this->store->read_only($folder)) {
            return $this->result(self::STATUS_ERROR, self::ERR_READ_ONLY, $uid, $subject);
        }

        // Step 4 (needed for step 3): flags and keywords without \Recent
        $flags = $this->without_recent($info['flags']);

        // Step 3: rights, where the server reports them
        if ($missing = $this->missing_rights($folder, $flags)) {
            $this->log("missing rights '" . implode('', $missing) . "' in {$folder}");

            return $this->result(self::STATUS_ERROR, self::ERR_NO_RIGHTS, $uid, $subject, implode('', $missing));
        }

        // Steps 5 and 6: new header block plus the unchanged body in a file.
        // Plain ASCII is written as it is - unless it would not be read back
        // as typed: Roundcube reads raw header text with the character set
        // of the message, and that may be no superset of ASCII (UTF-16).
        $encoded = $this->decode_subject(edit_subject_header::subject_field($subject), edit_subject_header::charset($header)) !== $subject;
        $new_header = edit_subject_header::rewrite($header, $subject, $keep_original, $encoded);
        $body_size = (int) $info['size'] - strlen($header);

        if ($body_size < 0) {
            $this->log("UID {$uid} in {$folder}: RFC822.SIZE {$info['size']} smaller than its header block");

            return $this->result(self::STATUS_ERROR, self::ERR_FETCH, $uid, $subject);
        }

        $path = null;
        $fp = null;
        $keep_temp = false;

        try {
            if (!($path = $this->create_temp_file())) {
                return $this->result(self::STATUS_ERROR, self::ERR_TEMP_FILE, $uid, $subject);
            }

            // "r+b": the file exists and is empty. Nothing is written unless
            // the handle is the file that was just created; a symbolic link
            // put in its place would be followed.
            if (!($fp = @fopen($path, 'r+b'))
                || !($written = $this->own_file($fp, $path))
                || fwrite($fp, $new_header) !== strlen($new_header)
            ) {
                $this->log('could not write the temporary file');

                return $this->result(self::STATUS_ERROR, self::ERR_TEMP_FILE, $uid, $subject);
            }

            // The return value is not reliable (Roundcube 1.6 returns false
            // after writing a non-empty body), the file size is. One byte
            // more than announced is enough to see a longer body; the server
            // never gets to write more than that into the file.
            $this->store->fetch_body($folder, $uid, $fp, $body_size + 1);
            fflush($fp);
            fclose($fp);
            $fp = null;

            $expected_size = strlen($new_header) + $body_size;

            // Roundcube opens the file by its name again: the name must
            // still lead to the file that was written
            clearstatcache(true, $path);
            $there = @lstat($path);

            if (!$there || $there['ino'] !== $written['ino'] || $there['dev'] !== $written['dev']
                || ($there['mode'] & 0170000) !== 0100000
            ) {
                $this->log('the temporary file was replaced while it was written');

                return $this->result(self::STATUS_ERROR, self::ERR_TEMP_FILE, $uid, $subject);
            }

            if ($there['size'] !== $expected_size) {
                $this->log("UID {$uid} in {$folder}: fetched " . ($there['size'] - strlen($new_header))
                    . " body bytes, expected {$body_size}");

                return $this->result(self::STATUS_ERROR, self::ERR_FETCH, $uid, $subject);
            }

            // Step 7: store the copy with the same flags and INTERNALDATE
            $new_uid = $this->store->append($folder, $path, $flags, $info['internaldate']);

            if ($new_uid === false) {
                $this->log("APPEND to {$folder} failed: " . $this->store->last_error());

                return $this->result(self::STATUS_ERROR, self::ERR_APPEND, $uid, $subject, $this->store->last_error());
            }

            if ($new_uid === null) {
                // No answer, or none that tells: the copy may or may not be
                // there. The original is untouched.
                $this->log("APPEND to {$folder} was not confirmed (" . ($this->store->last_error() ?: 'no answer')
                    . "), original UID {$uid} kept");

                return $this->result(self::STATUS_ERROR, self::ERR_APPEND_UNKNOWN, $uid, $subject);
            }

            if (!is_int($new_uid) || $new_uid <= $uid) {
                // The copy was stored, but cannot be identified: no UID, or
                // one that a new message cannot have. Leave both.
                $this->log("APPEND to {$folder} returned no usable APPENDUID (" . var_export($new_uid, true)
                    . "), original UID {$uid} kept");

                return $this->result(self::STATUS_ERROR, self::ERR_BOTH_EXIST, $uid, $subject);
            }

            // What is known about the copy, in case it has to be taken back
            $copy = [
                'uid' => $new_uid,
                'header' => $new_header,
                'confirmed' => (bool) $this->store->append_confirmed(),
                'seen' => false,
            ];
            $keep_temp = true;
        } finally {
            if ($fp) {
                fclose($fp);
            }
            if (!$keep_temp) {
                $this->remove_temp_file($path);
            }
        }

        // Step 8: verify the copy. The temporary file remains available for
        // the body comparison and is removed on every outcome.
        try {
            $problem = $this->verify($folder, $new_uid, $new_header, $expected_size, $subject, $flags,
                $info['internaldate'], $path, $copy['seen']);
        } finally {
            $this->remove_temp_file($path);
        }

        if ($problem) {
            $this->log("verification of the copy UID {$new_uid} in {$folder} failed: {$problem}");

            // The broken copy is taken back in any case. Only the message
            // depends on the original: if another client has changed or
            // removed it meanwhile, "unchanged" would not be true.
            $now = $this->store->message_info($folder, $uid);
            $gone = !$now && $this->store->exists($folder, $uid) === false;
            $changed = $gone
                || ($now && (!$this->same_message($info, $now) || !$this->same_flags($flags, $now['flags'])));

            return $this->undo($folder, $uid, $copy, $flags, $changed ? self::ERR_CHANGED : self::ERR_VERIFY, $subject, $gone);
        }

        // Before step 9: the original must still be what was read at the
        // beginning. If another client removed, moved or changed it
        // meanwhile, that action stands: the copy is taken back instead of
        // overwriting it, also when the copy is the only version left. A
        // second edit running elsewhere is such a client as well; keeping
        // the copy would leave two versions, both reported as success.
        $now = $this->store->message_info($folder, $uid);

        if (!$this->same_message($info, $now) || !$this->same_flags($flags, $now['flags'])) {
            if (!$now && $this->store->exists($folder, $uid) !== false) {
                // not readable right now, but maybe still there
                $this->log("could not read the original UID {$uid} in {$folder} again, both versions exist");

                return $this->result(self::STATUS_ERROR, self::ERR_BOTH_EXIST, $uid, $subject);
            }

            $this->log("the original UID {$uid} in {$folder} was changed or removed during the edit");

            return $this->undo($folder, $uid, $copy, $flags, self::ERR_CHANGED, $subject, !$now);
        }

        // And the copy must still be there with exactly the flags that were
        // verified: with the original deleted it is the only version.
        $copy_now = $this->store->message_info($folder, $new_uid);
        $there = $this->store->exists($folder, $new_uid);

        if ($there === false) {
            $this->log("the copy UID {$new_uid} in {$folder} was removed by someone else, original UID {$uid} kept");

            return $this->result(self::STATUS_ERROR, self::ERR_COPY_GONE, $uid, $subject);
        }

        if (!$copy_now || $there === null) {
            $this->log("could not check the copy UID {$new_uid} in {$folder} again, original UID {$uid} kept, both versions exist");

            return $this->result(self::STATUS_ERROR, self::ERR_BOTH_EXIST, $uid, $subject);
        }

        if (!$this->same_flags($flags, $copy_now['flags'])) {
            // someone else has flagged the copy (\Deleted, \Seen, ...)
            $this->log("flags of the copy UID {$new_uid} in {$folder} changed after verification");

            return $this->undo($folder, $uid, $copy, $flags, self::ERR_COPY_CHANGED, $subject);
        }

        // Step 9: now delete the original, exactly this one UID
        $this->store->delete($folder, $uid);
        $still_there = $this->store->exists($folder, $uid);

        if ($still_there === false) {
            return $this->replaced($folder, $uid, $new_uid, $subject, $flags);
        }

        if ($still_there === null) {
            // Unknown state: the original may be gone, so the copy must stay.
            $this->log("could not check whether UID {$uid} in {$folder} was removed, copy UID {$new_uid} kept");

            return $this->result(self::STATUS_ERROR, self::ERR_UNCERTAIN, $uid, $subject);
        }

        // Step 10: the original is reported to be still there - restore the
        // starting point. First take back the deletion flag this request
        // has set: while the original carries it, the expunge of any other
        // client removes it.
        $this->log("could not delete the original UID {$uid} in {$folder}");

        if (!$this->has_flag($flags, '\\Deleted') && !$this->store->unflag_deleted($folder, $uid)) {
            // still flagged for deletion: it may vanish with the next expunge
            $this->log("could not unflag the original UID {$uid} in {$folder}, copy UID {$new_uid} kept, both versions exist");

            return $this->result(self::STATUS_ERROR, self::ERR_BOTH_EXIST, $uid, $subject);
        }

        // Only then look at the original again. A server answers OK as well
        // when the flag of a message is removed that is no longer there, so
        // the answer above says nothing about the message itself.
        $now = $this->store->message_info($folder, $uid);

        if (!$now && $this->store->exists($folder, $uid) === false) {
            // gone after all: the copy is the message now
            $this->log("the original UID {$uid} in {$folder} was removed by someone else while it was flagged for deletion");

            return $this->replaced($folder, $uid, $new_uid, $subject, $flags);
        }

        if (!$this->same_message($info, $now)
            || ($this->has_flag($now['flags'], '\\Deleted') && !$this->has_flag($flags, '\\Deleted'))
        ) {
            $this->log("the original UID {$uid} in {$folder} could not be confirmed, copy UID {$new_uid} kept, both versions exist");

            return $this->result(self::STATUS_ERROR, self::ERR_BOTH_EXIST, $uid, $subject);
        }

        return $this->undo($folder, $uid, $copy, $flags, self::ERR_DELETE, $subject);
    }

    /**
     * The original is gone. That is a success only if the copy is still there
     * with exactly the verified flags (step 11).
     */
    protected function replaced($folder, $uid, $new_uid, $subject, $flags)
    {
        $copy = $this->store->message_info($folder, $new_uid);

        if ($copy && $this->same_flags($flags, $copy['flags'])) {
            return $this->result(self::STATUS_OK, null, $new_uid, $subject);
        }

        if ($copy) {
            $this->log("flags of the last copy UID {$new_uid} in {$folder} changed after the original UID {$uid} was removed");

            return $this->result(self::STATUS_ERROR, self::ERR_UNCERTAIN, $uid, $subject);
        }

        $exists = $this->store->exists($folder, $new_uid);

        if ($exists === false) {
            $this->log("after deleting the original UID {$uid} in {$folder} the copy UID {$new_uid} is gone as well");

            return $this->result(self::STATUS_ERROR, self::ERR_LOST, $uid, $subject);
        }

        $this->log("could not check the copy UID {$new_uid} in {$folder} after deleting the original UID {$uid}");

        return $this->result(self::STATUS_ERROR, self::ERR_UNCERTAIN, $uid, $subject);
    }

    /**
     * Take the copy back and report why. If the copy cannot be removed,
     * both versions exist - or only the copy, if the original is gone.
     *
     * @param array $copy          What is known about the copy: uid, header
     *                             (the header block as written), confirmed
     *                             (the server named the UID for this very
     *                             folder), seen (a message was found under
     *                             the UID)
     * @param bool  $original_gone Another client has removed the original
     */
    protected function undo($folder, $uid, $copy, $flags, $code, $subject, $original_gone = false)
    {
        $removed = $this->remove_copy($folder, $copy, $flags);

        if ($removed === true) {
            return $this->result(self::STATUS_ERROR, $code, $uid, $subject);
        }

        if ($removed === null) {
            // Never seen and not verifiably removed: it may be there or not.
            if ($original_gone) {
                $this->log("the original UID {$uid} in {$folder} is gone, whether the copy UID {$copy['uid']} exists is unknown");

                return $this->result(self::STATUS_ERROR, self::ERR_UNKNOWN, $uid, $subject);
            }

            $this->log("the copy UID {$copy['uid']} in {$folder} was never seen and may exist, original UID {$uid} kept");

            return $this->result(self::STATUS_ERROR, self::ERR_APPEND_UNKNOWN, $uid, $subject);
        }

        if ($original_gone) {
            $this->log("could not remove the copy UID {$copy['uid']} in {$folder}, it is the only version now");

            return $this->result(self::STATUS_ERROR, self::ERR_UNCERTAIN, $uid, $subject);
        }

        $this->log("could not remove the copy UID {$copy['uid']} in {$folder}, both versions exist");

        return $this->result(self::STATUS_ERROR, self::ERR_BOTH_EXIST, $uid, $subject);
    }

    /**
     * Rights missing for the operation, empty if all are there or the
     * server does not report rights.
     */
    protected function missing_rights($folder, $flags)
    {
        $rights = $this->store->rights($folder);

        if ($rights === null) {
            return [];
        }

        $rights = array_map('strtolower', (array) $rights);

        // "i" to store the copy, "t" and "e" to remove the original. Servers
        // with RFC 4314 rights may report the obsolete "d" although "e" is
        // missing (measured with Dovecot 2.4), so "d" only counts on servers
        // without RFC 4314 rights.
        $need = ['r', 'i'];
        $need = array_merge($need, $this->store->rfc4314_rights() ? ['t', 'e'] : ['d']);

        foreach ($flags as $flag) {
            if (strcasecmp($flag, '\\Seen') === 0) {
                $need[] = 's';
            } elseif (strcasecmp($flag, '\\Deleted') === 0) {
                $need[] = $this->store->rfc4314_rights() ? 't' : 'd';
            } else {
                // all other flags and keywords are silently dropped without "w"
                $need[] = 'w';
            }
        }

        return array_values(array_diff(array_unique($need), $rights));
    }

    /**
     * Check the stored copy. Returns a description of the first problem or
     * NULL if everything matches.
     */
    protected function verify($folder, $uid, $header, $size, $subject, $flags, $internaldate, $temp_path, &$seen = false)
    {
        $info = $this->store->message_info($folder, $uid);
        $seen = (bool) $info;

        if (!$info) {
            return 'copy not found';
        }

        if ((int) $info['size'] !== $size) {
            return "size {$info['size']} instead of {$size}";
        }

        // one byte more than expected shows a longer header block as well
        $stored_header = $this->store->fetch_header($folder, $uid, strlen($header) + 1);

        if ($stored_header !== $header) {
            return 'header block differs';
        }

        if ($this->decoded_subject($stored_header) !== $subject) {
            return 'decoded subject differs';
        }

        foreach ($flags as $flag) {
            if (!$this->has_flag($info['flags'], $flag)) {
                return "flag {$flag} missing";
            }
        }

        if (!$this->same_flags($flags, $info['flags'])) {
            return 'unexpected flags on the copy';
        }

        $expected = $this->timestamp($internaldate);
        $actual = $this->timestamp($info['internaldate']);

        if ($expected === null || $expected !== $actual) {
            return "INTERNALDATE {$info['internaldate']} instead of {$internaldate}";
        }

        $same_body = $this->same_body($temp_path, strlen($header), $folder, $uid);

        if ($same_body === null) {
            return 'body could not be read for comparison';
        }

        if ($same_body === false) {
            return 'body differs';
        }

        return null;
    }

    /**
     * Compare the body written before APPEND with the body stored on the
     * server, not just its length. The same temporary file is reused: first
     * the expected body is hashed, then the file is truncated and receives
     * the stored body for its hash. Memory use stays constant and no second
     * temporary message is created.
     *
     * @return bool|null TRUE for byte-identical bodies, FALSE for a mismatch,
     *                   NULL if either body could not be read completely
     */
    protected function same_body($path, $offset, $folder, $uid)
    {
        $fp = @fopen($path, 'r+b');

        if (!$fp || !$this->own_file($fp, $path)) {
            if (is_resource($fp)) {
                fclose($fp);
            }

            return null;
        }

        try {
            if (fseek($fp, $offset) !== 0) {
                return null;
            }

            $expected = hash_init('sha256');
            $expected_bytes = hash_update_stream($expected, $fp);
            $expected_hash = hash_final($expected);

            if (!ftruncate($fp, 0) || fseek($fp, 0) !== 0) {
                return null;
            }

            // The return value is not reliable (Roundcube 1.6); byte count
            // and hash below decide whether the complete body arrived. At
            // most one byte more than expected is written, as above.
            $this->store->fetch_body($folder, $uid, $fp, $expected_bytes + 1);

            if (!fflush($fp) || fseek($fp, 0) !== 0) {
                return null;
            }

            $actual = hash_init('sha256');
            $actual_bytes = hash_update_stream($actual, $fp);
            $actual_hash = hash_final($actual);

            return $expected_bytes === $actual_bytes && hash_equals($expected_hash, $actual_hash);
        } finally {
            fclose($fp);
        }
    }

    /**
     * Remove the copy again.
     *
     * @param array $copy See undo()
     *
     * @return bool|null TRUE only if it is verifiably gone, FALSE if it is
     *                   still there, NULL if nothing is known about it
     */
    protected function remove_copy($folder, $copy, $flags)
    {
        $uid = $copy['uid'];
        $seen = $copy['seen'];

        // Only a message that is known to be the copy is deleted: the server
        // named its UID for this very folder (APPENDUID with the UIDVALIDITY
        // of the folder), or it carries the header block that was written.
        // A UID alone may belong to a message of someone else.
        if (!$copy['confirmed']) {
            if ($this->store->fetch_header($folder, $uid, strlen($copy['header']) + 1) !== $copy['header']) {
                $this->log("UID {$uid} in {$folder} is not known to be the copy, nothing was removed");

                return $seen ? false : null;
            }

            $seen = true;
        }

        $reported = $this->store->delete($folder, $uid);

        if ($this->store->exists($folder, $uid) === false) {
            // "Not found" proves nothing for a message this connection has
            // never seen (a server may show a new message after the next
            // command only) - unless the deletion itself was accepted.
            return $seen || $reported ? true : null;
        }

        // do not leave the copy flagged for deletion
        if (!$this->has_flag($flags, '\\Deleted')) {
            $this->store->unflag_deleted($folder, $uid);
        }

        return false;
    }

    /**
     * Decoded Subject of a header block, as Roundcube shows it: the last
     * Subject field, raw 8-bit text read with the character set of the
     * message.
     */
    protected function decoded_subject($header)
    {
        return $this->decode_subject($header, edit_subject_header::charset($header));
    }

    /**
     * Decoded value of the last Subject field of a header block.
     *
     * @param string      $header  Header block, or a single Subject field
     * @param string|null $charset Character set of raw 8-bit text
     */
    protected function decode_subject($header, $charset)
    {
        $value = edit_subject_header::field_value($header, 'Subject', true);

        if ($value === null) {
            return '';
        }

        if (strlen($value) > self::MAX_SUBJECT_BYTES) {
            $value = substr($value, 0, self::MAX_SUBJECT_BYTES);
        }

        return (string) $this->store->decode_header($value, $charset);
    }

    /**
     * Whether an open handle is the regular file that the path itself names:
     * not a symbolic link someone put there, not another kind of file, not a
     * file with a second name.
     *
     * @return array|false stat() data of the file
     */
    protected function own_file($fp, $path)
    {
        clearstatcache(true, $path);
        $mine = fstat($fp);
        $there = @lstat($path);

        if (!$mine || !$there || $mine['ino'] !== $there['ino'] || $mine['dev'] !== $there['dev']
            || ($mine['mode'] & 0170000) !== 0100000 || $mine['nlink'] !== 1
        ) {
            return false;
        }

        return $mine;
    }

    protected function header_too_large($header)
    {
        return strlen($header) > self::MAX_HEADER_SIZE || substr_count($header, "\n") > self::MAX_HEADER_LINES;
    }

    protected function without_recent($flags)
    {
        return array_values(array_filter((array) $flags, function ($flag) {
            return strcasecmp($flag, '\\Recent') !== 0;
        }));
    }

    protected function has_flag($flags, $flag)
    {
        foreach ((array) $flags as $f) {
            if (strcasecmp($f, $flag) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a message read again is the one read before: same size and
     * same INTERNALDATE.
     */
    protected function same_message($before, $now)
    {
        return is_array($now)
            && (int) $now['size'] === (int) $before['size']
            && $this->timestamp($now['internaldate']) !== null
            && $this->timestamp($now['internaldate']) === $this->timestamp($before['internaldate']);
    }

    /**
     * Whether two lists hold the same flags and keywords (\Recent ignored).
     */
    protected function same_flags($a, $b)
    {
        $norm = function ($flags) {
            $flags = array_map('strtolower', $this->without_recent($flags));
            sort($flags);

            return array_values(array_unique($flags));
        };

        return $norm($a) === $norm($b);
    }

    protected function timestamp($date)
    {
        $ts = is_string($date) ? strtotime($date) : false;

        return $ts === false ? null : $ts;
    }

    /**
     * Take the account lock, so that one host never runs two replacements for
     * the same account at once. Besides preventing competing copies this
     * bounds the temporary-file budget to one message per account. Hosts that
     * do not share the temporary directory and two accounts that see the same
     * folder are not covered; the checks before step 9 narrow that window.
     *
     * The name of the lock file can be guessed, and PHP follows a symbolic
     * link whenever it opens a file, even one it is told to create. So the
     * name is looked at first: only a free name or a regular file is opened
     * at all, nothing that exists is changed, and after opening the handle
     * must be the file of that very name. What remains is the moment between
     * the look and the creation - for someone who may write to the temporary
     * directory, which belongs to the web server alone.
     *
     * @return array|string|null [handle, path, state]; ERR_BUSY if another
     *                           request holds the lock or the lock file
     *                           cannot be trusted, ERR_TEMP_FILE if the
     *                           filesystem cannot lock at all; NULL if the
     *                           temporary directory is unavailable (reported
     *                           later)
     */
    protected function lock($folder, $uid)
    {
        $dir = realpath($this->temp_dir);

        if (!$dir || !is_dir($dir) || !is_writable($dir)) {
            return null;
        }

        $path = $dir . '/' . $this->temp_prefix . '.' . sha1($this->scope) . '.lock';

        $fp = null;
        clearstatcache(true, $path);
        $there = @lstat($path);

        if (!$there) {
            // a free name: create the file, for the owner only
            $umask = umask(0077);
            $fp = @fopen($path, 'x');
            umask($umask);

            if (!$fp) {
                // another request was faster
                clearstatcache(true, $path);
                $there = @lstat($path);
            }
        }

        if (!$fp && $there && ($there['mode'] & 0170000) === 0100000) {
            // a regular file that is there already is opened as it is
            $fp = @fopen($path, 'r+');
        }

        if (!$fp) {
            $this->log("the lock file {$path} cannot be used, the message is not edited");

            return self::ERR_BUSY;
        }

        $would_block = 0;

        if (!$this->acquire_lock($fp, $would_block)) {
            fclose($fp);

            // another request holds the lock
            if ($would_block) {
                return self::ERR_BUSY;
            }

            // This filesystem cannot lock at all. Fail closed, and say why:
            // "another edit is running" would not be true.
            $this->log("the lock file {$path} cannot be locked (flock failed), the message is not edited");

            return self::ERR_TEMP_FILE;
        }

        // The request that held the lock before removes the file when it is
        // done; a handle on such a removed file locks nothing. And the handle
        // must be the file of that name itself, not the target of a link.
        if (!$this->own_file($fp, $path)) {
            fclose($fp);

            return self::ERR_BUSY;
        }

        // A file left behind by an aborted request is old: keep Roundcube
        // from clearing it away with its temporary files while it is in use
        @touch($path);

        $state = new stdClass();
        $state->path = $path;

        // remove the file even after a fatal error (time limit); "static":
        // the function must not keep this object and its message alive
        register_shutdown_function(static function () use ($state) {
            if ($state->path !== null) {
                @unlink($state->path);
            }
        });

        return [$fp, $path, $state];
    }

    /** Isolated so tests can simulate an unsupported or failing filesystem lock. */
    protected function acquire_lock($fp, &$would_block)
    {
        return flock($fp, LOCK_EX | LOCK_NB, $would_block);
    }

    protected function unlock($lock)
    {
        if (is_array($lock)) {
            $lock[2]->path = null;
            @unlink($lock[1]);
            flock($lock[0], LOCK_UN);
            fclose($lock[0]);
        }
    }

    /**
     * Create a private temporary file in the configured directory.
     */
    protected function create_temp_file()
    {
        $dir = realpath($this->temp_dir);

        if (!$dir || !is_dir($dir) || !is_writable($dir)) {
            $this->log("temp_dir {$this->temp_dir} is not a writable directory");

            return null;
        }

        // tempnam() creates the file for the owner only. It falls back to
        // the system temp dir and warns; avoid both.
        $path = @tempnam($dir, $this->temp_prefix);

        if (!$path || dirname($path) !== $dir) {
            $this->remove_temp_file($path);
            $this->log("could not create a temporary file in {$dir}");

            return null;
        }

        // remove the file even after a fatal error (memory, time limit)
        register_shutdown_function(static function () use ($path) {
            if (is_file($path)) {
                @unlink($path);
            }
        });

        return $path;
    }

    protected function remove_temp_file($path)
    {
        if ($path && is_file($path) && !unlink($path)) {
            $this->log("could not remove the temporary file {$path}");
        }
    }

    protected function log($message)
    {
        if ($this->logger) {
            call_user_func($this->logger, $message);
        }
    }

    protected function result($status, $code, $uid, $subject = null, $detail = null)
    {
        return [
            'status' => $status,
            'code' => $code,
            'uid' => $uid,
            'subject' => $subject,
            'detail' => $detail,
        ];
    }
}

/**
 * What the procedure needs from the mail store.
 */
interface edit_subject_store
{
    /** @return bool Whether exactly one UID can be expunged (UIDPLUS, on the connection in use) */
    public function has_uidplus();

    /** @return array|null Rights in the folder (one letter each), NULL if the server has no ACL */
    public function rights($folder);

    /** @return bool Whether the server announces RFC 4314 rights "t" and "e" */
    public function rfc4314_rights();

    /** @return bool|null Whether the folder is opened read-only, NULL if unknown */
    public function read_only($folder);

    /** @return array|null ['flags' => raw flags, 'internaldate' => string, 'size' => int], NULL if not found */
    public function message_info($folder, $uid);

    /** @return string|false Raw header block (BODY[HEADER]), cut after $max_bytes bytes if that is given */
    public function fetch_header($folder, $uid, $max_bytes = 0);

    /** Write the raw body (BODY[TEXT]) into the open file, never more than $max_bytes bytes if that is given */
    public function fetch_body($folder, $uid, $fp, $max_bytes = 0);

    /** @return int|bool|null New UID, true if stored without a usable APPENDUID, false if refused, NULL if not answered */
    public function append($folder, $path, $flags, $internaldate);

    /** @return bool Whether the server named the UID of the last append() for this very folder (UIDVALIDITY) */
    public function append_confirmed();

    /** @return bool As reported by the server, not necessarily the truth */
    public function delete($folder, $uid);

    /** @return bool */
    public function unflag_deleted($folder, $uid);

    /** @return bool|null Whether the UID exists in the folder, NULL if unknown */
    public function exists($folder, $uid);

    /** @return string Decoded header value; raw 8-bit text is read with $charset, if given */
    public function decode_header($value, $charset = null);

    /** @return string Last error reported by the server */
    public function last_error();
}
