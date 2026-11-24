<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for edit_subject_engine against a simulated mail store.
 *
 * The store keeps one folder in memory and can be told to fail in the ways
 * real servers fail (measured with Dovecot 2.4.5: STORE \Deleted without
 * the "t" right and UID EXPUNGE without "e" answer OK and do nothing,
 * APPEND without "w" drops flags).
 */
final class EngineTest extends TestCase
{
    private const HEADER = "Message-ID: <m1@example.test>\r\n"
        . "Date: Tue, 05 Mar 2019 14:22:01 +0100\r\n"
        . "From: Alice <alice@example.test>\r\n"
        . "Subject: Old subject\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "\r\n";
    private const BODY = "Line one\r\nLine two with Ümlauts\r\n.\r\n";
    private const DATE = '05-Mar-2019 14:22:01 +0100';
    private const FLAGS = ['\\Seen', '\\Flagged', '\\Answered', '$Forwarded', '$label1'];

    private FakeStore $store;
    private string $tmp;
    private array $log = [];
    private int $uid;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/es-unit-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0700);
        $this->store = new FakeStore($this->tmp);
        $this->store->add(1, "Subject: neighbour\r\n\r\nx\r\n", ['\\Deleted'], '01-Jan-2020 00:00:00 +0000');
        $this->uid = $this->store->add(5, self::HEADER . self::BODY, array_merge(self::FLAGS, ['\\Recent']), self::DATE);
        $this->store->add(9, "Subject: other\r\n\r\ny\r\n", [], '02-Jan-2020 00:00:00 +0000');
        $this->store->next_uid = 10;
        $this->log = [];
    }

    protected function tearDown(): void
    {
        $this->assertSame([], glob($this->tmp . '/*'), 'no temporary files left');
        rmdir($this->tmp);
    }

    private function engine(): edit_subject_engine
    {
        return new edit_subject_engine($this->store, $this->tmp, 'RCMTEMPedit_subject', function ($msg) {
            $this->log[] = $msg;
        });
    }

    private function run_edit(string $subject = 'New subject', bool $keep = false, ?string $expected = null): array
    {
        return $this->engine()->run('INBOX', $this->uid, $subject, $keep, $expected);
    }

    /** Path of the account lock file the engine uses (scope ''). */
    private function lock_path(int $uid): string
    {
        return $this->tmp . '/RCMTEMPedit_subject.' . sha1('') . '.lock';
    }

    private function assertUntouched(): void
    {
        $this->assertSame([1, 5, 9], array_keys($this->store->messages));
        $this->assertSame(self::HEADER . self::BODY, $this->store->messages[5]['raw']);
        $this->assertSame(array_merge(self::FLAGS, ['\\Recent']), $this->store->messages[5]['flags']);
        $this->assertSame(['\\Deleted'], $this->store->messages[1]['flags']);
    }

    // -- success --------------------------------------------------------------

    public function testSuccessReplacesTheMessage(): void
    {
        $result = $this->run_edit('Neuer Betreff 😀');

        $this->assertSame('ok', $result['status']);
        $this->assertSame(10, $result['uid']);
        $this->assertSame([1, 9, 10], array_keys($this->store->messages));

        $copy = $this->store->messages[10];
        [$header, $body] = explode("\r\n\r\n", $copy['raw'], 2);
        $this->assertSame(self::BODY, $body);
        $this->assertSame('Neuer Betreff 😀', iconv_mime_decode(edit_subject_header::field_value($header . "\r\n\r\n", 'subject'), 0, 'UTF-8'));
        $this->assertSame(self::FLAGS, $copy['flags'], 'flags and keywords without \\Recent');
        $this->assertSame(self::DATE, $copy['internaldate']);
        $this->assertSame([], $this->log, 'nothing logged on success');
    }

    public function testStepsRunInTheRequiredOrder(): void
    {
        $this->run_edit();

        $writes = array_values(array_filter($this->store->calls, fn ($c) => in_array($c[0], ['append', 'delete', 'unflag_deleted'])));
        $this->assertSame([['append', null], ['delete', 5]], $writes, 'append first, then delete exactly the original');

        $names = array_column($this->store->calls, 0);
        $this->assertLessThan(array_search('append', $names), array_search('has_uidplus', $names));
        $this->assertLessThan(array_search('append', $names), array_search('rights', $names));
        $this->assertLessThan(array_search('append', $names), array_search('fetch_body', $names));

        // the copy is verified before the original is deleted
        $verify = array_search(['message_info', 10], $this->store->calls);
        $this->assertNotFalse($verify);
        $this->assertLessThan(array_search(['delete', 5], $this->store->calls), $verify);
    }

    public function testTempFileIsPrivateAndInTheTempDir(): void
    {
        $this->run_edit();

        $seen = $this->store->appended_files[0];
        $this->assertSame(realpath($this->tmp), $seen['dir']);
        $this->assertSame('0600', $seen['perms']);
        $this->assertStringStartsWith('RCMTEMPedit_subject', $seen['name']);
    }

    public function testKeepOriginalIsPassedOn(): void
    {
        $this->run_edit('x', true);

        $this->assertStringContainsString("X-Original-Subject: Old subject\r\n", $this->store->messages[10]['raw']);
    }

    public function testDeletedFlagOfTheOriginalIsKept(): void
    {
        $this->store->messages[5]['flags'][] = '\\Deleted';

        $result = $this->run_edit();

        $this->assertSame('ok', $result['status']);
        $this->assertContains('\\Deleted', $this->store->messages[10]['flags']);
    }

    // -- nothing happens --------------------------------------------------------

    public function testUnchangedSubjectDoesNothing(): void
    {
        foreach (['Old subject', '  Old subject  ', "Old subject\r\n"] as $same) {
            $result = $this->run_edit($same);
            $this->assertSame('unchanged', $result['status']);
            $this->assertSame(5, $result['uid']);
        }

        $this->assertSame([], $this->store->writes());
        $this->assertUntouched();
    }

    public function testInvalidInputIsRejectedBeforeTouchingTheServer(): void
    {
        $this->assertSame('errortoolong', $this->run_edit(str_repeat('x', 501))['code']);
        $this->assertSame('errorinvalidsubject', $this->run_edit("\xC3")['code']);
        $this->assertSame([], $this->store->calls);
    }

    public function testMissingMessage(): void
    {
        $result = $this->engine()->run('INBOX', 77, 'x');

        $this->assertSame('errornotfound', $result['code']);
        $this->assertSame([], $this->store->writes());
    }

    public function testWithoutUidplusNothingIsWritten(): void
    {
        $this->store->uidplus = false;

        $result = $this->run_edit();

        $this->assertSame('errornouidplus', $result['code']);
        $this->assertSame([], $this->store->writes());
        $this->assertUntouched();
        $this->assertCount(1, $this->log);
    }

    public static function missingRights(): array
    {
        return [
            'no insert' => ['lrswtek', true, 'i'],
            'no delete flag' => ['lrswiek', true, 't'],
            'no expunge' => ['lrswitk', true, 'e'],
            'no expunge, obsolete d reported' => ['lrswitdk', true, 'e'],
            'no seen' => ['lrwitek', true, 's'],
            'no write (keywords present)' => ['lrsitek', true, 'w'],
            'RFC 2086 server without d' => ['lrswi', false, 'd'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missingRights')]
    public function testMissingRightsAbortBeforeWriting(string $rights, bool $rfc4314, string $missing): void
    {
        $this->store->rights = str_split($rights);
        $this->store->rfc4314 = $rfc4314;

        $result = $this->run_edit();

        $this->assertSame('errornorights', $result['code']);
        $this->assertStringContainsString($missing, $result['detail']);
        $this->assertSame([], $this->store->writes());
        $this->assertUntouched();
    }

    public function testRightsAreEnoughWhenComplete(): void
    {
        $this->store->rights = str_split('lrswitek');
        $this->assertSame('ok', $this->run_edit()['status']);

        $this->setUp();
        $this->store->rights = str_split('lrswid');
        $this->store->rfc4314 = false;
        $this->assertSame('ok', $this->run_edit()['status']);
    }

    public function testWithoutFlagsNoWriteRightIsNeeded(): void
    {
        $this->store->messages[5]['flags'] = [];
        $this->store->rights = str_split('lritek');

        $this->assertSame('ok', $this->run_edit()['status']);
    }

    public function testTempDirNotWritable(): void
    {
        $engine = new edit_subject_engine($this->store, $this->tmp . '/missing', 'RCMTEMPedit_subject', fn ($m) => $this->log[] = $m);

        $result = $engine->run('INBOX', 5, 'x');

        $this->assertSame('errortempfile', $result['code']);
        $this->assertSame([], $this->store->writes());
    }

    public function testShortBodyFetchAbortsBeforeAppend(): void
    {
        $this->store->short_body = true;

        $result = $this->run_edit();

        $this->assertSame('errorfetch', $result['code']);
        $this->assertSame([], $this->store->writes());
        $this->assertUntouched();
    }

    public function testShortStoredBodyFetchFailsVerification(): void
    {
        $this->store->short_body_uids = [10];

        $result = $this->run_edit();

        $this->assertSame('errorverify', $result['code']);
        $this->assertUntouched();
        $this->assertStringContainsString('body differs', implode("\n", $this->log));
    }

    // -- APPEND fails (test case 18) -------------------------------------------

    public function testFailingAppendLeavesTheOriginal(): void
    {
        $this->store->append_result = false;

        $result = $this->run_edit();

        $this->assertSame('errorappend', $result['code']);
        $this->assertSame([['append', null]], $this->store->writes());
        $this->assertUntouched();
        $this->assertStringContainsString('Quota exceeded', $result['detail']);
    }

    public function testAppendWithoutUidKeepsBoth(): void
    {
        $this->store->append_result = true;

        $result = $this->run_edit();

        $this->assertSame('errorbothexist', $result['code']);
        $this->assertNotContains(['delete', 5], $this->store->calls, 'original not deleted');
        $this->assertArrayHasKey(5, $this->store->messages);
    }

    // -- verification (step 8) -------------------------------------------------

    public static function brokenCopies(): array
    {
        return [
            'changed bytes' => ['corrupt', 'size'],
            'same-length body change' => ['corrupt_body', 'body differs'],
            'flags dropped' => ['drop_flags', 'flag'],
            'date changed' => ['change_date', 'INTERNALDATE'],
            'header rewritten by server' => ['rewrite_header', 'header block'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenCopies')]
    public function testBrokenCopyIsRemovedAndOriginalKept(string $defect, string $logged): void
    {
        $this->store->defect = $defect;

        $result = $this->run_edit();

        $this->assertSame('errorverify', $result['code']);
        $this->assertUntouched();
        $this->assertNotContains(['delete', 5], $this->store->calls);
        $this->assertStringContainsString($logged, implode("\n", $this->log));
    }

    public function testBrokenCopyThatCannotBeRemoved(): void
    {
        $this->store->defect = 'corrupt';
        $this->store->refuse_delete = [10];

        $result = $this->run_edit();

        $this->assertSame('errorbothexist', $result['code']);
        $this->assertSame([1, 5, 9, 10], array_keys($this->store->messages));
        $this->assertNotContains('\\Deleted', $this->store->messages[10]['flags']);
    }

    public function testUnexpectedCopyFlagIsRefused(): void
    {
        $this->store->after_append = function (FakeStore $store) {
            $store->messages[10]['flags'][] = '\\Deleted';
        };

        $result = $this->run_edit();

        $this->assertSame('errorverify', $result['code']);
        $this->assertUntouched();
        $this->assertStringContainsString('unexpected flags', implode("\n", $this->log));
    }

    public function testCopyFlagChangedAfterVerificationIsRefused(): void
    {
        // test case 51
        $reads = 0;
        $this->store->before['message_info'] = function (FakeStore $store, array $args) use (&$reads) {
            if ($args[1] === 5 && ++$reads === 2) {
                $store->messages[10]['flags'][] = '\\Deleted';
            }
        };

        $result = $this->run_edit();

        $this->assertSame('errorcopychanged', $result['code']);
        $this->assertUntouched();
        $this->assertStringContainsString('changed after verification', implode("\n", $this->log));
    }

    public function testBrokenCopyIsTakenBackAfterTheOriginalWasRemoved(): void
    {
        // test case 51
        // another client deletes the original, the copy is broken: the
        // deletion stands, and the message does not claim "unchanged"
        $this->store->defect = 'corrupt_body';
        $this->store->after_append = function (FakeStore $store) {
            unset($store->messages[5]);
        };

        $result = $this->run_edit();

        $this->assertSame('errorchanged', $result['code']);
        $this->assertSame([1, 9], array_keys($this->store->messages));
        $this->assertContains(['delete', 10], $this->store->calls);
    }

    public function testBrokenCopyIsTakenBackWhenTheOriginalWasFlaggedMeanwhile(): void
    {
        // test case 51
        $this->store->defect = 'corrupt_body';
        $this->store->after_append = function (FakeStore $store) {
            $store->messages[5]['flags'][] = '\\Deleted';
        };

        $result = $this->run_edit();

        $this->assertSame('errorchanged', $result['code']);
        $this->assertSame([1, 5, 9], array_keys($this->store->messages));
        $this->assertContains('\\Deleted', $this->store->messages[5]['flags'], 'the flag of the other client stays');
    }

    // -- deleting the original fails (test case 20) ------------------------------

    public function testRefusedDeleteRollsBackTheCopy(): void
    {
        $this->store->refuse_delete = [5];

        $result = $this->run_edit();

        $this->assertSame('errordelete', $result['code']);
        $this->assertSame(5, $result['uid']);
        $this->assertUntouched();
    }

    public function testExpungeWithoutEffectRollsBackAndClearsTheFlag(): void
    {
        // STORE \Deleted works, UID EXPUNGE answers OK and does nothing
        $this->store->noop_expunge = [5];

        $result = $this->run_edit();

        $this->assertSame('errordelete', $result['code']);
        $this->assertUntouched();
        $this->assertContains(['unflag_deleted', 5], $this->store->calls);
    }

    public function testNeitherOriginalNorCopyCanBeRemoved(): void
    {
        $this->store->noop_expunge = [5, 10];

        $result = $this->run_edit();

        $this->assertSame('errorbothexist', $result['code']);
        $this->assertSame([1, 5, 9, 10], array_keys($this->store->messages));
        $this->assertNotContains('\\Deleted', $this->store->messages[5]['flags'], 'original flag restored');
        $this->assertNotContains('\\Deleted', $this->store->messages[10]['flags'], 'copy not left flagged');
    }

    public function testUnknownStateAfterDeleteKeepsTheCopy(): void
    {
        $this->store->before['delete'] = function (FakeStore $store) {
            $store->exists_unknown_after_delete = true;
        };

        $result = $this->run_edit();

        $this->assertSame('erroruncertain', $result['code']);
        $this->assertArrayHasKey(10, $this->store->messages, 'the copy must never be removed blindly');
        $this->assertNotContains(['delete', 10], $this->store->calls);
    }

    public function testReportedDeleteFailureButOriginalGoneIsSuccess(): void
    {
        $this->store->delete_reports_false = true;

        $result = $this->run_edit();

        $this->assertSame('ok', $result['status']);
        $this->assertSame([1, 9, 10], array_keys($this->store->messages));
    }

    public function testNeighboursFlaggedDeletedSurvive(): void
    {
        $this->run_edit();

        $this->assertArrayHasKey(1, $this->store->messages);
        $this->assertSame(['\\Deleted'], $this->store->messages[1]['flags']);
    }

    // -- the message the user looked at (audit M1, N1, N3) ----------------------

    public function testExpectedSubjectMustMatch(): void
    {
        $result = $this->run_edit('New subject', false, 'Some other subject');

        $this->assertSame('errorchanged', $result['code']);
        $this->assertSame([], $this->store->writes());
        $this->assertUntouched();

        $this->assertSame('ok', $this->run_edit('New subject', false, 'Old subject')['status']);
    }

    public function testOriginalRemovedDuringTheEditTakesTheCopyBack(): void
    {
        // test case 50
        // another client deletes (or moves) the original after the copy was
        // stored: that action stands, the copy must not bring it back
        $this->store->after_append = function (FakeStore $store) {
            unset($store->messages[5]);
        };

        $result = $this->run_edit();

        $this->assertSame('errorchanged', $result['code']);
        $this->assertSame([1, 9], array_keys($this->store->messages), 'no copy stays behind');
        $this->assertNotContains(['delete', 5], $this->store->calls);
    }

    public function testSecondEditElsewhereLeavesOneVersion(): void
    {
        // test case 50
        // A second edit of the same message runs where the lock does not
        // reach (another host, another account on a shared folder) and
        // replaces the original first. Only one of the two may succeed.
        $this->store->after_append = function (FakeStore $store) {
            $store->after_append = null;
            $other = new edit_subject_engine($store, $this->tmp, 'RCMTEMPother', null, 'other@host');
            $this->assertSame('ok', $other->run('INBOX', 5, 'Subject of the other edit')['status']);
        };

        $result = $this->run_edit();

        $this->assertSame('errorchanged', $result['code']);
        $this->assertSame([1, 9, 11], array_keys($this->store->messages), 'exactly one version, that of the other edit');
        $this->assertStringContainsString('Subject: Subject of the other edit', $this->store->messages[11]['raw']);
    }

    public function testFlagsChangedDuringTheEditAreNotOverwritten(): void
    {
        // another client flags the original after its flags were read
        $this->store->after_append = function (FakeStore $store) {
            $store->messages[5]['flags'][] = 'ImportantLater';
        };

        $result = $this->run_edit();

        $this->assertSame('errorchanged', $result['code']);
        $this->assertSame([1, 5, 9], array_keys($this->store->messages));
        $this->assertContains('ImportantLater', $this->store->messages[5]['flags']);
        $this->assertNotContains(['delete', 5], $this->store->calls);
    }

    public function testRecentFlagGoingAwayIsNoChange(): void
    {
        $this->store->after_append = function (FakeStore $store) {
            $store->messages[5]['flags'] = array_values(array_diff($store->messages[5]['flags'], ['\\Recent']));
        };

        $this->assertSame('ok', $this->run_edit()['status']);
    }

    public function testOriginalFlaggedForDeletionMeanwhileTakesTheCopyBack(): void
    {
        // test case 50
        // another client is deleting the original: the copy would bring the
        // message back after its expunge
        $this->store->after_append = function (FakeStore $store) {
            $store->messages[5]['flags'][] = '\\Deleted';
        };

        $result = $this->run_edit();

        $this->assertSame('errorchanged', $result['code']);
        $this->assertSame([1, 5, 9], array_keys($this->store->messages));
        $this->assertContains('\\Deleted', $this->store->messages[5]['flags'], 'the flag of the other client stays');
        $this->assertNotContains(['delete', 5], $this->store->calls);
    }

    public function testOriginalAndCopyRemovedMeanwhileStayRemoved(): void
    {
        // test case 50
        $reads = 0;
        $this->store->before['message_info'] = function (FakeStore $store, array $args) use (&$reads) {
            if ($args[1] === 5 && ++$reads === 2) {
                unset($store->messages[5]);
                $store->messages[10]['flags'][] = '\\Deleted';
            }
        };

        $result = $this->run_edit();

        $this->assertSame('errorchanged', $result['code']);
        $this->assertSame([1, 9], array_keys($this->store->messages));
    }

    public function testCopyFlagChangedByTheDeleteHookIsNoSuccess(): void
    {
        $this->store->before['delete'] = function (FakeStore $store, array $args) {
            if ($args[1] === 5) {
                $store->messages[10]['flags'][] = '\\Deleted';
            }
        };

        $result = $this->run_edit();

        $this->assertSame('erroruncertain', $result['code']);
        $this->assertSame([1, 9, 10], array_keys($this->store->messages));
    }

    public function testOriginalAndCopyNotFoundAfterwardsIsUnknown(): void
    {
        // test case 51
        // "not found" proves nothing for a copy this connection has never
        // seen: it may only be invisible so far
        $this->store->after_append = function (FakeStore $store) {
            unset($store->messages[5], $store->messages[10]);
        };

        $result = $this->run_edit();

        $this->assertSame('errorunknown', $result['code']);
        $this->assertSame([1, 9], array_keys($this->store->messages));
    }

    public function testInvisibleCopyIsTakenBackByItsConfirmedUid(): void
    {
        // test case 51
        $this->store->invisible = [10];
        $this->store->after_append = function (FakeStore $store) {
            unset($store->messages[5]);
        };

        $result = $this->run_edit();

        $this->assertSame('errorchanged', $result['code']);
        $this->assertSame([1, 9], array_keys($this->store->messages));
    }

    public function testLockedAccountIsBusyForEveryMessage(): void
    {
        $other = fopen($this->lock_path(5), 'c');
        $this->assertTrue(flock($other, LOCK_EX | LOCK_NB));

        $result = $this->run_edit();

        $this->assertSame('errorbusy', $result['code']);
        $this->assertSame([], $this->store->calls, 'the store is not touched while another request works');

        // One replacement per account also bounds temporary-file use.
        $this->assertSame('errorbusy', $this->engine()->run('INBOX', 9, 'Other, edited')['code']);
        $this->assertSame([], $this->store->calls);

        flock($other, LOCK_UN);
        fclose($other);
        unlink($this->lock_path(5));

        $this->assertSame('ok', $this->run_edit()['status']);
        $this->assertSame('ok', $this->engine()->run('INBOX', 9, 'Other, edited')['status']);
    }

    public function testFilesystemLockFailureFailsClosed(): void
    {
        // test case 52
        $engine = new LockFailureEngine($this->store, $this->tmp, 'RCMTEMPedit_subject', fn ($m) => $this->log[] = $m);

        $result = $engine->run('INBOX', 5, 'New subject');

        // not "another edit is running": that would not be true
        $this->assertSame('errortempfile', $result['code']);
        $this->assertStringContainsString('flock failed', implode("\n", $this->log));
        $this->assertSame([], $this->store->calls, 'the store is not touched without a lock');
        $this->assertFileExists($this->lock_path(5), 'a failed lock does not delete the lock file');
        unlink($this->lock_path(5));
    }

    public function testLockFileExistsOnlyWhileTheMessageIsReplaced(): void
    {
        $path = $this->lock_path(5);
        $seen = null;
        $this->store->after_append = function () use ($path, &$seen) {
            $seen = file_exists($path);
        };

        $this->assertSame('ok', $this->run_edit()['status']);
        $this->assertTrue($seen, 'the lock file exists while the message is replaced');
        $this->assertFileDoesNotExist($path);
    }

    // -- loading the subject for the dialog -------------------------------------

    public function testLoadReturnsTheDecodedSubject(): void
    {
        $result = $this->engine()->load('INBOX', 5);
        $this->assertSame(['ok', null, 5, 'Old subject'], [$result['status'], $result['code'], $result['uid'], $result['subject']]);

        $this->assertSame('errornotfound', $this->engine()->load('INBOX', 77)['code']);
        $this->assertSame([], $this->store->writes());
    }

    public function testTheLastOfSeveralSubjectFieldsCounts(): void
    {
        // Roundcube shows the last Subject field (measured with 1.6.21 and 1.7.6)
        $this->store->add(20, "Subject: First\r\nFrom: a@example.test\r\nSubject: Last\r\n\r\nbody\r\n", [], self::DATE);
        $this->store->next_uid = 21;

        $this->assertSame('Last', $this->engine()->load('INBOX', 20)['subject']);
        $this->assertSame('unchanged', $this->engine()->run('INBOX', 20, 'Last')['status']);
        $this->assertSame('errorchanged', $this->engine()->run('INBOX', 20, 'x', false, 'First')['code']);

        $result = $this->engine()->run('INBOX', 20, 'Only', true, 'Last');
        $this->assertSame('ok', $result['status']);
        $this->assertSame("From: a@example.test\r\nSubject: Only\r\nX-Original-Subject: Last\r\n\r\nbody\r\n", $this->store->messages[21]['raw']);
    }

    // -- header and message limits ----------------------------------------------

    public function testMessageOverTheSizeLimitIsRefusedBeforeFetching(): void
    {
        $limit = strlen(self::HEADER . self::BODY) - 1;
        $engine = new edit_subject_engine($this->store, $this->tmp, 'RCMTEMPedit_subject',
            fn ($m) => $this->log[] = $m, '', $limit);

        $loaded = $engine->load('INBOX', 5);
        $result = $engine->run('INBOX', 5, 'New subject');

        $this->assertSame('errormessagetoolarge', $loaded['code']);
        $this->assertSame($limit, $loaded['detail']);
        $this->assertSame('errormessagetoolarge', $result['code']);
        $this->assertSame($limit, $result['detail']);
        $this->assertSame([['message_info', 5], ['message_info', 5]], $this->store->calls, 'no header or body is fetched');
        $this->assertUntouched();
    }

    public function testMessageAtTheSizeLimitIsAccepted(): void
    {
        $limit = strlen(self::HEADER . self::BODY);
        $engine = new edit_subject_engine($this->store, $this->tmp, 'RCMTEMPedit_subject',
            fn ($m) => $this->log[] = $m, '', $limit);

        $this->assertSame('ok', $engine->load('INBOX', 5)['status']);
        $this->assertSame('ok', $engine->run('INBOX', 5, 'New subject')['status']);
    }

    public function testBodyFetchesAreLimitedToOneByteMoreThanExpected(): void
    {
        // test case 52
        $this->assertSame('ok', $this->run_edit()['status']);

        $limit = strlen(self::BODY) + 1;
        $this->assertSame([$limit, $limit], $this->store->body_limits, 'original and copy');
    }

    public function testBodyLongerThanAnnouncedNeverFillsTheTemporaryFile(): void
    {
        // test case 52
        // A server, maybe one the user chose at login, sends far more than
        // RFC822.SIZE announced. Only one byte more reaches the file.
        $this->store->extra_body[5] = str_repeat('X', 1 << 20);

        $result = $this->run_edit();

        $this->assertSame('errorfetch', $result['code']);
        $this->assertSame([strlen(self::HEADER) + strlen('Subject: New subject') - strlen('Subject: Old subject') + strlen(self::BODY) + 1],
            $this->store->body_file_sizes);
        $this->assertNotContains(['append', null], $this->store->calls);
        $this->assertUntouched();
    }

    public static function configuredSizes(): array
    {
        return [
            'bytes' => [1024, 1024],
            'digits' => ['1024', 1024],
            'unit K' => ['1K', 1024],
            'unit M, spaces' => [' 64 M ', 67108864],
            'unit with B' => ['64MB', 67108864],
            'lower case' => ['2g', 2147483648],
            'zero' => [0, null],
            'negative' => [-5, null],
            'zero with unit' => ['0M', null],
            'fraction' => ['1.5M', null],
            'float' => [1e20, null],
            'integral float' => [1024.0, null],
            'boolean' => [true, null],
            'array' => [[5], null],
            'unknown unit' => ['64T', null],
            'hexadecimal' => ['0x10', null],
            'beyond PHP_INT_MAX' => ['8589934592G', null],
            'line feed after it' => ["1024\n", 1024],
            'something after it' => ['1024 bytes', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('configuredSizes')]
    public function testConfiguredSizeLimit(mixed $value, ?int $bytes): void
    {
        // test case 49
        $this->assertSame($bytes, edit_subject_engine::size_limit($value));
    }

    public static function oversizedHeaders(): array
    {
        return [
            'one line too many' => [str_repeat("a:\r\n", edit_subject_engine::MAX_HEADER_LINES) . "Subject: x\r\n\r\n"],
            'one byte too many' => ['X-Fill: ' . str_repeat('a', edit_subject_engine::MAX_HEADER_SIZE - 23) . "\r\nSubject: x\r\n\r\n"],
            'no end within the limit' => [str_repeat('X-Fill: ' . str_repeat('a', 990) . "\r\n", 6000) . "Subject: x\r\n\r\n"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('oversizedHeaders')]
    public function testOversizedHeaderIsRefusedBeforeItIsTakenApart(string $header): void
    {
        $over_size = strlen($header) - edit_subject_engine::MAX_HEADER_SIZE;
        $over_lines = substr_count($header, "\n") - edit_subject_engine::MAX_HEADER_LINES;
        $this->assertTrue($over_size > 0 || $over_lines > 0, 'the case is over a limit');
        $this->assertTrue($over_size !== 1 || $over_lines <= 0, 'one byte too many means the size alone');
        $this->store->add(30, $header . "body\r\n", [], self::DATE);

        $loaded = $this->engine()->load('INBOX', 30);
        $saved = $this->engine()->run('INBOX', 30, 'New subject');

        $this->assertSame('errorheadertoolarge', $loaded['code']);
        $this->assertSame('errorheadertoolarge', $saved['code']);
        $this->assertSame([], $this->store->writes());
        // never more than the limit plus one byte is asked from the store
        $this->assertSame([edit_subject_engine::MAX_HEADER_SIZE + 1], array_unique($this->store->header_limits));
    }

    public function testHeaderAtTheLineLimitIsAccepted(): void
    {
        $header = str_repeat("a:\r\n", edit_subject_engine::MAX_HEADER_LINES - 2) . "Subject: x\r\n\r\n";
        $this->assertSame(edit_subject_engine::MAX_HEADER_LINES, substr_count($header, "\n"));
        $this->store->add(30, $header . "body\r\n", [], self::DATE);
        $this->store->next_uid = 31;

        $this->assertSame('x', $this->engine()->load('INBOX', 30)['subject']);
        $this->assertSame('ok', $this->engine()->run('INBOX', 30, 'New subject')['status']);
    }

    // -- further failures --------------------------------------------------------

    public function testReadOnlyFolderIsRefusedBeforeWriting(): void
    {
        $this->store->read_only = true;

        $result = $this->run_edit();

        $this->assertSame('errorreadonly', $result['code']);
        $this->assertSame([], $this->store->writes());
        $this->assertUntouched();
    }

    public function testUnansweredAppendIsNotReportedAsRefused(): void
    {
        // the copy was stored, but the answer of the server never arrived
        $this->store->append_result = 'unanswered';

        $result = $this->run_edit();

        $this->assertSame('errorappendunknown', $result['code']);
        $this->assertSame([['append', null]], $this->store->writes(), 'nothing is deleted');
        $this->assertSame([1, 5, 9, 10], array_keys($this->store->messages));
        $this->assertSame(self::HEADER . self::BODY, $this->store->messages[5]['raw']);
    }

    public static function impossibleAppendUids(): array
    {
        return ['UID of the original' => [5], 'UID of an older message' => [1], 'zero' => [0]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('impossibleAppendUids')]
    public function testAppendUidThatANewMessageCannotHave(int $reported): void
    {
        $this->store->append_result = $reported;

        $result = $this->run_edit();

        $this->assertSame('errorbothexist', $result['code']);
        $this->assertSame([['append', null]], $this->store->writes(), 'nothing is deleted, in particular not the reported UID');
        $this->assertSame([1, 5, 9, 10], array_keys($this->store->messages));
    }

    public function testCopyIsKeptWhenTheOriginalCannotBeConfirmed(): void
    {
        // the store claims the original still exists although it is gone
        // (what a misread FETCH line would do)
        $this->store->ghosts = [5];

        $result = $this->run_edit();

        $this->assertSame('errorbothexist', $result['code']);
        $this->assertArrayHasKey(10, $this->store->messages, 'the only version left must stay');
        $this->assertNotContains(['delete', 10], $this->store->calls);
    }

    public function testCopyIsKeptWhenTheOriginalStaysFlaggedDeleted(): void
    {
        $this->store->noop_expunge = [5];
        $this->store->unflag_fails = true;

        $result = $this->run_edit();

        $this->assertSame('errorbothexist', $result['code']);
        $this->assertSame([1, 5, 9, 10], array_keys($this->store->messages));
        $this->assertNotContains(['delete', 10], $this->store->calls, 'the original may vanish with the next expunge');
    }

    public function testSuccessIsOnlyReportedWhileTheCopyExists(): void
    {
        // a plain EXPUNGE instead of UID EXPUNGE takes every message flagged
        // \Deleted, the copy of a flagged original included
        $this->store->messages[5]['flags'][] = '\\Deleted';
        $this->store->plain_expunge = true;

        $result = $this->run_edit();

        $this->assertSame('errorlost', $result['code']);
        $this->assertSame('error', $result['status']);
        $this->assertSame(5, $result['uid']);
    }

    // -- second audit of 22./23.11.2026 (test cases 41 to 47) ---------------------

    public function testForeignExpungeDuringTheRollbackLosesNothing(): void
    {
        // test case 41: UID EXPUNGE of the original has no effect, so the
        // original stays flagged \Deleted for a moment - and another client
        // expunges the folder right before the flag is taken back
        $this->store->noop_expunge = [5];
        $this->store->before['unflag_deleted'] = function (FakeStore $store) {
            $store->messages = array_filter($store->messages, fn ($m) => !in_array('\\Deleted', $m['flags'], true));
        };

        $result = $this->run_edit();

        $this->assertArrayHasKey(10, $this->store->messages, 'the copy is the only version left and must stay');
        $this->assertNotContains(['delete', 10], $this->store->calls);
        $this->assertSame('ok', $result['status'], 'one version with the new subject: that is a success');
        $this->assertSame(10, $result['uid']);
    }

    public function testRollbackNeedsTheOriginalWithoutTheDeletionFlag(): void
    {
        // the server answers OK to STORE -FLAGS and leaves the flag where it is
        $this->store->noop_expunge = [5];
        $this->store->unflag_without_effect = true;

        $result = $this->run_edit();

        $this->assertSame('errorbothexist', $result['code']);
        $this->assertSame([1, 5, 9, 10], array_keys($this->store->messages));
        $this->assertNotContains(['delete', 10], $this->store->calls, 'the original may vanish with the next expunge');
    }

    public function testOriginalFlaggedBeforeTheEditIsRolledBackAsItWas(): void
    {
        // the original carried \Deleted already: nothing to take back
        $this->store->messages[5]['flags'][] = '\\Deleted';
        $this->store->noop_expunge = [5];

        $result = $this->run_edit();

        $this->assertSame('errordelete', $result['code']);
        $this->assertSame([1, 5, 9], array_keys($this->store->messages));
        $this->assertContains('\\Deleted', $this->store->messages[5]['flags']);
        $this->assertNotContains(['unflag_deleted', 5], $this->store->calls);
    }

    public function testOverlongSubjectIsCutBeforeItIsDecoded(): void
    {
        // test case 42: Roundcube's decoder needs memory and (without PCRE
        // JIT) time far beyond the length of a prepared value
        $subject = str_repeat('=?a?B?x', 30000);
        $this->store->add(20, "From: a@example.test\r\nSubject: {$subject}\r\n\r\nbody\r\n", [], self::DATE);
        $this->store->next_uid = 21;

        $loaded = $this->engine()->load('INBOX', 20);

        $this->assertSame('ok', $loaded['status']);
        $this->assertNotEmpty($this->store->decoded);
        $this->assertLessThanOrEqual(edit_subject_engine::MAX_SUBJECT_BYTES, max(array_column($this->store->decoded, 0)));

        // the message can still be edited, bound to what the dialog showed
        $result = $this->engine()->run('INBOX', 20, 'Short again', true, $loaded['subject']);

        $this->assertSame('ok', $result['status']);
        $this->assertLessThanOrEqual(edit_subject_engine::MAX_SUBJECT_BYTES, max(array_column($this->store->decoded, 0)));
        $this->assertStringContainsString("Subject: Short again\r\nX-Original-Subject: {$subject}\r\n",
            $this->store->messages[21]['raw'], 'the original value is kept completely');
    }

    public function testRawSubjectIsReadWithTheCharsetOfTheMessage(): void
    {
        // test case 43: Roundcube shows "Grüße" for this message, the
        // dialog has to show the same
        $this->store->add(20, "Content-Type: text/plain; charset=ISO-8859-1\r\nSubject: Gr\xFC\xDFe\r\n\r\nbody\r\n", [], self::DATE);

        $loaded = $this->engine()->load('INBOX', 20);

        $this->assertSame('Grüße', $loaded['subject']);
        $this->assertSame('ISO-8859-1', end($this->store->decoded)[1]);
    }

    public function testAsciiIsEncodedWhereRawTextWouldBeMisread(): void
    {
        // the message names UTF-16: plain ASCII in its header would be read
        // as something else
        $this->store->add(20, "Content-Type: text/plain; charset=UTF-16BE\r\nSubject: =?UTF-8?B?QWx0?=\r\n\r\nbody\r\n", [], self::DATE);
        $this->store->next_uid = 21;

        $result = $this->engine()->run('INBOX', 20, 'Plain ASCII', false, 'Alt');

        $this->assertSame('ok', $result['status']);
        $this->assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('Plain ASCII') . "?=\r\n", $this->store->messages[21]['raw']);
    }

    public function testAsciiStaysPlainInAnOrdinaryMessage(): void
    {
        $this->assertSame('ok', $this->run_edit('Plain ASCII')['status']);
        $this->assertStringContainsString("Subject: Plain ASCII\r\n", $this->store->messages[10]['raw']);
    }

    public function testLockFileIsNeverReachedThroughALink(): void
    {
        // test case 45: the name of the lock file can be guessed; someone
        // with access to the directory puts a link there
        $victim = $this->tmp . '-victim';
        file_put_contents($victim, 'content');
        chmod($victim, 0644);
        symlink($victim, $this->lock_path(5));

        try {
            $result = $this->run_edit();
            clearstatcache();

            $this->assertSame('errorbusy', $result['code']);
            $this->assertSame([], $this->store->writes());
            $this->assertSame('0644', sprintf('%04o', fileperms($victim) & 0777), 'rights of the target unchanged');
            $this->assertSame('content', file_get_contents($victim));
            $this->assertTrue(is_link($this->lock_path(5)), 'the link is left alone');
        } finally {
            unlink($this->lock_path(5));
            unlink($victim);
        }
    }

    public function testLinkToNowhereCreatesNothing(): void
    {
        $target = $this->tmp . '-new';
        symlink($target, $this->lock_path(5));

        try {
            $result = $this->run_edit();
            clearstatcache();

            $this->assertSame('errorbusy', $result['code']);
            $this->assertFileDoesNotExist($target);
        } finally {
            unlink($this->lock_path(5));
            @unlink($target);
        }
    }

    public function testFifoInPlaceOfTheLockFileDoesNotBlock(): void
    {
        if (!function_exists('posix_mkfifo')) {
            $this->markTestSkipped('needs the posix extension');
        }

        posix_mkfifo($this->lock_path(5), 0666);

        try {
            $start = microtime(true);
            $result = $this->run_edit();

            $this->assertSame('errorbusy', $result['code']);
            $this->assertLessThan(2.0, microtime(true) - $start);
            $this->assertSame([], $this->store->writes());
        } finally {
            unlink($this->lock_path(5));
        }
    }

    public function testLockFileIsPrivateWhileItIsHeld(): void
    {
        $perms = null;
        $this->store->before['delete'] = function () use (&$perms) {
            clearstatcache();
            $perms = sprintf('%04o', fileperms($this->lock_path(5)) & 0777);
        };

        $this->assertSame('ok', $this->run_edit()['status']);
        $this->assertSame('0600', $perms);
    }

    public function testLockFileLeftBehindIsUsedAndRemoved(): void
    {
        // an aborted request leaves its lock file; it must not block the next
        touch($this->lock_path(5), time() - 86400);

        $this->assertSame('ok', $this->run_edit()['status']);
        $this->assertFileDoesNotExist($this->lock_path(5));
    }

    public function testTempFileReplacedMeanwhileIsNotSent(): void
    {
        // Roundcube opens the file by its name again; what lies under that
        // name by then must be the file that was written
        $this->store->before['fetch_body'] = function () {
            foreach (glob($this->tmp . '/RCMTEMPedit_subject*') as $file) {
                if (substr($file, -5) !== '.lock') {
                    unlink($file);
                    file_put_contents($file, 'something else');
                }
            }
        };

        $result = $this->run_edit();

        $this->assertSame('errortempfile', $result['code']);
        $this->assertSame([], $this->store->writes());
        $this->assertUntouched();
    }

    public function testUnconfirmedUidOfAnotherMessageIsNeverDeleted(): void
    {
        // test case 46: the server named a UID, but the answer that says
        // for which folder was gone (another plugin had talked to the
        // server). Under that UID lies a message of someone else.
        $this->store->add(12, "Subject: not the copy\r\n\r\nz\r\n", [], '03-Jan-2020 00:00:00 +0000');
        $this->store->next_uid = 13;
        $this->store->append_result = 12;
        $this->store->append_confirmed = false;

        $result = $this->run_edit();

        $this->assertArrayHasKey(12, $this->store->messages, 'a message that is not the copy must stay');
        $this->assertNotContains(['delete', 12], $this->store->calls);
        $this->assertSame('errorbothexist', $result['code']);
        $this->assertArrayHasKey(5, $this->store->messages);
    }

    public function testUnconfirmedCopyIsRemovedOnceItIsRecognized(): void
    {
        // the header block is the one that was written: it is the copy
        $this->store->append_confirmed = false;
        $this->store->defect = 'corrupt';

        $result = $this->run_edit();

        $this->assertSame('errorverify', $result['code']);
        $this->assertUntouched();
    }

    public function testCopyNeverSeenAndNotRemovedIsReportedAsUnknown(): void
    {
        // the server shows the new message to this connection only later
        // and refuses to flag what it does not show
        $this->store->invisible = [10];
        $this->store->refuse_delete = [10];

        $result = $this->run_edit();

        $this->assertSame('errorappendunknown', $result['code'], 'not "removed again": nothing was removed');
        $this->assertArrayHasKey(10, $this->store->messages);
        $this->assertArrayHasKey(5, $this->store->messages);
    }

    public function testCopyRemovedBySomeoneElseKeepsTheOriginal(): void
    {
        // test case 47: after the copy was verified, another client takes
        // it away (a filter that moves new messages). Deleting the original
        // now would leave nothing.
        $reads = 0;
        $this->store->before['message_info'] = function (FakeStore $store, array $args) use (&$reads) {
            if ($args[1] === 5 && ++$reads === 2) {
                unset($store->messages[10]);
            }
        };

        $result = $this->run_edit();

        $this->assertNotContains(['delete', 5], $this->store->calls);
        $this->assertSame('errorcopygone', $result['code']);
        $this->assertUntouched();
    }

    public function testCopyThatCannotBeCheckedAgainKeepsBoth(): void
    {
        $this->store->before['exists'] = function (FakeStore $store, array $args) {
            if ($args[1] === 10) {
                $store->exists_unknown_after_delete = true;
            }
        };

        $result = $this->run_edit();

        $this->assertSame('errorbothexist', $result['code']);
        $this->assertNotContains(['delete', 5], $this->store->calls);
        $this->assertSame([1, 5, 9, 10], array_keys($this->store->messages));
    }

    public function testHeaderThatCannotBeReadIsNotAMissingMessage(): void
    {
        // the message is there, its header did not arrive (connection lost)
        $this->store->before['message_info'] = function (FakeStore $store) {
            $store->invisible = [];
        };
        $this->store->header_unreadable = [5];

        $result = $this->run_edit();

        $this->assertSame('errorfetch', $result['code']);
        $this->assertSame([], $this->store->writes());
    }
}

/**
 * One IMAP folder in memory.
 */
final class FakeStore implements edit_subject_store
{
    public array $messages = [];
    public int $next_uid = 1;
    public array $calls = [];
    public array $appended_files = [];

    public bool $uidplus = true;
    public ?array $rights = null;
    public bool $rfc4314 = true;
    public $append_result = null;   // null: normal, false: refused, true: stored without UID,
                                    // 'unanswered': stored, no answer; int: stored, this UID reported
    public ?Closure $after_append = null;
    public bool $read_only = false;
    public bool $unflag_fails = false;
    public bool $plain_expunge = false;
    public array $ghosts = [];      // UIDs exists() reports although they are gone
    public array $header_limits = [];
    public ?string $defect = null;
    public array $refuse_delete = [];
    public array $noop_expunge = [];
    public bool $short_body = false;
    public array $short_body_uids = [];
    public bool $exists_unknown_after_delete = false;
    public bool $delete_reports_false = false;
    public bool $append_confirmed = true;       // the UID of an append is named for this folder
    public bool $unflag_without_effect = false; // STORE -FLAGS answers OK and changes nothing
    public array $invisible = [];               // UIDs this connection does not see (yet)
    public array $header_unreadable = [];       // UIDs whose header block does not arrive
    public array $decoded = [];                 // [length of the raw value, charset] of every decode_header()
    public array $extra_body = [];              // UID => bytes the server sends beyond the body it announced
    public array $body_limits = [];             // $max_bytes of every fetch_body()
    public array $body_file_sizes = [];         // size of the file after every fetch_body()

    /** @var Closure[] method name => function (FakeStore $store, array $args), called before the method */
    public array $before = [];

    public function __construct(private string $tmp)
    {
    }

    private function hook(string $method, array $args): void
    {
        if (isset($this->before[$method])) {
            ($this->before[$method])($this, $args);
        }
    }

    private function visible(int $uid): bool
    {
        return isset($this->messages[$uid]) && !in_array($uid, $this->invisible, true);
    }

    public function add(int $uid, string $raw, array $flags, string $date): int
    {
        $this->messages[$uid] = ['raw' => $raw, 'flags' => $flags, 'internaldate' => $date];
        ksort($this->messages);

        return $uid;
    }

    public function writes(): array
    {
        return array_values(array_filter($this->calls, fn ($c) => in_array($c[0], ['append', 'delete', 'unflag_deleted'])));
    }

    private function split(string $raw): array
    {
        $pos = strpos($raw, "\r\n\r\n");

        return $pos === false ? [$raw, ''] : [substr($raw, 0, $pos + 4), substr($raw, $pos + 4)];
    }

    public function has_uidplus()
    {
        $this->calls[] = ['has_uidplus', null];

        return $this->uidplus;
    }

    public function rights($folder)
    {
        $this->calls[] = ['rights', null];

        return $this->rights;
    }

    public function rfc4314_rights()
    {
        return $this->rfc4314;
    }

    public function message_info($folder, $uid)
    {
        $this->hook(__FUNCTION__, func_get_args());
        $this->calls[] = ['message_info', $uid];
        $m = $this->visible($uid) ? $this->messages[$uid] : null;

        return $m ? ['flags' => $m['flags'], 'internaldate' => $m['internaldate'], 'size' => strlen($m['raw'])] : null;
    }

    public function read_only($folder)
    {
        return $this->read_only;
    }

    public function fetch_header($folder, $uid, $max_bytes = 0)
    {
        $this->calls[] = ['fetch_header', $uid];
        $this->header_limits[] = $max_bytes;

        if (!$this->visible($uid) || in_array($uid, $this->header_unreadable, true)) {
            return false;
        }

        $header = $this->split($this->messages[$uid]['raw'])[0];

        return $max_bytes ? substr($header, 0, $max_bytes) : $header;
    }

    public function fetch_body($folder, $uid, $fp, $max_bytes = 0)
    {
        $this->hook(__FUNCTION__, func_get_args());
        $this->calls[] = ['fetch_body', $uid];
        $this->body_limits[] = $max_bytes;
        $body = $this->split($this->messages[$uid]['raw'])[1] . ($this->extra_body[$uid] ?? '');

        if ($this->short_body || in_array($uid, $this->short_body_uids, true)) {
            $body = substr($body, 0, -1);
        }

        // like edit_subject_storage: nothing beyond $max_bytes reaches the file
        fwrite($fp, $max_bytes ? substr($body, 0, $max_bytes) : $body);
        $this->body_file_sizes[] = fstat($fp)['size'];

        return false; // like Roundcube 1.6 after a successful write
    }

    public function append($folder, $path, $flags, $internaldate)
    {
        $this->calls[] = ['append', null];
        clearstatcache();
        $this->appended_files[] = [
            'dir' => dirname(realpath($path)),
            'name' => basename($path),
            'perms' => sprintf('%04o', fileperms($path) & 0777),
        ];

        if ($this->append_result === false) {
            return false;
        }

        $raw = file_get_contents($path);

        switch ($this->defect) {
            case 'corrupt':
                $raw .= 'X';
                break;
            case 'corrupt_body':
                $raw = substr($raw, 0, -1) . 'X';
                break;
            case 'drop_flags':
                $flags = array_values(array_filter($flags, fn ($f) => strcasecmp($f, '\\Seen') === 0));
                break;
            case 'change_date':
                $internaldate = '01-Jan-2001 00:00:00 +0000';
                break;
            case 'rewrite_header':
                $raw = str_replace('From: Alice', 'From:  Alice', $raw);
                $raw = substr($raw, 0, -1);
                break;
        }

        $uid = $this->next_uid++;
        $this->add($uid, $raw, $flags, $internaldate);

        if ($this->after_append) {
            ($this->after_append)($this);
        }

        if ($this->append_result === 'unanswered') {
            return null;
        }

        if (is_int($this->append_result)) {
            return $this->append_result;
        }

        return $this->append_result === true ? true : $uid;
    }

    public function append_confirmed()
    {
        return $this->append_confirmed;
    }

    public function delete($folder, $uid)
    {
        $this->hook(__FUNCTION__, func_get_args());
        $this->calls[] = ['delete', $uid];

        if (in_array($uid, $this->refuse_delete, true) || !isset($this->messages[$uid])) {
            return false;
        }

        if (!in_array('\\Deleted', $this->messages[$uid]['flags'], true)) {
            $this->messages[$uid]['flags'][] = '\\Deleted';
        }

        if (!in_array($uid, $this->noop_expunge, true)) {
            unset($this->messages[$uid]);
        }

        if ($this->plain_expunge) {
            $this->messages = array_filter($this->messages, fn ($m) => !in_array('\\Deleted', $m['flags'], true));
        }

        return !$this->delete_reports_false;
    }

    public function unflag_deleted($folder, $uid)
    {
        $this->hook(__FUNCTION__, func_get_args());
        $this->calls[] = ['unflag_deleted', $uid];

        if ($this->unflag_fails) {
            return false;
        }

        // a server answers OK as well if the message is gone
        if (isset($this->messages[$uid]) && !$this->unflag_without_effect) {
            $this->messages[$uid]['flags'] = array_values(array_diff($this->messages[$uid]['flags'], ['\\Deleted']));
        }

        return true;
    }

    public function exists($folder, $uid)
    {
        $this->hook(__FUNCTION__, func_get_args());
        $this->calls[] = ['exists', $uid];

        if ($this->exists_unknown_after_delete) {
            return null;
        }

        return $this->visible($uid) || in_array($uid, $this->ghosts, true);
    }

    public function decode_header($value, $charset = null)
    {
        $this->decoded[] = [strlen($value), $charset];

        // like Roundcube: text outside of encoded-words is read with the
        // character set of the message
        if ($charset !== null && strpos($value, '=?') === false) {
            return mb_convert_encoding($value, 'UTF-8', $charset);
        }

        return iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    }

    public function last_error()
    {
        return 'APPEND: Quota exceeded (mailbox for user is full)';
    }
}

/** Simulates a filesystem on which flock() fails without another lock holder. */
final class LockFailureEngine extends edit_subject_engine
{
    protected function acquire_lock($fp, &$would_block)
    {
        $would_block = false;

        return false;
    }
}
