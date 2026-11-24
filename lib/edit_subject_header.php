<?php

/**
 * Edit subject - header rewriting
 *
 * Pure functions on the raw header block of a message: header block and new
 * subject in, new header block out. No IMAP and no Roundcube classes are
 * used, so the logic can be tested on its own (tests/unit).
 *
 * Rules:
 * - only the header block up to the first empty line is touched
 * - the last Subject field (with its continuation lines) is replaced in
 *   place, earlier Subject fields are removed, a missing one is appended.
 *   The last one, because that is the subject Roundcube shows for a message
 *   with several Subject fields (measured with 1.6.21 and 1.7.6).
 * - all other header lines stay byte-exact and in the same order
 * - pure ASCII is written as is, everything else as UTF-8 encoded-words
 *   (RFC 2047): no encoded-word longer than 75 characters, no character
 *   split across two encoded-words, lines folded with CRLF SP
 *
 * @license GNU GPLv3+
 */
class edit_subject_header
{
    /** Maximum length of a subject in characters (Unicode code points) */
    const MAX_LENGTH = 500;

    /**
     * Longest raw input sanitize() looks at. A subject of MAX_LENGTH
     * characters has at most 2000 bytes; the rest is room for white space
     * around it. Bounds the work before any regular expression runs.
     */
    const MAX_INPUT_BYTES = 16384;

    /** RFC 2047: maximum length of one encoded-word */
    const MAX_ENCODED_WORD = 75;

    /** RFC 2047: maximum length of a line that contains encoded-words */
    const MAX_ENCODED_LINE = 76;

    /** RFC 5322: recommended maximum line length (without CRLF) */
    const MAX_LINE = 78;

    /** RFC 5322: hard maximum line length (without CRLF) */
    const MAX_LINE_HARD = 998;

    const ERROR_ENCODING = 1;
    const ERROR_TOO_LONG = 2;

    /**
     * Clean up a subject entered by the user.
     *
     * CR, LF, NUL and all other control characters become spaces, white
     * space at the beginning and the end is removed. This makes header
     * injection impossible.
     *
     * @param string $subject Raw user input (UTF-8)
     *
     * @return string Clean subject
     *
     * @throws InvalidArgumentException With code ERROR_ENCODING for invalid
     *                                  UTF-8 or ERROR_TOO_LONG for more than
     *                                  MAX_LENGTH characters (or more than
     *                                  MAX_INPUT_BYTES bytes of raw input)
     */
    public static function sanitize($subject)
    {
        if (!is_string($subject)) {
            throw new InvalidArgumentException('Subject is not a string', self::ERROR_ENCODING);
        }

        if (strlen($subject) > self::MAX_INPUT_BYTES) {
            throw new InvalidArgumentException('Subject is too long', self::ERROR_TOO_LONG);
        }

        if (!preg_match('//u', $subject)) {
            throw new InvalidArgumentException('Subject is not valid UTF-8', self::ERROR_ENCODING);
        }

        // C0 controls (including TAB, CR, LF, NUL), DEL and C1 controls
        $subject = preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', ' ', $subject);

        // White space at both ends, including Unicode spaces like NBSP. Two
        // expressions anchored at the start: a single one with "...$" is
        // tried again at every position of a white space run in the middle,
        // which takes quadratic time where PCRE runs without JIT.
        if (is_string($subject)) {
            $subject = preg_replace('/^[\s\p{Z}]+/u', '', $subject);
        }

        if (is_string($subject)) {
            $subject = preg_match('/^(?:.*[^\s\p{Z}])?/su', $subject, $m) ? $m[0] : null;
        }

        // preg_* return NULL or FALSE when PCRE gives up (backtrack limit)
        if (!is_string($subject)) {
            throw new InvalidArgumentException('Subject could not be processed', self::ERROR_ENCODING);
        }

        if (self::length($subject) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('Subject is too long', self::ERROR_TOO_LONG);
        }

        return $subject;
    }

    /**
     * Rewrite the Subject field of a raw header block.
     *
     * @param string $header_block  Raw header block, usually including the
     *                              empty line that ends it (IMAP BODY[HEADER])
     * @param string $subject       New subject (raw user input, will be sanitized)
     * @param bool   $keep_original Store the original Subject value as
     *                              X-Original-Subject unless that field exists
     * @param bool   $encoded       Write the subject as encoded-words even if
     *                              it is plain ASCII, see subject_field()
     *
     * @return string New header block
     *
     * @throws InvalidArgumentException See sanitize()
     */
    public static function rewrite($header_block, $subject, $keep_original = false, $encoded = false)
    {
        $subject = self::sanitize($subject);
        [$fields, $tail] = self::parse($header_block);

        $new_fields = [];
        $original = null;
        $have_x_original = false;

        foreach ($fields as $idx => $field) {
            if ($field['name'] === 'x-original-subject') {
                $have_x_original = true;
            } elseif ($field['name'] === 'subject') {
                $original = $idx;
            }
        }

        foreach ($fields as $idx => $field) {
            if ($field['name'] !== 'subject') {
                $new_fields[] = $field['raw'];
                continue;
            }

            // only the last Subject field is replaced, earlier ones are dropped
            if ($idx === $original) {
                $new_fields[] = self::subject_field($subject, $encoded);

                if ($keep_original && !$have_x_original) {
                    $new_fields[] = self::x_original_field($field);
                }
            }
        }

        if ($original === null) {
            // no Subject field: add it at the end of the header block
            if (($last = count($new_fields) - 1) >= 0 && substr($new_fields[$last], -1) !== "\n") {
                $new_fields[$last] .= "\r\n";
            }

            $new_fields[] = self::subject_field($subject, $encoded);

            if ($keep_original && !$have_x_original) {
                $new_fields[] = "X-Original-Subject:\r\n";
            }
        }

        return implode('', $new_fields) . $tail;
    }

    /**
     * Build a complete Subject field, including the final CRLF.
     *
     * @param string $subject Sanitized subject
     * @param bool   $encoded Write encoded-words even for plain ASCII. Needed
     *                        where raw header text would not be read as
     *                        ASCII: Roundcube reads it with the character set
     *                        of the message, which may be UTF-16 or UTF-7.
     *
     * @return string Header field
     */
    public static function subject_field($subject, $encoded = false)
    {
        if ($subject === '') {
            return "Subject:\r\n";
        }

        if (!$encoded && self::is_plain($subject)) {
            return self::fold_plain('Subject: ' . $subject) . "\r\n";
        }

        return 'Subject: ' . implode("\r\n ", self::encoded_words($subject, strlen('Subject: '))) . "\r\n";
    }

    /**
     * Character set named in the Content-Type field of a header block.
     *
     * Roundcube decodes raw 8-bit header text with this character set
     * (rcube_imap_generic::fetchHeaders() reads it with the same expression,
     * the last Content-Type field that names one counts).
     *
     * @param string $header_block Raw header block
     *
     * @return string|null Character set, NULL if none is named
     */
    public static function charset($header_block)
    {
        [$fields] = self::parse($header_block);
        $charset = null;

        foreach ($fields as $field) {
            if ($field['name'] === 'content-type'
                && preg_match('/charset\s*=\s*"?([a-z0-9\-\.\_]+)"?/i', substr($field['raw'], strpos($field['raw'], ':') + 1), $m)
            ) {
                $charset = $m[1];
            }
        }

        return $charset;
    }

    /**
     * Return the raw value of the first (or last) field with the given name,
     * unfolded (CRLF before white space removed) and without the field name.
     *
     * @param string $header_block Raw header block
     * @param string $name         Field name (case-insensitive)
     * @param bool   $last         Take the last field of that name
     *
     * @return string|null Field value, NULL if the field does not exist
     */
    public static function field_value($header_block, $name, $last = false)
    {
        [$fields] = self::parse($header_block);
        $name = strtolower($name);
        $found = null;

        foreach ($fields as $field) {
            if ($field['name'] === $name) {
                $found = $field['raw'];

                if (!$last) {
                    break;
                }
            }
        }

        if ($found === null) {
            return null;
        }

        $value = substr($found, strpos($found, ':') + 1);
        $value = preg_replace('/\r?\n(?=[ \t])/', '', $value);

        return trim((string) $value, " \t\r\n");
    }

    /**
     * Count the fields with the given name.
     *
     * @param string $header_block Raw header block
     * @param string $name         Field name (case-insensitive)
     *
     * @return int Number of fields
     */
    public static function field_count($header_block, $name)
    {
        [$fields] = self::parse($header_block);
        $name = strtolower($name);
        $count = 0;

        foreach ($fields as $field) {
            if ($field['name'] === $name) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Number of characters (Unicode code points) of a UTF-8 string.
     *
     * @param string $string UTF-8 string
     *
     * @return int Length
     */
    public static function length($string)
    {
        return preg_match_all('/./su', $string);
    }

    /**
     * Split a header block into fields and the rest (the empty line that
     * ends the block and everything after it).
     *
     * @param string $header_block Raw header block
     *
     * @return array [fields, tail], each field is ['name' => ..., 'raw' => ...]
     */
    protected static function parse($header_block)
    {
        $lines = preg_split('/(?<=\n)/', (string) $header_block, -1, PREG_SPLIT_NO_EMPTY);
        $fields = [];
        $tail = '';

        foreach ($lines as $idx => $line) {
            if ($line === "\r\n" || $line === "\n") {
                $tail = implode('', array_slice($lines, $idx));
                break;
            }

            if (($line[0] === ' ' || $line[0] === "\t") && !empty($fields)) {
                $fields[count($fields) - 1]['raw'] .= $line;
                continue;
            }

            $name = null;
            if (preg_match('/^([!-9;-~]+)[ \t]*:/', $line, $m)) {
                $name = strtolower($m[1]);
            }

            $fields[] = ['name' => $name, 'raw' => $line];
        }

        return [$fields, $tail];
    }

    /**
     * X-Original-Subject field with the unchanged value of a Subject field.
     */
    protected static function x_original_field($subject)
    {
        $raw = $subject['raw'];
        $value = substr($raw, strpos($raw, ':') + 1);

        if (substr($value, -1) !== "\n") {
            $value .= "\r\n";
        }

        $field = 'X-Original-Subject:' . $value;

        // The longer field name may push the first line over the hard limit;
        // fold it before existing white space if that happens.
        $eol = strpos($field, "\n");
        $first = rtrim(substr($field, 0, $eol), "\r");

        if (strlen($first) > self::MAX_LINE_HARD) {
            $pos = self::fold_position($first, self::MAX_LINE_HARD, strlen('X-Original-Subject: '));
            if ($pos !== null) {
                $field = substr($field, 0, $pos) . "\r\n" . substr($field, $pos);
            }
        }

        return $field;
    }

    /**
     * Whether a subject can be written without encoding.
     */
    protected static function is_plain($subject)
    {
        // printable US-ASCII, and nothing a decoder could take for an
        // encoded-word (the user input would not survive decoding then)
        return preg_match('/^[\x20-\x7E]*$/D', $subject) && strpos($subject, '=?') === false;
    }

    /**
     * Fold a plain ASCII field to lines of at most MAX_LINE characters where
     * possible. Folding happens only before a single space between two
     * non-space characters, so that unfolding - also Roundcube's own, which
     * trims continuation lines - gives back exactly the same text.
     */
    protected static function fold_plain($field)
    {
        $lines = [];
        $min = strlen('Subject: ');

        while (strlen($field) > self::MAX_LINE) {
            $pos = self::fold_position($field, self::MAX_LINE, $min);

            if ($pos === null) {
                // a long word: allow a longer line (always < MAX_LINE_HARD,
                // the subject is limited to MAX_LENGTH characters)
                $pos = self::fold_position($field, strlen($field) - 1, $min, true);
                if ($pos === null) {
                    break;
                }
            }

            $lines[] = substr($field, 0, $pos);
            $field = substr($field, $pos);
            $min = 1;
        }

        $lines[] = $field;

        return implode("\r\n", $lines);
    }

    /**
     * Find the offset of a space where a line can be folded: a single space
     * with non-space characters on both sides, at an offset <= $max and >= $min.
     * With $forward the first such space after $max is returned instead.
     */
    protected static function fold_position($line, $max, $min, $forward = false)
    {
        $len = strlen($line);
        $found = null;

        for ($i = max($min, 1); $i < $len - 1; $i++) {
            if ($line[$i] !== ' '
                || $line[$i - 1] === ' ' || $line[$i - 1] === "\t"
                || $line[$i + 1] === ' ' || $line[$i + 1] === "\t"
            ) {
                continue;
            }

            // The forward case permits one overlong word and therefore uses
            // the first candidate. The backward case needs only the last
            // candidate up to the limit; nothing beyond it is relevant.
            if ($forward) {
                return $i;
            }

            if ($i > $max) {
                break;
            }

            $found = $i;
        }

        return $found;
    }

    /**
     * Encode a UTF-8 string as a list of encoded-words ("B" encoding).
     *
     * Every word holds whole characters only. The first word is shorter, so
     * that the first line including the field name stays within the limit.
     *
     * @param string $subject UTF-8 string
     * @param int    $offset  Length of the field name part on the first line
     *
     * @return array Encoded-words
     */
    protected static function encoded_words($subject, $offset)
    {
        $words = [];
        $chars = preg_split('//u', $subject, -1, PREG_SPLIT_NO_EMPTY);
        $chunk = '';
        $overhead = strlen('=?UTF-8?B??=');

        // maximum number of payload bytes so that the base64 text fits
        $max_bytes = function ($word_limit) use ($overhead) {
            return intdiv(intdiv($word_limit - $overhead, 4) * 4, 4) * 3;
        };

        $limit = $max_bytes(min(self::MAX_ENCODED_WORD, self::MAX_ENCODED_LINE - $offset));

        foreach ($chars as $char) {
            if ($chunk !== '' && strlen($chunk) + strlen($char) > $limit) {
                $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
                // following lines start with a single space
                $limit = $max_bytes(min(self::MAX_ENCODED_WORD, self::MAX_ENCODED_LINE - 1));
            }

            $chunk .= $char;
        }

        if ($chunk !== '') {
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }

        return $words;
    }
}
