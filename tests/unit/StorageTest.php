<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// The few names of Roundcube that edit_subject_storage touches. The values
// of the constants are those of rcube_imap_generic (1.6.21 and 1.7.6).
if (!class_exists('rcube_imap_generic')) {
    class rcube_imap_generic
    {
        public const ERROR_OK = 0;
        public const ERROR_NO = -1;
        public const ERROR_BAD = -2;
        public const ERROR_UNKNOWN = -4;
    }
}

/**
 * Unit tests for edit_subject_storage: how it reads FETCH responses and
 * what it asks Roundcube before it lets a message be expunged.
 *
 * Roundcube itself is replaced by two small stand-ins that answer with
 * prepared lines; the lines are in the form servers send them.
 */
final class StorageTest extends TestCase
{
    private const DATE = '22-Nov-2026 18:53:07 +0000';

    private StandInImap $imap;
    private edit_subject_storage $store;

    protected function setUp(): void
    {
        $this->imap = new StandInImap();
        $this->store = new edit_subject_storage($this->imap);
    }

    private function line(string $items, int $seq = 1): string
    {
        return "* {$seq} FETCH ({$items})";
    }

    // -- reading FETCH lines (audit M2) -----------------------------------------

    public function testOrdinaryAnswer(): void
    {
        $this->imap->conn->response = $this->line('UID 3 FLAGS (\\Seen $label1) INTERNALDATE "' . self::DATE . '" RFC822.SIZE 667');

        $this->assertSame(
            ['flags' => ['\\Seen', '$label1'], 'internaldate' => self::DATE, 'size' => 667],
            $this->store->message_info('INBOX', 3)
        );
        $this->assertTrue($this->store->exists('INBOX', 3));
        $this->assertFalse($this->store->exists('INBOX', 4));
        $this->assertNull($this->store->message_info('INBOX', 4));
    }

    public static function keywordsThatLookLikeItems(): array
    {
        $date = 'INTERNALDATE "' . self::DATE . '"';

        return [
            'size keyword, server order of Dovecot' => ["UID 3 FLAGS (RFC822.SIZE 5) {$date} RFC822.SIZE 667", ['RFC822.SIZE', '5']],
            'size keyword, flags first' => ["FLAGS (RFC822.SIZE 5 \\Seen) UID 3 {$date} RFC822.SIZE 667", ['RFC822.SIZE', '5', '\\Seen']],
            'uid keyword, flags first' => ["FLAGS (UID 999) UID 3 {$date} RFC822.SIZE 667", ['UID', '999']],
            'all item names as keywords' => ["FLAGS (UID 9 FLAGS INTERNALDATE NIL RFC822.SIZE 1) RFC822.SIZE 667 {$date} UID 3", ['UID', '9', 'FLAGS', 'INTERNALDATE', 'NIL', 'RFC822.SIZE', '1']],
            'with MODSEQ list' => ["UID 3 MODSEQ (4711) FLAGS (a[b a=b) {$date} RFC822.SIZE 667", ['a[b', 'a=b']],
            'no flags' => ["UID 3 FLAGS () {$date} RFC822.SIZE 667", []],
            'lower case item names' => ["uid 3 flags (\\Seen) internaldate \"" . self::DATE . '" rfc822.size 667', ['\\Seen']],
        ];
    }

    #[DataProvider('keywordsThatLookLikeItems')]
    public function testKeywordsAreNeverTakenForItems(string $items, array $flags): void
    {
        $this->imap->conn->response = $this->line($items);

        $this->assertSame(
            ['flags' => $flags, 'internaldate' => self::DATE, 'size' => 667],
            $this->store->message_info('INBOX', 3)
        );
        $this->assertTrue($this->store->exists('INBOX', 3));
    }

    public static function linesAboutOtherMessages(): array
    {
        return [
            'unsolicited flags without UID, keywords UID 123' => ['* 7 FETCH (FLAGS (\\Seen UID 123))'],
            'flags first, keywords UID 123, real UID 7' => ['* 7 FETCH (FLAGS (\\Seen UID 123) UID 7)'],
            'UID first, keywords UID 123' => ['* 7 FETCH (UID 7 FLAGS (\\Seen UID 123))'],
            'keyword $UID' => ['* 7 FETCH (FLAGS ($UID 123))'],
            'nested twice' => ['* 7 FETCH (FLAGS (x) MODSEQ ((UID 123)))'],
            'quoted string holding the text' => ['* 7 FETCH (UID 7 X-ITEM "UID 123")'],
            'no FETCH line' => ['* 123 EXISTS'],
            'UID is no number' => ['* 7 FETCH (UID 123x)'],
        ];
    }

    /**
     * After the original has been expunged, no line about another message
     * may make it look alive: the copy would be rolled back and both
     * versions would be gone.
     */
    #[DataProvider('linesAboutOtherMessages')]
    public function testGoneMessageIsNotSeenInOtherLines(string $line): void
    {
        $this->imap->conn->response = $line;

        $this->assertFalse($this->store->exists('INBOX', 123));
        $this->assertNull($this->store->message_info('INBOX', 123));
    }

    public static function linesNotUnderstood(): array
    {
        return [
            'list not closed' => ['* 7 FETCH (UID 123 FLAGS (\\Seen'],
            'text after the list' => ['* 7 FETCH (UID 7) UID 123'],
            'odd number of items' => ['* 7 FETCH (FLAGS (\\Seen) UID 123 UID)'],
            'item given twice' => ['* 7 FETCH (UID 7 UID 123)'],
        ];
    }

    /**
     * A line that is not understood is no information at all: the message
     * is neither there nor gone (second audit, test case 48). "Gone" would
     * let a copy be taken for removed, "there" would roll it back.
     */
    #[DataProvider('linesNotUnderstood')]
    public function testLineNotUnderstoodIsNoInformation(string $line): void
    {
        $this->imap->conn->response = $line;

        $this->assertNull($this->store->exists('INBOX', 123));
        $this->assertNull($this->store->message_info('INBOX', 123));

        // and it does not hide a line that is understood
        $this->imap->conn->response = $line . "\r\n* 9 FETCH (UID 123)";
        $this->assertTrue($this->store->exists('INBOX', 123));
    }

    public function testAnswerWithALiteralIsNotRead(): void
    {
        // Roundcube hands over a line with a literal in one piece, data
        // included. The data may be anything, here text of a message.
        $this->imap->conn->raw_response = "* 7 FETCH (UID 7 BODY[HEADER.FIELDS (SUBJECT)] {40}\r\n"
            . "Subject: x\r\n* 9 FETCH (UID 123)\r\n\r\n)";

        $this->assertSame([], edit_subject_storage::fetch_items($this->imap->conn->raw_response, $complete));
        $this->assertFalse($complete);
        $this->assertNull($this->store->exists('INBOX', 123), 'text inside a literal is no FETCH line');
        $this->assertNull($this->store->exists('INBOX', 7));
        $this->assertNull($this->store->message_info('INBOX', 123));

        // a literal announced where the response ends: the line is not closed
        $this->imap->conn->raw_response = '* 7 FETCH (UID 7 X-ITEM {12}';
        $this->assertNull($this->store->exists('INBOX', 7));
    }

    public static function flagLists(): array
    {
        return [
            'system flags and keywords' => ['\\Seen \\Answered $label1 $Forwarded Junk', true],
            'what Dovecot accepts as keywords' => ['x} } a&b a/b a#b a+b a:b a;b a<b a>b a?b a@b a^b a`b a|b a,b a.b a!b a$b a-b ~x a[b a=b +FLAGS - $ 0 NIL UID', true],
            'quoted, but an atom' => ['"Junk"', true],
            'quoted with a space' => ['\\Seen "a b"', false],
            'quoted with a parenthesis' => ['"x) y"', false],
            'empty string' => ['\\Seen ""', false],
            'a list inside' => ['\\Seen (nested)', false],
            'two backslashes' => ['\\\\Seen', false],
            'backslash inside' => ['"a\\\\b"', false],
            'closing bracket' => ['a]b', false],
            'wildcards' => ['\\*', false],
            '8-bit' => ["k\xC3\xA9y", false],
        ];
    }

    /**
     * Roundcube writes the flags into the APPEND command as they are; only
     * what RFC 3501 calls a flag may get there (test case 48).
     */
    #[DataProvider('flagLists')]
    public function testFlagsMustBeAtoms(string $flags, bool $usable): void
    {
        $this->imap->conn->response = $this->line("UID 3 FLAGS ({$flags}) INTERNALDATE \"" . self::DATE . '" RFC822.SIZE 667');

        $info = $this->store->message_info('INBOX', 3);

        $this->assertSame($usable, $info !== null);
        $this->assertTrue($this->store->exists('INBOX', 3), 'the message is there either way');
    }

    public function testTheRightLineIsFoundAmongOthers(): void
    {
        $this->imap->conn->response = implode("\r\n", [
            '* 7 FETCH (FLAGS (\\Seen UID 123))',
            '* 2 FETCH (UID 5 FLAGS (\\Answered) INTERNALDATE "' . self::DATE . '" RFC822.SIZE 100)',
            '* 9 FETCH (UID 123 FLAGS (\\Flagged) INTERNALDATE "' . self::DATE . '" RFC822.SIZE 4321)',
        ]);

        $this->assertSame(4321, $this->store->message_info('INBOX', 123)['size']);
        $this->assertSame(['\\Flagged'], $this->store->message_info('INBOX', 123)['flags']);
    }

    public function testIncompleteAnswerIsNoInformation(): void
    {
        $this->imap->conn->response = $this->line('UID 3 FLAGS (\\Seen) RFC822.SIZE 667');
        $this->assertNull($this->store->message_info('INBOX', 3), 'no INTERNALDATE');

        $this->imap->conn->response = $this->line('UID 3 FLAGS \\Seen INTERNALDATE "' . self::DATE . '" RFC822.SIZE 667 X Y');
        $this->assertNull($this->store->message_info('INBOX', 3), 'FLAGS is no list');

        $this->imap->conn->response = $this->line('UID 3 FLAGS (\\Seen) INTERNALDATE "' . self::DATE . '" RFC822.SIZE big');
        $this->assertNull($this->store->message_info('INBOX', 3), 'size is no number');
    }

    public function testFailedCommandIsUnknown(): void
    {
        $this->imap->conn->code = rcube_imap_generic::ERROR_NO;
        $this->imap->conn->response = $this->line('UID 3');

        $this->assertNull($this->store->exists('INBOX', 3));
        $this->assertNull($this->store->message_info('INBOX', 3));
    }

    // -- UIDPLUS (audit H1) --------------------------------------------------------

    public function testUidplusNeedsTheSessionAndTheConnection(): void
    {
        $this->assertTrue($this->store->has_uidplus());

        // imap_force_caps, or a capability that went away: Roundcube's
        // cached answer still says yes, the connection says no
        $this->imap->conn->capabilities = [];
        $this->assertFalse($this->store->has_uidplus());

        $this->imap->conn->capabilities = ['UIDPLUS'];
        $this->imap->session_capabilities = [];
        $this->assertFalse($this->store->has_uidplus());

        $this->imap->session_capabilities = ['UIDPLUS'];
        $this->imap->connected = false;
        $this->assertFalse($this->store->has_uidplus());
    }

    public function testNothingIsDeletedWithoutUidplusOnTheConnection(): void
    {
        $this->imap->conn->capabilities = [];

        $this->assertFalse($this->store->delete('INBOX', 5));
        $this->assertSame([], $this->imap->deleted, 'delete_message() of Roundcube is not called');

        $this->imap->conn->capabilities = ['UIDPLUS'];
        $this->assertTrue($this->store->delete('INBOX', 5));
        $this->assertSame([[5, 'INBOX']], $this->imap->deleted);
    }

    // -- the folder stays the same (audit N11) ------------------------------------

    public function testFolderCreatedAgainIsNotTouched(): void
    {
        $this->imap->conn->response = $this->line('UID 5');
        $this->assertTrue($this->store->exists('INBOX', 5));

        // same name, other UIDVALIDITY: UID 5 is another message now
        $this->imap->conn->uidvalidity = 1700000002;
        $this->imap->conn->selected = null;

        $this->assertNull($this->store->exists('INBOX', 5));
        $this->assertNull($this->store->message_info('INBOX', 5));
        $this->assertFalse($this->store->fetch_header('INBOX', 5));
        $this->assertFalse($this->store->delete('INBOX', 5));
        $this->assertSame([], $this->imap->deleted);
        $this->assertFalse($this->store->unflag_deleted('INBOX', 5));
        $this->assertSame([], $this->imap->unflagged, 'no flag of a message in the new folder is touched');
    }

    public function testUnflagInTheFolderAsItWas(): void
    {
        $this->assertTrue($this->store->unflag_deleted('INBOX', 5));
        $this->assertSame([[5, 'DELETED', 'INBOX']], $this->imap->unflagged);
    }

    // -- APPEND (audit N10, N11) ---------------------------------------------------

    public function testAppendReturnsTheUid(): void
    {
        $this->assertTrue($this->store->exists('INBOX', 1) !== null);
        $this->imap->save_result = '17';
        $this->imap->conn->result = '[APPENDUID 1700000001 17] Append completed.';

        $this->assertSame(17, $this->store->append('INBOX', '/nonexistent', ['\\Seen', '$label1'], self::DATE));
        $this->assertTrue($this->store->append_confirmed(), 'named for this folder: it is the copy');
        $this->assertSame(['\\Seen', '$label1'], $this->imap->saved[0]['flags']);
        $this->assertSame('22-Nov-2026 18:53:07 +0000', $this->imap->saved[0]['date']->format('d-M-Y H:i:s O'));
        $this->assertSame('\\Seen', $this->imap->saved[0]['map']['\\SEEN'], 'flags of the message map to themselves');
        $this->assertArrayNotHasKey('\\SEEN', $this->imap->conn->flags, 'the flag map is restored');
    }

    public function testAppendIntoAnotherIncarnationOfTheFolderHasNoUsableUid(): void
    {
        $this->store->exists('INBOX', 1);
        $this->imap->save_result = '17';
        $this->imap->conn->result = '[APPENDUID 1700000002 17] Append completed.';

        $this->assertTrue($this->store->append('INBOX', '/nonexistent', [], self::DATE));
        $this->assertFalse($this->store->append_confirmed());
    }

    public function testAppendUidWithoutItsAnswerIsNotConfirmed(): void
    {
        // Second audit, test case 46: another plugin has sent a command in
        // the message_saved hook, the answer of the APPEND is gone. The UID
        // is passed on, but nobody knows for which folder it was named.
        $this->store->exists('INBOX', 1);
        $this->imap->conn->result = '[APPENDUID 1700000001 17] Append completed.';
        $this->store->append('INBOX', '/nonexistent', [], self::DATE);
        $this->assertTrue($this->store->append_confirmed());

        $this->imap->save_result = '18';
        $this->imap->conn->result = 'NOOP completed.';

        $this->assertSame(18, $this->store->append('INBOX', '/nonexistent', [], self::DATE));
        $this->assertFalse($this->store->append_confirmed(), 'and the confirmation of an earlier append does not carry over');

        // an answer for another UID confirms nothing either
        $this->imap->conn->result = '[APPENDUID 1700000001 17] Append completed.';
        $this->assertSame(18, $this->store->append('INBOX', '/nonexistent', [], self::DATE));
        $this->assertFalse($this->store->append_confirmed());
    }

    public function testRefusedAppendIsAnError(): void
    {
        $this->imap->save_result = false;
        $this->imap->error_code = rcube_imap_generic::ERROR_NO;
        $this->assertFalse($this->store->append('INBOX', '/nonexistent', [], self::DATE));
    }

    public function testBadDuringAppendLeavesItOpen(): void
    {
        // Roundcube reports BAD for an untagged "* BAD" while the command
        // is running as well; the message may have been stored then
        $this->imap->save_result = false;
        $this->imap->error_code = rcube_imap_generic::ERROR_BAD;
        $this->assertNull($this->store->append('INBOX', '/nonexistent', [], self::DATE));
    }

    public function testAppendWithoutAnAnswerIsUnknown(): void
    {
        // connection lost or timed out after the message was sent
        $this->imap->save_result = false;
        $this->imap->error_code = rcube_imap_generic::ERROR_UNKNOWN;
        $this->assertNull($this->store->append('INBOX', '/nonexistent', [], self::DATE));

        // no error recorded at all: Roundcube gave up while sending
        $this->imap->error_code = rcube_imap_generic::ERROR_OK;
        $this->assertNull($this->store->append('INBOX', '/nonexistent', [], self::DATE));
    }

    public function testHeaderIsFetchedWithALimit(): void
    {
        $this->store->fetch_header('INBOX', 5, 4194305);
        $this->store->fetch_header('INBOX', 5);

        $this->assertSame([['HEADER', 4194305], ['HEADER', 0]], $this->imap->conn->parts);
    }

    public function testBodyIsFetchedWithALimitThatHoldsForTheFile(): void
    {
        // test case 52
        // The server ignores the partial fetch and sends 3 MB
        $this->imap->conn->body_literal = str_repeat('B', 3 * 1024 * 1024);
        $path = tempnam(sys_get_temp_dir(), 'es-cap');
        $fp = fopen($path, 'r+b');

        try {
            $this->store->fetch_body('INBOX', 5, $fp, 1000);
            fflush($fp);
            $this->assertSame(['TEXT', 1000], $this->imap->conn->parts[0], 'partial fetch asked for');
            $this->assertLessThanOrEqual(1000, fstat($fp)['size'], 'nothing beyond the limit in the file');

            // the limit ends with the fetch
            $this->assertSame(1, fwrite($fp, 'Z'));

            // without a limit everything arrives
            ftruncate($fp, 0);
            rewind($fp);
            $this->store->fetch_body('INBOX', 5, $fp);
            fflush($fp);
            $this->assertSame(3 * 1024 * 1024, fstat($fp)['size']);
            $this->assertSame(['TEXT', 0], $this->imap->conn->parts[1]);

            // a body that fits arrives completely
            $this->imap->conn->body_literal = str_repeat('C', 999);
            ftruncate($fp, 0);
            rewind($fp);
            $this->store->fetch_body('INBOX', 5, $fp, 1000);
            fflush($fp);
            $this->assertSame(999, fstat($fp)['size']);
        } finally {
            fclose($fp);
            unlink($path);
        }
    }

    public function testHeaderAfterALostConnectionIsNoHeader(): void
    {
        $this->assertSame("Subject: x\r\n\r\n", $this->store->fetch_header('INBOX', 5));

        // Roundcube returns what it had read when the connection broke
        $this->imap->alive = false;
        $this->assertFalse($this->store->fetch_header('INBOX', 5));
    }

    public function testReadOnlyFolder(): void
    {
        $this->assertFalse($this->store->read_only('INBOX'));

        $this->imap->conn->read_write = false;
        $this->imap->conn->selected = null;
        $this->assertTrue($this->store->read_only('INBOX'));
    }
}

/**
 * Stands in for rcube_imap.
 */
final class StandInImap
{
    public StandInConn $conn;
    public array $session_capabilities = ['UIDPLUS'];
    public bool $connected = true;
    public $save_result = '17';
    public int $error_code = 0;
    public array $saved = [];
    public array $deleted = [];
    public array $unflagged = [];
    public bool $alive = true;

    public function __construct()
    {
        $this->conn = new StandInConn();
    }

    public function is_connected()
    {
        return $this->alive;
    }

    public function unset_flag($uids, $flag, $folder = null)
    {
        $this->unflagged[] = [$uids, $flag, $folder];

        return true;
    }

    public function get_capability($name)
    {
        return in_array($name, $this->session_capabilities, true);
    }

    public function check_connection()
    {
        return $this->connected;
    }

    public function save_message($folder, &$message, $headers = '', $is_file = false, $flags = [], $date = null, $binary = false)
    {
        $this->saved[] = ['folder' => $folder, 'flags' => $flags, 'date' => $date, 'map' => $this->conn->flags];

        return $this->save_result;
    }

    public function delete_message($uids, $folder = null)
    {
        $this->deleted[] = [$uids, $folder];

        return true;
    }

    public function get_error_code()
    {
        return $this->error_code;
    }

    public function get_error_str()
    {
        return '';
    }
}

/**
 * Stands in for rcube_imap_generic.
 */
final class StandInConn
{
    public array $flags = ['SEEN' => '\\Seen', 'DELETED' => '\\Deleted'];
    public array $data = [];
    public $result = '';
    public $selected = null;
    public array $capabilities = ['UIDPLUS'];
    public int $uidvalidity = 1700000001;
    public bool $read_write = true;
    public string $response = '';
    public ?string $raw_response = null;    // handed over as it is, like a line with a literal
    public int $code = 0;
    public array $parts = [];
    public string $body_literal = '';       // what the server sends for BODY[TEXT]

    public function select($folder)
    {
        if ($this->selected !== $folder) {
            $this->data = ['UIDVALIDITY' => $this->uidvalidity, 'READ-WRITE' => $this->read_write];
            $this->selected = $folder;
        }

        return true;
    }

    public function getCapability($name)
    {
        return in_array($name, $this->capabilities, true);
    }

    /** Like rcube_imap_generic::execute() with a filter: matching lines only */
    public function execute($command, $arguments = [], $options = 0, $filter = null)
    {
        if ($this->raw_response !== null) {
            return [$this->code, $this->raw_response];
        }

        $lines = array_filter(explode("\r\n", $this->response), fn ($l) => !$filter || preg_match($filter, $l . "\r\n"));

        return [$this->code, implode("\r\n", $lines)];
    }

    public function handlePartBody($mailbox, $id, $is_uid = false, $part = '', $encoding = null, $print = null,
        $file = null, $formatted = false, $max_bytes = 0)
    {
        $this->parts[] = [$part, $max_bytes];

        if ($file) {
            // like Roundcube: the literal in chunks of 1 MB, stop at the
            // first write that fails; the server decides the length
            foreach (str_split($this->body_literal, 1024 * 1024) as $chunk) {
                if (@fwrite($file, $chunk) === false) {
                    return false;
                }
            }

            return true;
        }

        return "Subject: x\r\n\r\n";
    }
}
