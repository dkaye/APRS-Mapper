<?php
/** Tests for who a broadcast actually reaches: the devices that could receive it, not
 *  every identity the event has ever held. */
use PHPUnit\Framework\TestCase;

class MessagingBroadcastTest extends TestCase
{
    private string $dbFile;
    private string $trackerFile;
    private MessagingDb $db;
    private string $ev = 'Test Event';

    protected function setUp(): void
    {
        $this->dbFile      = tempnam(sys_get_temp_dir(), 'msgbc_') . '.db';
        $this->trackerFile = tempnam(sys_get_temp_dir(), 'msgbctr_') . '.json';
        $this->db          = new MessagingDb($this->dbFile);
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbFile, $this->dbFile . '-wal', $this->dbFile . '-shm',
                  $this->trackerFile] as $f) {
            if (file_exists($f)) unlink($f);
        }
    }

    /** Writes the tracker feed the picker and the broadcast both read. */
    private function trackers(array $rows): array
    {
        file_put_contents($this->trackerFile, json_encode($rows));
        return ['event' => $this->ev, 'mobileFile' => $this->trackerFile];
    }

    private function tracker(string $cs, int $ageSeconds, array $extra = []): array
    {
        return $extra + ['callsign' => $cs, 'name' => $cs, 'token' => 'tok-' . $cs,
                         'lastUpdate' => time() - $ageSeconds];
    }

    /** A phone heard from an hour ago receives it; one last heard two days ago does not.
     *
     *  This is the whole bug: the recipient list was every row in `participants`, so a
     *  broadcast to two dozen live phones reported "Read by 1 of 66" and could never
     *  report anything better. A denominator that cannot be satisfied is worse than no
     *  denominator, because it looks like an answer. */
    public function testOnlyDevicesThatCouldReceiveItAreCounted(): void
    {
        $ctx  = $this->trackers([
            $this->tracker('MARSQ-001', 3600),        // an hour ago — live
            $this->tracker('MARSQ-002', 2 * 86400),   // two days ago — gone
        ]);
        $me   = $this->db->upsertParticipant($this->ev, 'operator', 'Net Control', 'Net Control', null, 'tok');
        $live = $this->db->upsertParticipant($this->ev, 'mobile', 'MARSQ-001', 'Live', 'H1', 'tok-MARSQ-001');
        $gone = $this->db->upsertParticipant($this->ev, 'mobile', 'MARSQ-002', 'Gone', 'H2', 'tok-MARSQ-002');

        $to = _msg_broadcast_recipients($this->db, $ctx, $me);

        $this->assertContains($live, $to, 'a phone heard from an hour ago is reachable');
        $this->assertNotContains($gone, $to, 'one last heard two days ago is not');
        $this->assertNotContains($me, $to, 'and the sender never receives their own broadcast');
    }

    /** A mobile's freshness comes from the tracker feed, NOT participants.last_seen.
     *
     *  upsertParticipant stamps last_seen on every write, and _msg_ensure_all_mobiles
     *  writes every tracker in the file immediately before a broadcast is addressed — so
     *  a last_seen test would call all of them fresh and filter nothing at all. The row
     *  below is stamped as of right now and must still be excluded, because the feed says
     *  nobody has heard from it in two days. */
    public function testStaleDeviceStaysOutEvenWithAFreshParticipantRow(): void
    {
        $ctx  = $this->trackers([$this->tracker('MARSQ-002', 2 * 86400)]);
        $me   = $this->db->upsertParticipant($this->ev, 'operator', 'Net Control', 'Net Control', null, 'tok');
        // Written twice, exactly as a broadcast would: last_seen is now, the feed is old.
        $gone = $this->db->upsertParticipant($this->ev, 'mobile', 'MARSQ-002', 'Gone', 'H2', 'tok-MARSQ-002');
        $gone = $this->db->upsertParticipant($this->ev, 'mobile', 'MARSQ-002', 'Gone', 'H2', 'tok-MARSQ-002');

        $this->assertNotContains($gone, _msg_broadcast_recipients($this->db, $ctx, $me));
    }

    /** A device with no session token cannot be reached, however recently it beaconed. */
    public function testATrackerWithNoTokenIsNotAddressable(): void
    {
        $ctx = $this->trackers([['callsign' => 'MARSQ-003', 'name' => 'NoTok',
                                 'lastUpdate' => time() - 60]]);
        $me  = $this->db->upsertParticipant($this->ev, 'operator', 'Net Control', 'Net Control', null, 'tok');
        $no  = $this->db->upsertParticipant($this->ev, 'mobile', 'MARSQ-003', 'NoTok', 'H3', null);

        $this->assertNotContains($no, _msg_broadcast_recipients($this->db, $ctx, $me));
    }

    /** A blocked tracker is not a recipient either — the picker refuses it and delivery
     *  must agree, or an operator could reach by broadcast what they cannot address. */
    public function testABlockedTrackerIsNotARecipient(): void
    {
        $ctx = $this->trackers([$this->tracker('MARSQ-004', 60, ['blocked' => true])]);
        $me  = $this->db->upsertParticipant($this->ev, 'operator', 'Net Control', 'Net Control', null, 'tok');
        $b   = $this->db->upsertParticipant($this->ev, 'mobile', 'MARSQ-004', 'Blocked', 'H4', 'tok-MARSQ-004');

        $this->assertNotContains($b, _msg_broadcast_recipients($this->db, $ctx, $me));
    }

    /** Other operators still receive a broadcast, judged on last_seen — which for them is
     *  written only by touchParticipant and so means what it says. A signed-out one from
     *  a previous net does not. */
    public function testOperatorsAreJudgedOnLastSeen(): void
    {
        $ctx  = $this->trackers([]);
        $me   = $this->db->upsertParticipant($this->ev, 'operator', 'Net Control', 'Net Control', null, 'tok');
        $here = $this->db->upsertParticipant($this->ev, 'operator', 'Shadow', 'Shadow', null, 'tok2');
        $away = $this->db->upsertParticipant($this->ev, 'operator', 'Yesterday', 'Yesterday', null, 'tok3');
        $this->db->disconnectParticipant($away);   // signs out: token cleared, last_seen 0

        $to = _msg_broadcast_recipients($this->db, $ctx, $me);
        $this->assertContains($here, $to);
        $this->assertNotContains($away, $to);
    }

    /** Transcribers are excluded. A receiver reads nothing, so its delivery row could
     *  never be marked read and would sit in the denominator for good. */
    public function testTranscribersAreNotBroadcastRecipients(): void
    {
        $ctx = $this->trackers([]);
        $me  = $this->db->upsertParticipant($this->ev, 'operator', 'Net Control', 'Net Control', null, 'tok');
        $rx  = $this->db->upsertParticipant($this->ev, 'transcriber', 'rx1-146700', '146.700', null, 'tokrx');

        $this->assertNotContains($rx, _msg_broadcast_recipients($this->db, $ctx, $me));
    }

    /** The old answer is refused rather than left lying around: it cannot see the tracker
     *  feed, so any answer it gives for a broadcast is the wrong one. */
    public function testTheDatabaseNoLongerAnswersBroadcastRecipients(): void
    {
        $conv = $this->db->resolveLogConversation($this->ev);
        $this->expectException(RuntimeException::class);
        $this->db->conversationRecipients($this->ev, $conv, true, 0);
    }
}
