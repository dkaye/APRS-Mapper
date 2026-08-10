<?php
/** Tests for the guarantees the Apple Watch relay depends on.
 *
 *  The watch reaches the messaging API two ways — relayed from the phone over
 *  WatchConnectivity, and, when the phone is unreachable, by polling directly.
 *  Both paths converge on one dedupe on the watch. These tests pin the two
 *  server-side properties that make that safe, plus the legacy shape the phone
 *  relays through. */
use PHPUnit\Framework\TestCase;

class MessagingWatchShapeTest extends TestCase
{
    private string $dbFile;
    private MessagingDb $db;
    private string $ev = 'Watch Test';
    private int $op, $phone;

    protected function setUp(): void
    {
        $this->dbFile = tempnam(sys_get_temp_dir(), 'msgwatch_') . '.db';
        $this->db     = new MessagingDb($this->dbFile);
        $this->op     = $this->db->upsertParticipant($this->ev, 'operator', 'Net Control', 'Net Control', null, null);
        $this->phone  = $this->db->upsertParticipant($this->ev, 'mobile', 'MARSQ-015', 'Doug', 'M141', null);
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbFile, $this->dbFile . '-wal', $this->dbFile . '-shm'] as $f) {
            if (file_exists($f)) unlink($f);
        }
    }

    /** A direct thread between two participants. */
    private function direct(int $from, int $to): int
    {
        [$id, ] = $this->db->resolveConversation($this->ev, $from, [$to], false, null);
        return $id;
    }

    /** Send $text and return the legacy shape the phone would relay to the watch. */
    private function relayed(int $conv, int $from, int $to, string $text, bool $broadcast = false): array
    {
        $this->db->insertMessage($this->ev, $conv, $from, $text, [$to], $broadcast);
        $pending = $this->db->pendingFor($to);
        return _msg_legacy_shape(end($pending));
    }

    // ── legacy shape ──────────────────────────────────────────────────────────

    /** conversation_id is what lets a watch reply land in the thread the message
     *  arrived on rather than opening a new ad-hoc one. */
    public function testLegacyShapeCarriesConversationId(): void
    {
        $conv = $this->direct($this->op, $this->phone);

        $out = $this->relayed($conv, $this->op, $this->phone, 'Radio check');

        $this->assertSame($conv, $out['conversation_id']);
    }

    /** The watch builds "M141 Doug" from these, exactly as the new API's
     *  senderLabel does, so the two paths never disagree about a sender's name. */
    public function testLegacyShapeCarriesSenderIdentity(): void
    {
        $conv = $this->direct($this->phone, $this->op);

        $out = $this->relayed($conv, $this->phone, $this->op, 'M141 is 10-8');

        $this->assertSame('M141', $out['from_short']);
        $this->assertSame('mobile', $out['from_kind']);
        $this->assertSame('Doug', $out['from_label']);
    }

    /** A net-wide call gets a distinct haptic and spoken prefix on the watch, so
     *  the flag has to survive the shim rather than being inferred client-side. */
    public function testLegacyShapeMarksBroadcast(): void
    {
        [$conv, $kind] = $this->db->resolveConversation($this->ev, $this->op, [], true, null);
        $this->assertSame('broadcast', $kind);

        $out = $this->relayed($conv, $this->op, $this->phone, 'All stations', true);

        $this->assertTrue($out['broadcast']);
    }

    /** A direct message must NOT be flagged, or every message would get the
     *  net-wide double haptic and the distinction would be worthless. */
    public function testLegacyShapeLeavesDirectMessagesUnflagged(): void
    {
        $conv = $this->direct($this->op, $this->phone);

        $out = $this->relayed($conv, $this->op, $this->phone, 'Just for you');

        $this->assertFalse($out['broadcast']);
    }

    /** Old app builds must keep working unchanged — the four keys are additive. */
    public function testLegacyShapeKeepsItsOriginalKeys(): void
    {
        $conv = $this->direct($this->op, $this->phone);

        $out = $this->relayed($conv, $this->op, $this->phone, 'Copy that');

        foreach (['id', 'from_label', 'text', 'ts'] as $k) {
            $this->assertArrayHasKey($k, $out);
        }
        $this->assertSame('Copy that', $out['text']);
    }

    // ── what makes the hybrid transport safe ──────────────────────────────────

    /** The watch may ack messages it obtained from its own direct poll while the
     *  phone acks the same ones. A second ack must be a no-op, or the sender's
     *  "Read ✓✓" state would move to whichever device asked last. */
    public function testMarkReadIsIdempotent(): void
    {
        $conv = $this->direct($this->op, $this->phone);
        $id   = $this->db->insertMessage($this->ev, $conv, $this->op, 'Ack me', [$this->phone], false);

        $this->db->pollFor($this->phone, 0);      // delivered
        $this->db->markRead($this->phone, [$id]);
        $first = $this->db->receiptsForSender($this->op, 0);
        $this->db->markRead($this->phone, [$id]); // the other device acks too
        $second = $this->db->receiptsForSender($this->op, 0);

        $this->assertSame($first, $second, 'a duplicate ack must not change the receipt');
    }

    /** Phone and watch share one participant id, so both poll the same feed. A
     *  poll must not consume rows: whichever device asked second would otherwise
     *  see nothing and the message would be lost on that surface. */
    public function testPollForIsRepeatableAtTheSameWatermark(): void
    {
        $conv = $this->direct($this->op, $this->phone);
        $this->db->insertMessage($this->ev, $conv, $this->op, 'One', [$this->phone], false);
        $this->db->insertMessage($this->ev, $conv, $this->op, 'Two', [$this->phone], false);

        $phoneSaw = $this->db->pollFor($this->phone, 0);
        $watchSaw = $this->db->pollFor($this->phone, 0);

        $this->assertCount(2, $phoneSaw);
        $this->assertSame(array_column($phoneSaw, 'id'), array_column($watchSaw, 'id'));
    }

    // ── stale threads ─────────────────────────────────────────────────────────

    /** An operator scanning the inbox is looking for someone to talk to, so a thread
     *  whose other end has not been seen in 24 hours is reported stale — the same
     *  window the recipient picker uses to decide who is addressable. The flag is
     *  reported, not acted on here: the panel hides them, an inbox may not. */
    public function testConversationWithALongGoneMemberIsStale(): void
    {
        $conv = $this->direct($this->op, $this->phone);
        $this->db->insertMessage($this->ev, $conv, $this->phone, 'Hello', [$this->op], false);

        // The operator has gone: disconnect zeroes last_seen, which is the same
        // condition as never having been seen inside the window.
        $this->db->disconnectParticipant($this->op);

        $this->assertTrue($this->conversationById($this->phone, $conv)['stale']);
    }

    public function testConversationWithARecentMemberIsNotStale(): void
    {
        $conv = $this->direct($this->op, $this->phone);
        $this->db->insertMessage($this->ev, $conv, $this->phone, 'Hello', [$this->op], false);
        $this->db->touchParticipant($this->op);

        $this->assertFalse($this->conversationById($this->phone, $conv)['stale']);
    }

    /** "All Trackers" has no members and outlives everyone in it. */
    public function testBroadcastIsNeverStale(): void
    {
        [$conv, ] = $this->db->resolveConversation($this->ev, $this->op, [], true, null);
        $this->db->insertMessage($this->ev, $conv, $this->op, 'All stations', [$this->phone], true);

        $this->assertFalse($this->conversationById($this->phone, $conv)['stale']);
    }

    private function conversationById(int $me, int $conversationId): array
    {
        foreach ($this->db->conversationsFor($this->ev, $me) as $c) {
            if ((int)$c['id'] === $conversationId) return $c;
        }
        $this->fail("conversation $conversationId not returned");
    }

    // ── delivered is not read ─────────────────────────────────────────────────

    /** A device acking a message means it has it, not that anyone has looked at it.
     *  The ack used to call markRead, so an operator's panel showed "Read ✓✓" the
     *  moment a phone polled — for a message still sitting unseen on a lock screen. */
    public function testAckMarksDeliveredNotRead(): void
    {
        $conv = $this->direct($this->op, $this->phone);
        $id   = $this->db->insertMessage($this->ev, $conv, $this->op, 'Radio check', [$this->phone], false);

        $this->db->markDelivered($this->phone, [$id]);
        $r = $this->db->receiptsForSender($this->op, 0)[0];

        $this->assertSame(1, (int)$r['delivered']);
        $this->assertSame(0, (int)$r['read'], 'nobody has opened it');
    }

    /** And having been delivered is what stops it being sent again — that was the
     *  only reason the ack ever had to claim it was read. */
    public function testDeliveredStopsRedelivery(): void
    {
        $conv = $this->direct($this->op, $this->phone);
        $id   = $this->db->insertMessage($this->ev, $conv, $this->op, 'Once only', [$this->phone], false);

        $this->assertCount(1, $this->db->pendingFor($this->phone));
        $this->db->markDelivered($this->phone, [$id]);

        $this->assertCount(0, $this->db->pendingFor($this->phone), 'already handed over');
    }

    // ── replying into an entity thread ────────────────────────────────────────

    /** The watch aims at the thread a message arrived on, and an operator addressing
     *  a mobile from the picker uses an `ent:` row — so that thread is very often an
     *  entity conversation, and a reply into it has to reach the operator.
     *
     *  These pin the invariant the send path depends on. The gate itself lives in
     *  messaging.php's send handler, which echoes JSON and exits, so it cannot be
     *  exercised from here; what can be pinned is that plain conversationRecipients
     *  gives a mobile sender the right answer, which is what the gate falls through
     *  to. */
    public function testMobileReplyingIntoAnEntityThreadReachesTheOperator(): void
    {
        $phone2 = $this->db->upsertParticipant($this->ev, 'mobile', 'MARSQ-016', 'Doug', 'M142', null);
        $conv = $this->db->resolveEntityConversation($this->ev, $this->op, 'DRK', 'Doug',
                                                     [$this->phone, $phone2]);

        $to = $this->db->conversationRecipients($this->ev, $conv, false, $this->phone);

        $this->assertContains($this->op, $to, 'the operator being answered must receive the reply');
        $this->assertNotContains($this->phone, $to, 'the sender never receives their own message');
    }

    /** The operator is a member, which is the whole reason the fall-through works. */
    public function testEntityThreadIncludesTheOperator(): void
    {
        $conv = $this->db->resolveEntityConversation($this->ev, $this->op, 'DRK', 'Doug', [$this->phone]);

        $members = $this->db->conversationRecipients($this->ev, $conv, false, 0);

        $this->assertContains($this->op, $members);
        $this->assertContains($this->phone, $members);
    }

    /** The behaviour the gate protects: an operator replying into an entity thread
     *  still addresses the devices, not themselves. */
    public function testOperatorReplyingIntoAnEntityThreadAddressesTheDevices(): void
    {
        $phone2 = $this->db->upsertParticipant($this->ev, 'mobile', 'MARSQ-016', 'Doug', 'M142', null);
        $conv = $this->db->resolveEntityConversation($this->ev, $this->op, 'DRK', 'Doug',
                                                     [$this->phone, $phone2]);

        $to = $this->db->conversationRecipients($this->ev, $conv, false, $this->op);

        $this->assertNotContains($this->op, $to);
        $this->assertContains($this->phone, $to);
        $this->assertContains($phone2, $to);
    }

    /** Ordering is by id, never ts — the watch sorts the same way, and a device
     *  with a skewed clock must not be able to reorder a net's traffic. */
    public function testPollForOrdersById(): void
    {
        $conv = $this->direct($this->op, $this->phone);
        $a = $this->db->insertMessage($this->ev, $conv, $this->op, 'First',  [$this->phone], false);
        $b = $this->db->insertMessage($this->ev, $conv, $this->op, 'Second', [$this->phone], false);

        $ids = array_column($this->db->pollFor($this->phone, 0), 'id');

        $this->assertSame([$a, $b], $ids);
    }
}
