<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for edit_subject_header (test cases 1-8 as listed in
 * CONTRIBUTING.md, plus edge cases). The decoders used for checking are independent of the
 * code under test.
 */
final class HeaderTest extends TestCase
{
    private const BASE = "Return-Path: <alice@example.test>\r\n"
        . "Received: from mx.example.test (mx.example.test [192.0.2.10])\r\n"
        . "\tby mail.example.test with ESMTPS id 4XyZ12abCd\r\n"
        . "\tfor <bob@example.test>; Tue, 05 Mar 2019 14:22:01 +0100\r\n"
        . "Message-ID: <simple-1@example.test>\r\n"
        . "Date: Tue, 05 Mar 2019 14:22:01 +0100\r\n"
        . "From: Alice Example <alice@example.test>\r\n"
        . "To: Bob Example <bob@example.test>\r\n"
        . "Subject: Quarterly report\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "\r\n";

    // -- test case 1 ------------------------------------------------------

    public function testAsciiReplacesAscii(): void
    {
        $new = edit_subject_header::rewrite(self::BASE, 'Annual report');

        $this->assertSame(str_replace('Subject: Quarterly report', 'Subject: Annual report', self::BASE), $new);
        $this->assertOthersUnchanged(self::BASE, $new);
    }

    // -- test case 2 ------------------------------------------------------

    public static function unicodeSubjects(): array
    {
        $mix = 'Grüße aus Köln 😀 Größenänderung 🎉 Ünïcödé € 日本語テキスト ';
        $two_hundred = rtrim(self::cut(str_repeat($mix, 10), 200));
        while (edit_subject_header::length($two_hundred) < 200) {
            $two_hundred .= 'ü';
        }

        return [
            'umlauts' => ['Grüße aus Köln'],
            'emoji' => ['Meeting 😀🎉👍🏽 tomorrow'],
            'emoji only' => [str_repeat('😀', 60)],
            '200 characters' => [$two_hundred],
            '500 characters, 4 byte each' => [str_repeat('🎉', 500)],
            'combining characters' => [str_repeat("e\u{0301}a\u{0308} ", 30) . 'x'],
            'ZWJ sequences' => [str_repeat('👨‍👩‍👧‍👦 ', 20) . 'end'],
            'ascii with encoded-word lookalike' => ['Look: =?UTF-8?B?SGFsbG8=?= is not decoded'],
            'leading non-ascii' => ['€uro'],
        ];
    }

    #[DataProvider('unicodeSubjects')]
    public function testUnicodeIsEncodedAndDecodesToTheInput(string $subject): void
    {
        $new = edit_subject_header::rewrite(self::BASE, $subject);
        $field = $this->onlySubjectField($new);

        $this->assertEncodingRules($field);
        $this->assertSame($subject, $this->decodeField($field));
        $this->assertSame($subject, $this->iconvDecodeField($field));
        $this->assertOthersUnchanged(self::BASE, $new);
    }

    public function testRandomSubjectsKeepAllRules(): void
    {
        mt_srand(20261122);
        $alphabet = array_merge(
            str_split('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 .,:;!?-_()[]<>@"\'/\\=?'),
            ['ä', 'ö', 'ü', 'ß', 'Ä', 'é', 'ñ', '€', '日', '本', 'テ', '😀', '🎉', '🏽', "\u{0301}", "\u{00A0}", 'ﬁ', 'Ω']
        );

        for ($n = 0; $n < 400; $n++) {
            $len = mt_rand(1, 500);
            $s = '';
            for ($i = 0; $i < $len; $i++) {
                $s .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }

            $clean = edit_subject_header::sanitize($s);
            $new = edit_subject_header::rewrite(self::BASE, $s);
            $field = $this->onlySubjectField($new);

            $this->assertEncodingRules($field);
            $this->assertSame($clean, $this->decodeField($field), "round trip of #$n");
            $this->assertOthersUnchanged(self::BASE, $new);
        }
    }

    /**
     * The rule checks above must be able to fail: feed them broken output.
     */
    public function testRuleChecksDetectBrokenEncoding(): void
    {
        $too_long = 'Subject: =?UTF-8?B?' . base64_encode(str_repeat('a', 60)) . "?=\r\n";
        $split_char = 'Subject: =?UTF-8?B?' . base64_encode("a\xC3") . "?=\r\n =?UTF-8?B?" . base64_encode("\xBCb") . "?=\r\n";
        $long_line = 'Subject: ' . str_repeat('x', 70) . ' =?UTF-8?B?w7w=?=' . "\r\n";
        $bare_lf = "Subject: =?UTF-8?B?w7w=?=\n =?UTF-8?B?w7w=?=\r\n";

        foreach (['too long' => $too_long, 'split char' => $split_char, 'long line' => $long_line, 'bare LF' => $bare_lf] as $case => $field) {
            try {
                $this->assertEncodingRules($field);
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                continue;
            }
            $this->fail("rule checks did not detect: $case");
        }

        $this->addToAssertionCount(1);
    }

    // -- test case 3 ------------------------------------------------------

    public function testFoldedMultilineEncodedOriginal(): void
    {
        $orig = $this->fixtureHeader('subject-folded-encoded.eml');
        $this->assertSame(1, edit_subject_header::field_count($orig, 'subject'));

        $new = edit_subject_header::rewrite($orig, 'Neuer Betreff');

        $this->assertStringContainsString("\r\nSubject: Neuer Betreff\r\nMIME-Version: 1.0\r\n", $new);
        $this->assertStringNotContainsString('gefaltet', $new);
        $this->assertStringNotContainsString('ISO-8859-1', $new);
        $this->assertOthersUnchanged($orig, $new);
    }

    // -- test case 4 ------------------------------------------------------

    public function testMissingSubjectIsAppendedAtTheEnd(): void
    {
        $orig = $this->fixtureHeader('no-subject.eml');
        $this->assertSame(0, edit_subject_header::field_count($orig, 'subject'));

        $new = edit_subject_header::rewrite($orig, 'Added subject');

        $this->assertSame(substr($orig, 0, -2) . "Subject: Added subject\r\n\r\n", $new);
        $this->assertOthersUnchanged($orig, $new);
    }

    public function testMissingSubjectWithoutTerminatingEmptyLine(): void
    {
        $orig = "From: a@example.test\r\nTo: b@example.test";
        $new = edit_subject_header::rewrite($orig, 'x');

        $this->assertSame("From: a@example.test\r\nTo: b@example.test\r\nSubject: x\r\n", $new);
    }

    // -- test case 5 ------------------------------------------------------

    public function testLastSubjectIsReplacedAndEarlierOnesAreRemoved(): void
    {
        // Roundcube shows the last of several Subject fields (measured with
        // 1.6.21 and 1.7.6), so that is the one the user edits
        $orig = $this->fixtureHeader('two-subjects.eml');
        $this->assertSame(2, edit_subject_header::field_count($orig, 'subject'));
        $this->assertSame('First subject line', edit_subject_header::field_value($orig, 'subject'));
        $this->assertSame('Second subject line', edit_subject_header::field_value($orig, 'subject', true));

        $new = edit_subject_header::rewrite($orig, 'Only subject');

        $this->assertSame(1, edit_subject_header::field_count($new, 'subject'));
        $expected = str_replace(
            ["Subject: First subject line\r\n", "Subject: Second subject line\r\n"],
            ['', "Subject: Only subject\r\n"],
            $orig
        );
        $this->assertSame($expected, $new);
        $this->assertOthersUnchanged($orig, $new);
    }

    public function testKeepOriginalTakesTheLastSubject(): void
    {
        $orig = $this->fixtureHeader('two-subjects.eml');

        $new = edit_subject_header::rewrite($orig, 'Only subject', true);

        $this->assertSame("X-Original-Subject: Second subject line\r\n", $this->rawField($new, 'x-original-subject'));
        $this->assertStringContainsString("Subject: Only subject\r\nX-Original-Subject: Second subject line\r\n", $new);
        $this->assertStringNotContainsString('First subject line', $new);
    }

    // -- test case 6 ------------------------------------------------------

    public static function injections(): array
    {
        return [
            'CRLF' => ["Hello\r\nBcc: x@example.test", 'Hello  Bcc: x@example.test'],
            'LF' => ["Hello\nBcc: x@example.test", 'Hello Bcc: x@example.test'],
            'CR' => ["Hello\rBcc: x@example.test", 'Hello Bcc: x@example.test'],
            'CRLF CRLF' => ["Hello\r\n\r\nBcc: x@example.test", 'Hello    Bcc: x@example.test'],
            'NUL' => ["Hello\0Bcc: x@example.test", 'Hello Bcc: x@example.test'],
            'TAB and DEL' => ["Hello\t\x7FBcc: x@example.test", 'Hello  Bcc: x@example.test'],
            'C1 NEL' => ["Hello\u{0085}Bcc: x@example.test", 'Hello Bcc: x@example.test'],
            'folding attempt' => ["Hello\r\n Bcc: x@example.test", 'Hello   Bcc: x@example.test'],
            'non-ascii' => ["Grüße\r\nBcc: x@example.test", 'Grüße  Bcc: x@example.test'],
        ];
    }

    #[DataProvider('injections')]
    public function testHeaderInjectionIsImpossible(string $input, string $expected): void
    {
        $new = edit_subject_header::rewrite(self::BASE, $input);

        $this->assertSame(0, edit_subject_header::field_count($new, 'bcc'));
        $this->assertCount(count($this->fields(self::BASE)), $this->fields($new));
        $this->assertSame($expected, $this->decodeField($this->onlySubjectField($new)));
        $this->assertOthersUnchanged(self::BASE, $new);
    }

    // -- test case 7 ------------------------------------------------------

    public function testEmptySubjectIsAllowed(): void
    {
        foreach (['', '   ', "\r\n", "\u{00A0}\t "] as $input) {
            $new = edit_subject_header::rewrite(self::BASE, $input);
            $this->assertSame(str_replace('Subject: Quarterly report', 'Subject:', self::BASE), $new);
        }
    }

    public function testLengthLimit(): void
    {
        $this->assertSame(500, edit_subject_header::length(edit_subject_header::sanitize(str_repeat('a', 500))));
        $this->assertSame(500, edit_subject_header::length(edit_subject_header::sanitize(str_repeat('ü', 500))));
        // trimming happens before counting
        $this->assertSame(500, edit_subject_header::length(edit_subject_header::sanitize('  ' . str_repeat('x', 500) . "\r\n")));

        foreach ([str_repeat('a', 501), str_repeat('😀', 501), str_repeat('a ', 300)] as $too_long) {
            try {
                edit_subject_header::rewrite(self::BASE, $too_long);
                $this->fail('too long subject accepted');
            } catch (InvalidArgumentException $e) {
                $this->assertSame(edit_subject_header::ERROR_TOO_LONG, $e->getCode());
            }
        }
    }

    // -- input limits (audit M3) ------------------------------------------------

    public function testRawInputAboveTheLimitIsTooLong(): void
    {
        $max = edit_subject_header::MAX_INPUT_BYTES;

        // up to the limit white space around the subject is simply removed
        $this->assertSame('x', edit_subject_header::sanitize(str_repeat(' ', $max - 1) . 'x'));
        $this->assertSame('x', edit_subject_header::sanitize('x' . str_repeat("\n", $max - 1)));

        foreach ([
            'one byte more' => str_repeat(' ', $max) . 'x',
            // PCRE gives up at more than 1000000 backtracks: the expression
            // returned NULL here and sanitize() passed that on
            'more white space than the backtrack limit' => 'x' . str_repeat(' ', 1000001) . 'y',
            'megabytes' => str_repeat('a', 5000000),
        ] as $case => $input) {
            try {
                $result = edit_subject_header::sanitize($input);
                $this->fail("$case: accepted, returned " . gettype($result));
            } catch (InvalidArgumentException $e) {
                $this->assertSame(edit_subject_header::ERROR_TOO_LONG, $e->getCode(), $case);
            }
        }
    }

    public function testNothingButAStringIsAccepted(): void
    {
        foreach ([null, 5, ['x'], false] as $input) {
            try {
                edit_subject_header::sanitize($input);
                $this->fail('accepted: ' . gettype($input));
            } catch (InvalidArgumentException $e) {
                $this->assertSame(edit_subject_header::ERROR_ENCODING, $e->getCode());
            }
        }
    }

    /**
     * Trimming with two anchored expressions gives exactly what the former
     * single expression gave.
     */
    public function testTrimmingMatchesTheReferenceExpression(): void
    {
        mt_srand(20261122);
        $spaces = [' ', "\t", "\r", "\n", "\u{00A0}", "\u{1680}", "\u{180E}", "\u{2003}", "\u{2028}", "\u{2029}", "\u{202F}", "\u{205F}", "\u{3000}", "\u{0085}"];
        $others = ['a', 'Z', '.', 'ä', '日', '😀', "\u{200B}", "\u{FEFF}", "\u{0301}", '=?', "\0"];
        $pick = fn (array $from, int $max) => implode('', array_map(fn () => $from[mt_rand(0, count($from) - 1)], array_fill(0, mt_rand(0, $max), 0)));

        for ($n = 0; $n < 3000; $n++) {
            $input = $pick($spaces, 6) . $pick(array_merge($others, $spaces), 30) . $pick($spaces, 6);
            $reference = preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', ' ', $input);
            $reference = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $reference);

            $this->assertSame($reference, edit_subject_header::sanitize($input), 'input #' . $n . ': ' . json_encode($input));
        }
    }

    /**
     * Without PCRE JIT the former expression took quadratic time for white
     * space in the middle (measured: 2.4 s for 16000 spaces, 38.7 s for
     * 64000). Run in a PHP process of its own, JIT cannot be switched off
     * for expressions that are compiled already.
     */
    public function testWhiteSpaceRunIsHandledInLinearTimeWithoutJit(): void
    {
        $code = 'require ' . var_export(realpath(__DIR__ . '/../../lib/edit_subject_header.php'), true) . ';'
            . '$in = "x" . str_repeat(" ", 16000) . "y"; $t = hrtime(true);'
            . 'try { edit_subject_header::sanitize($in); $r = "accepted"; }'
            . 'catch (InvalidArgumentException $e) { $r = "code " . $e->getCode(); }'
            . 'echo ini_get("pcre.jit"), "|", $r, "|", (hrtime(true) - $t) / 1e6;';

        exec(escapeshellarg(PHP_BINARY) . ' -d pcre.jit=0 -r ' . escapeshellarg($code), $out, $status);

        $this->assertSame(0, $status, implode("\n", $out));
        [$jit, $result, $ms] = explode('|', $out[0]);
        $this->assertSame('0', $jit, 'the child process runs without JIT');
        $this->assertSame('code ' . edit_subject_header::ERROR_TOO_LONG, $result);
        $this->assertLessThan(500, (float) $ms, 'milliseconds for 16002 bytes');
    }

    public function testInvalidUtf8IsRejected(): void
    {
        foreach (["\xC3", "abc\xFFdef", "\xED\xA0\x80"] as $input) {
            try {
                edit_subject_header::sanitize($input);
                $this->fail('invalid UTF-8 accepted');
            } catch (InvalidArgumentException $e) {
                $this->assertSame(edit_subject_header::ERROR_ENCODING, $e->getCode());
            }
        }
    }

    // -- test case 8 ------------------------------------------------------

    public function testOriginalSubjectIsKeptOnce(): void
    {
        $orig = $this->fixtureHeader('subject-folded-encoded.eml');
        $raw_value = substr($this->rawField($orig, 'subject'), strlen('Subject:'));

        $first = edit_subject_header::rewrite($orig, 'Erste Änderung', true);
        $this->assertSame(1, edit_subject_header::field_count($first, 'x-original-subject'));
        $this->assertSame('X-Original-Subject:' . $raw_value, $this->rawField($first, 'x-original-subject'));

        $second = edit_subject_header::rewrite($first, 'Zweite Änderung', true);
        $this->assertSame(1, edit_subject_header::field_count($second, 'x-original-subject'));
        $this->assertSame('X-Original-Subject:' . $raw_value, $this->rawField($second, 'x-original-subject'));
        $this->assertSame('Zweite Änderung', $this->decodeField($this->onlySubjectField($second)));

        // the field comes right after the new Subject field
        $this->assertMatchesRegularExpression('/\r\nSubject: [^\r]*\r\nX-Original-Subject:/', $first);
    }

    public function testVeryLongOriginalSubjectUsesBoundedMemory(): void
    {
        // Almost the engine's 4 MiB header limit. The old implementation
        // collected two million integers here and exceeded a 64 MiB limit.
        $orig = 'Subject: ' . str_repeat('a ', 2000000) . "\r\n\r\n";

        $new = edit_subject_header::rewrite($orig, 'New', true);

        $this->assertSame("Subject: New\r\n", $this->rawField($new, 'subject'));
        $this->assertSame(1, edit_subject_header::field_count($new, 'x-original-subject'));
    }

    public function testKeepOriginalWithoutOriginalSubject(): void
    {
        $orig = $this->fixtureHeader('no-subject.eml');

        $first = edit_subject_header::rewrite($orig, 'One', true);
        $this->assertSame("X-Original-Subject:\r\n", $this->rawField($first, 'x-original-subject'));

        $second = edit_subject_header::rewrite($first, 'Two', true);
        $this->assertSame("X-Original-Subject:\r\n", $this->rawField($second, 'x-original-subject'));
        $this->assertSame(1, edit_subject_header::field_count($second, 'x-original-subject'));
    }

    public function testKeepOriginalDisabledLeavesExistingFieldAlone(): void
    {
        $orig = "Subject: a\r\nX-Original-Subject: very first\r\n\r\n";
        $new = edit_subject_header::rewrite($orig, 'b', false);

        $this->assertSame("Subject: b\r\nX-Original-Subject: very first\r\n\r\n", $new);
    }

    // -- further edge cases -------------------------------------------------

    public function testOnlyTheHeaderBlockIsTouched(): void
    {
        $orig = "Subject: old\r\nFrom: a@example.test\r\n\r\nSubject: in the body\r\n\r\nmore\r\n";
        $new = edit_subject_header::rewrite($orig, 'new');

        $this->assertSame("Subject: new\r\nFrom: a@example.test\r\n\r\nSubject: in the body\r\n\r\nmore\r\n", $new);
    }

    public function testFieldNamesAreMatchedExactlyAndCaseInsensitive(): void
    {
        $orig = "Subject-Line: keep\r\nX-Subject: keep\r\nSUBJECT: old\r\nsubject : old too\r\n\r\n";
        $new = edit_subject_header::rewrite($orig, 'new');

        $this->assertSame("Subject-Line: keep\r\nX-Subject: keep\r\nSubject: new\r\n\r\n", $new);
    }

    public function testBareLineFeedsOfOtherFieldsArePreserved(): void
    {
        $orig = "From: a@example.test\nSubject: old\nTo: b@example.test\n\n";
        $new = edit_subject_header::rewrite($orig, 'new');

        $this->assertSame("From: a@example.test\nSubject: new\r\nTo: b@example.test\n\n", $new);
    }

    /**
     * Multiple spaces at every position around the folding boundary: folding
     * there would lose a space when a client trims continuation lines.
     */
    public function testMultipleSpacesNearTheFoldingBoundaryAreNeverSplit(): void
    {
        foreach (['  ', '   ', " \t ", "\t"] as $gap) {
            for ($n = 55; $n <= 95; $n++) {
                $subject = str_repeat('a', $n) . $gap . str_repeat('b', 12) . ' ' . str_repeat('c', 40);
                $clean = edit_subject_header::sanitize($subject);
                $field = $this->onlySubjectField(edit_subject_header::rewrite(self::BASE, $subject));
                $lines = explode("\r\n", rtrim($field, "\r\n"));

                foreach ($lines as $i => $line) {
                    $this->assertDoesNotMatchRegularExpression('/[ \t]$/', $line, "line ends with white space (n=$n)");
                    if ($i > 0) {
                        $this->assertMatchesRegularExpression('/^ [^ \t]/', $line, "continuation line (n=$n)");
                    }
                }

                $joined = preg_replace('/\n[\t\s]*/', ' ', implode("\n", array_map('trim', $lines)));
                $this->assertSame('Subject: ' . $clean, $joined, "Roundcube-style unfolding (n=$n)");
            }
        }
    }

    public function testLongAsciiIsFoldedAtSingleSpacesOnly(): void
    {
        $words = [];
        for ($i = 0; $i < 60; $i++) {
            $words[] = 'word' . $i . ($i % 7 == 0 ? '  double' : '');
        }
        $subject = implode(' ', $words);
        $this->assertLessThanOrEqual(500, strlen($subject));

        $field = $this->onlySubjectField(edit_subject_header::rewrite(self::BASE, $subject));
        $lines = explode("\r\n", rtrim($field, "\r\n"));

        $this->assertGreaterThan(1, count($lines));
        foreach ($lines as $i => $line) {
            $this->assertLessThanOrEqual(edit_subject_header::MAX_LINE, strlen($line));
            if ($i > 0) {
                $this->assertMatchesRegularExpression('/^ [^ ]/', $line);
            }
            $this->assertDoesNotMatchRegularExpression('/ $/', $line);
        }

        // plain text: unfolding by removing CRLF only gives back the input
        $this->assertSame('Subject: ' . $subject, str_replace("\r\n", '', rtrim($field, "\r\n")));
        // Roundcube's way (trim continuation lines, join with one space) as well
        $joined = preg_replace('/\n[\t\s]*/', ' ', implode("\n", array_map('trim', $lines)));
        $this->assertSame('Subject: ' . $subject, $joined);
    }

    public function testLongWordWithoutSpacesStaysBelowTheHardLimit(): void
    {
        $subject = str_repeat('x', 500);
        $field = $this->onlySubjectField(edit_subject_header::rewrite(self::BASE, $subject));

        $this->assertSame('Subject: ' . $subject . "\r\n", $field);
        $this->assertLessThanOrEqual(edit_subject_header::MAX_LINE_HARD, strlen($field) - 2);
    }

    public function testFieldValueHelper(): void
    {
        $orig = $this->fixtureHeader('subject-folded-encoded.eml');

        $this->assertSame(
            '=?UTF-8?Q?Gr=C3=BC=C3=9Fe_aus_K=C3=B6ln_und_eine_sehr_lange_Betreffz?='
            . ' =?UTF-8?Q?eile,_die_der_Absender_gefaltet_hat?='
            . "\t=?ISO-8859-1?Q?_=28mit_=E4lterem_Zeichensatz=29?=",
            edit_subject_header::field_value($orig, 'Subject')
        );
        $this->assertNull(edit_subject_header::field_value($orig, 'X-Missing'));
    }

    // -- helpers ------------------------------------------------------------

    private static function cut(string $s, int $chars): string
    {
        preg_match('/^.{0,' . $chars . '}/su', $s, $m);

        return $m[0];
    }

    private function fixtureHeader(string $name): string
    {
        $raw = file_get_contents(__DIR__ . '/../fixtures/' . $name);
        $raw = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $raw));
        $pos = strpos($raw, "\r\n\r\n");

        return substr($raw, 0, $pos + 4);
    }

    /** Fields of a header block (raw, with continuation lines), without the empty line */
    private function fields(string $block): array
    {
        $fields = [];
        foreach (preg_split('/(?<=\n)/', $block, -1, PREG_SPLIT_NO_EMPTY) as $line) {
            if ($line === "\r\n" || $line === "\n") {
                break;
            }
            if (($line[0] === ' ' || $line[0] === "\t") && $fields) {
                $fields[count($fields) - 1] .= $line;
            } else {
                $fields[] = $line;
            }
        }

        return $fields;
    }

    private function fieldName(string $field): string
    {
        return strtolower(trim(explode(':', $field, 2)[0]));
    }

    private function rawField(string $block, string $name): ?string
    {
        foreach ($this->fields($block) as $field) {
            if ($this->fieldName($field) === $name) {
                return $field;
            }
        }

        return null;
    }

    private function onlySubjectField(string $block): string
    {
        $subjects = array_values(array_filter($this->fields($block), fn ($f) => $this->fieldName($f) === 'subject'));
        $this->assertCount(1, $subjects, 'exactly one Subject field');

        return $subjects[0];
    }

    /** All fields except Subject/X-Original-Subject are byte-identical and in the same order */
    private function assertOthersUnchanged(string $orig, string $new): void
    {
        $filter = fn ($f) => !in_array($this->fieldName($f), ['subject', 'x-original-subject'], true);
        $this->assertSame(
            array_values(array_filter($this->fields($orig), $filter)),
            array_values(array_filter($this->fields($new), $filter))
        );

        // the rest after the header block is untouched as well
        $tail = fn ($b) => ($p = strpos($b, "\r\n\r\n")) === false ? '' : substr($b, $p + 2);
        $this->assertSame($tail($orig), $tail($new));
    }

    private function assertEncodingRules(string $field): void
    {
        $this->assertStringEndsWith("\r\n", $field);
        $body = substr($field, 0, -2);
        $this->assertStringNotContainsString("\n", str_replace("\r\n", '', $body), 'bare LF');
        $this->assertStringNotContainsString("\r", str_replace("\r\n", '', $body), 'bare CR');

        $lines = explode("\r\n", $body);
        $this->assertStringStartsWith('Subject:', $lines[0]);

        $decoded_parts = '';
        $has_words = false;
        foreach ($lines as $i => $line) {
            if ($i > 0) {
                $this->assertMatchesRegularExpression('/^[ \t]\S/', $line, 'continuation line');
            }
            $this->assertLessThanOrEqual(edit_subject_header::MAX_LINE, strlen($line));

            if (preg_match_all('/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/', $line, $m, PREG_SET_ORDER)) {
                $has_words = true;
                $this->assertLessThanOrEqual(edit_subject_header::MAX_ENCODED_LINE, strlen($line), 'line with encoded-words');
                foreach ($m as $word) {
                    $this->assertLessThanOrEqual(edit_subject_header::MAX_ENCODED_WORD, strlen($word[0]), 'encoded-word length');
                    $this->assertSame('UTF-8', strtoupper($word[1]));
                    $bytes = strtoupper($word[2]) === 'B' ? base64_decode($word[3], true) : quoted_printable_decode(str_replace('_', ' ', $word[3]));
                    $this->assertNotFalse($bytes);
                    $this->assertSame(1, preg_match('//u', $bytes), 'encoded-word holds whole characters');
                    $decoded_parts .= $bytes;
                }
            }
        }

        if ($has_words) {
            $this->assertSame($this->decodeField($field), $decoded_parts);
        }
    }

    /**
     * Minimal RFC 2047 decoder, written independently of the code under test:
     * unfold, decode encoded-words, ignore white space between adjacent
     * encoded-words.
     */
    private function decodeField(string $field): string
    {
        $value = substr($field, strpos($field, ':') + 1);
        $value = preg_replace('/\r\n(?=[ \t])/', '', rtrim($value, "\r\n"));
        $value = ltrim($value, " \t");

        preg_match_all('/(?<word>=\?(?<cs>[^?]+)\?(?<enc>[BbQq])\?(?<text>[^?]*)\?=)|(?<ws>[ \t]+)|(?<other>[^ \t]+)/', $value, $tokens, PREG_SET_ORDER);

        $out = '';
        $ws = '';
        $prev_word = false;
        foreach ($tokens as $t) {
            if (!empty($t['word'])) {
                $bytes = strtoupper($t['enc']) === 'B'
                    ? base64_decode($t['text'], true)
                    : quoted_printable_decode(str_replace('_', ' ', $t['text']));
                $text = iconv($t['cs'], 'UTF-8', $bytes);
                $out .= ($prev_word ? '' : $ws) . $text;
                $ws = '';
                $prev_word = true;
            } elseif (isset($t['ws']) && $t['ws'] !== '') {
                $ws .= $t['ws'];
            } else {
                $out .= $ws . $t['other'];
                $ws = '';
                $prev_word = false;
            }
        }

        return $out . $ws;
    }

    /** Second, independent decoder: PHP's iconv extension */
    private function iconvDecodeField(string $field): string
    {
        $value = trim(substr($field, strpos($field, ':') + 1), " \t\r\n");

        return iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    }

    // -- second audit (test case 43) ---------------------------------------------

    public static function contentTypes(): array
    {
        return [
            'plain' => ["Content-Type: text/plain; charset=UTF-8\r\n", 'UTF-8'],
            'quoted, folded, other case' => ["content-type: multipart/mixed;\r\n\tboundary=\"x\";\r\n CHARSET=\"iso-8859-15\"\r\n", 'iso-8859-15'],
            'spaces around the equals sign' => ["Content-Type: text/plain; charset = koi8-r\r\n", 'koi8-r'],
            'no charset' => ["Content-Type: text/plain\r\n", null],
            'no Content-Type' => ["Subject: charset=UTF-8\r\n", null],
            'the last field that names one counts' => ["Content-Type: text/plain; charset=UTF-8\r\nContent-Type: text/html; charset=windows-1252\r\nContent-Type: text/plain\r\n", 'windows-1252'],
            'body is not looked at' => ["Subject: x\r\n\r\nContent-Type: text/plain; charset=UTF-8\r\n", null],
            'continuation line of another field' => ["X-Test: a\r\n Content-Type: text/plain; charset=UTF-8\r\n", null],
        ];
    }

    #[DataProvider('contentTypes')]
    public function testCharsetOfTheMessage(string $header, ?string $charset): void
    {
        $this->assertSame($charset, edit_subject_header::charset($header . "\r\nbody"));
    }

    public function testAsciiCanBeForcedIntoEncodedWords(): void
    {
        $this->assertSame("Subject: Plain\r\n", edit_subject_header::subject_field('Plain'));
        $this->assertSame('Subject: =?UTF-8?B?' . base64_encode('Plain') . "?=\r\n", edit_subject_header::subject_field('Plain', true));
        $this->assertSame("Subject:\r\n", edit_subject_header::subject_field('', true), 'nothing to encode');

        $long = str_repeat('word ', 90);
        $field = edit_subject_header::subject_field(trim($long), true);
        $this->assertSame(trim($long), iconv_mime_decode(edit_subject_header::field_value($field, 'Subject'), 0, 'UTF-8'));
        foreach (explode("\r\n", rtrim($field, "\r\n")) as $line) {
            $this->assertLessThanOrEqual(76, strlen($line));
        }

        $this->assertSame(
            "From: a\r\nSubject: =?UTF-8?B?" . base64_encode('New') . "?=\r\nX-Original-Subject: Old\r\n\r\n",
            edit_subject_header::rewrite("From: a\r\nSubject: Old\r\n\r\n", 'New', true, true)
        );
    }
}
