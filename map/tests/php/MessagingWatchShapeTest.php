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
