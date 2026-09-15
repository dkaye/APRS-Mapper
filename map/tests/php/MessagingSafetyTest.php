<?php
/** Tests for the participant safety controls — blocking, reporting, deleting your own
 *  words, and the objectionable-content filter.
 *
 *  These exist because App Store Guideline 1.2 requires them of any app where one
 *  participant can put text in front of another. That makes them load-bearing in a way
 *  ordinary features are not: if one of them silently stops working, the app is not
 *  merely missing a feature, it is out of compliance with the terms it ships under.
 *
 *  The filter tests are written as two lists — what must pass and what must not — because
 *  the failure that matters most for a filter like this is the FALSE POSITIVE. A filter
 *  that refuses "good shot" or "count of riders" gets fought by volunteers during an
 *  event, and a tool people fight mid-net is worse than one that lets a rude word past.
 *
 * Docs: https://marsaprs.org
 * @author    Doug Kaye
 * @copyright 2026 Doug Kaye. All Rights Reserved.
 */
use PHPUnit\Framework\TestCase;

class MessagingSafetyTest extends TestCase
{
    private string $dbFile;
    private MessagingDb $db;
    private string $ev = 'Safety Test';
    private int $op, $alice, $bob;

    protected function setUp(): void
    {
        $this->dbFile = tempnam(sys_get_temp_dir(), 'msgsafe_') . '.db';
        $this->db     = new MessagingDb($this->dbFile);
        $this->op     = $this->db->upsertParticipant($this->ev, 'operator', 'Net Control', 'Net Control', null, null);
        $this->alice  = $this->db->upsertParticipant($this->ev, 'mobile', 'MARSQ-001', 'Alice', 'M001', null);
        $this->bob    = $this->db->upsertParticipant($this->ev, 'mobile', 'MARSQ-002', 'Bob',   'M002', null);
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbFile, $this->dbFile . '-wal', $this->dbFile . '-shm'] as $f) {
            if (file_exists($f)) unlink($f);
        }
    }

    private function direct(int $from, int $to): int
    {
        [$id, ] = $this->db->resolveConversation($this->ev, $from, [$to], false, null);
        return $id;
    }

    // ── blocking ──────────────────────────────────────────────────────────────

    public function testBlockingIsPrivateToTheBlocker(): void
    {
        $this->db->blockUser($this->ev, $this->alice, $this->bob);

        $this->assertSame([$this->bob], $this->db->blockedIds($this->ev, $this->alice));
        // Bob is not told, and net control is unaffected. One person finding another
        // intolerable is not the event deciding it.
        $this->assertSame([], $this->db->blockedIds($this->ev, $this->bob));
        $this->assertSame([], $this->db->blockedIds($this->ev, $this->op));
    }

    public function testBlockingIsIdempotentAndReversible(): void
    {
        $this->db->blockUser($this->ev, $this->alice, $this->bob);
        $this->db->blockUser($this->ev, $this->alice, $this->bob);
        $this->assertCount(1, $this->db->blockedIds($this->ev, $this->alice),
                           'blocking twice must not create two rows');

        $this->db->unblockUser($this->ev, $this->alice, $this->bob);
        $this->assertSame([], $this->db->blockedIds($this->ev, $this->alice));
    }

    /** Blocking yourself would make your own messages vanish from your own threads. */
    public function testYouCannotBlockYourself(): void
    {
        $this->db->blockUser($this->ev, $this->alice, $this->alice);
        $this->assertSame([], $this->db->blockedIds($this->ev, $this->alice));
    }

    /** The block hides the sender's traffic without deleting it: the message still
     *  exists, still reaches everybody else, and comes back if the block is lifted. */
    public function testABlockHidesTrafficWithoutDestroyingIt(): void
    {
        $conv = $this->direct($this->bob, $this->alice);
        $this->db->insertMessage($this->ev, $conv, $this->bob, 'unwanted', [$this->alice], false);

        $thread = $this->db->thread($conv);
        $this->assertCount(1, $thread, 'precondition: Alice can see it');

        $this->db->blockUser($this->ev, $this->alice, $this->bob);
        $blocked = $this->db->blockedIds($this->ev, $this->alice);
        $filtered = array_values(array_filter(
            $thread, fn($m) => !in_array((int)$m['from_id'], $blocked, true)));
        $this->assertSame([], $filtered, 'blocked sender is filtered out of the thread');

        // Still on the server, so an administrator reading the log sees what happened.
        $this->assertCount(1, $this->db->thread($conv), 'the message itself is untouched');

        // And unblocking restores the history rather than leaving a hole.
        $this->db->unblockUser($this->ev, $this->alice, $this->bob);
        $this->assertSame([], $this->db->blockedIds($this->ev, $this->alice));
    }

    // ── reporting ─────────────────────────────────────────────────────────────

    /** The excerpt is copied into the report, so deleting the message does not destroy
     *  the evidence of what was reported. "What was reported and what was done about it"
     *  is the question asked afterwards. */
    public function testAReportOutlivesTheMessageItNames(): void
    {
        $conv = $this->direct($this->bob, $this->alice);
        $mid  = $this->db->insertMessage($this->ev, $conv, $this->bob, 'something vile', [$this->alice], false);

        $rid = $this->db->reportMessage($this->ev, $mid, $this->alice, $this->bob,
                                        'something vile', 'abusive');
        $this->assertGreaterThan(0, $rid);
        $this->assertSame(1, $this->db->openReportCount($this->ev));

        $this->db->deleteMessage($mid);

        $reports = $this->db->listReports($this->ev);
        $this->assertCount(1, $reports);
        $this->assertSame('something vile', $reports[0]['excerpt']);
        $this->assertSame('abusive', $reports[0]['reason']);
        $this->assertSame('Alice', $reports[0]['reporter_name']);
        $this->assertSame('Bob', $reports[0]['sender_name']);
        $this->assertSame(0, (int)$reports[0]['still_present'],
                          'the queue shows the message is already gone');
    }

    public function testResolvingAReportClosesItOnce(): void
    {
        $conv = $this->direct($this->bob, $this->alice);
        $mid  = $this->db->insertMessage($this->ev, $conv, $this->bob, 'x', [$this->alice], false);
        $rid  = $this->db->reportMessage($this->ev, $mid, $this->alice, $this->bob, 'x', '');

        $this->db->resolveReport($this->ev, $rid, 'removed');
        $this->assertSame(0, $this->db->openReportCount($this->ev));

        $first = $this->db->listReports($this->ev)[0];
        $this->assertSame('removed', $first['action']);
        $this->assertNotNull($first['resolved_ts']);

        // Resolving again must not overwrite who closed it or when.
        $this->db->resolveReport($this->ev, $rid, 'something else');
        $this->assertSame('removed', $this->db->listReports($this->ev)[0]['action']);
    }

    /** Open reports sort ahead of closed ones — the administrator's working order, and
     *  the whole reason the 24-hour commitment is operable. */
    public function testOpenReportsSortFirst(): void
    {
        $conv = $this->direct($this->bob, $this->alice);
        $m1 = $this->db->insertMessage($this->ev, $conv, $this->bob, 'one', [$this->alice], false);
        $m2 = $this->db->insertMessage($this->ev, $conv, $this->bob, 'two', [$this->alice], false);
        $r1 = $this->db->reportMessage($this->ev, $m1, $this->alice, $this->bob, 'one', '');
        $this->db->reportMessage($this->ev, $m2, $this->alice, $this->bob, 'two', '');
        $this->db->resolveReport($this->ev, $r1, 'reviewed');

        $reports = $this->db->listReports($this->ev);
        $this->assertSame('two', $reports[0]['excerpt'], 'the open one comes first');
        $this->assertSame('one', $reports[1]['excerpt']);
    }

    // ── deleting your own words ───────────────────────────────────────────────

    public function testDeleteRemovesTheMessageFromTheThread(): void
    {
        $conv = $this->direct($this->alice, $this->bob);
        $mid  = $this->db->insertMessage($this->ev, $conv, $this->alice, 'sent in error', [$this->bob], false);
        $this->assertCount(1, $this->db->thread($conv));

        $this->db->deleteMessage($mid);
        $this->assertSame([], $this->db->thread($conv));
        $this->assertNull($this->db->message($mid));
    }

    /** message() is what the endpoint authorises a delete against, so it has to report
     *  the sender accurately or anybody could delete anybody's message. */
    public function testMessageReportsItsSender(): void
    {
        $conv = $this->direct($this->alice, $this->bob);
        $mid  = $this->db->insertMessage($this->ev, $conv, $this->alice, 'mine', [$this->bob], false);

        $m = $this->db->message($mid);
        $this->assertSame($this->alice, (int)$m['sender_id']);
        $this->assertSame($this->ev, $m['event']);
    }

    // ── the content filter ────────────────────────────────────────────────────

    /** Real event traffic must never be refused. Every string here is the sort of thing
     *  somebody actually types at an aid station, and several are the classic filter
     *  failures: "assessment" and "Scunthorpe" contain listed words; "shot" and "count"
     *  collapse to the same consonants as words that are listed. */
    public function testOrdinaryEventTrafficPasses(): void
    {
        $ok = [
            'Aid 3 we have a rider down',
            'the damn gate is locked',
            'pass the class 3 assessment',
            'Scunthorpe checkpoint clear',
            'good shot on that photo',
            'count of riders is 42',
            'check-in at aid 2',
            "don't forget water",
            'Cumberland Road is closed',
            'classic route today',
            'K6DRK mobile, no traffic',
            'SAG-1 en route to mile 7',
            'co-ordinate with net control',
            'e-mail me the roster',
        ];
        foreach ($ok as $text) {
            $this->assertNull(ht_filter_message($text),
                              "must not be refused: \"$text\"");
        }
    }

    public function testObjectionableLanguageIsRefused(): void
    {
        foreach (['what the fuck', 'sh1t', 'fuuuuck', 'you are a bitch'] as $text) {
            $this->assertNotNull(ht_filter_message($text), "must be refused: \"$text\"");
        }
    }

    /** Self-censored spellings. These are matched by treating the mask as a wildcard,
     *  which only runs on words that actually contain a mask — see content_filter.php
     *  for why that restriction is what keeps "good shot" out of it. */
    public function testMaskedSpellingsAreRefused(): void
    {
        foreach (['f*ck this', 's#!t happens', 'f.u.c.k'] as $text) {
            $this->assertNotNull(ht_filter_message($text), "must be refused: \"$text\"");
        }
    }

    public function testEmptyTextIsNotAnError(): void
    {
        // A photo-only message has no text, and must not be refused for it.
        $this->assertNull(ht_filter_message(''));
        $this->assertNull(ht_filter_message('   '));
    }
}
