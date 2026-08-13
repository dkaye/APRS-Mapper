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
        foreach ($this->tmpFiles as $f) {
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
     *  reported, not acted on here: the panel hides them, an inbox may not.
     *
     *  These cover the operator side, where participants.last_seen is written only by
     *  touchParticipant and so means what it says. A mobile's freshness is taken from
     *  its tracker lastUpdate instead — see _msg_mark_stale in messaging.php — because
     *  bulk upserts stamp last_seen on stations that have not been near the event for
     *  weeks. That override needs the tracker file and so lives in the handler, which
     *  echoes and exits and cannot be reached from here. */
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

    // ── an operator session outliving its event ───────────────────────────────

    /** An operator's token lives in the browser until they sign out, so it outlives the
     *  event it was issued for. Moving it into the current event is what puts them back
     *  in the roster mobiles are offered — without it a new event has no operator in it
     *  and nobody on a phone can address net control at all. */
    public function testRehomingASessionPutsItInTheCurrentEvent(): void
    {
        $next = 'Next Event';
        $old  = $this->db->participantById($this->op);

        $id = $this->db->rehomeSession($next, $old, 'tok-abc');

        $this->assertNotSame($this->op, $id, 'a different event means a different row');
        $moved = $this->db->participantById($id);
        $this->assertSame($next, $moved['event']);
        $this->assertSame('Net Control', $moved['key']);
        $this->assertSame('tok-abc', $moved['token']);
    }

    /** The token moves rather than being copied. Two rows answering to one token would
     *  make participantByToken's answer depend on row order. */
    public function testRehomingLeavesTheOldRowUnreachableByToken(): void
    {
        $this->db->upsertParticipant($this->ev, 'operator', 'Net Control', 'Net Control', null, 'tok-abc');
        $old = $this->db->participantByToken('tok-abc');
        $this->assertSame($this->ev, $old['event']);

        $id = $this->db->rehomeSession('Next Event', $old, 'tok-abc');

        $this->assertSame($id, $this->db->participantByToken('tok-abc')['id'],
                          'the token now resolves to the new event only');
        $this->assertNull($this->db->participantById((int)$old['id'])['token']);
    }

    /** Re-homing within the same event is a no-op that must not blank the live token. */
    public function testRehomingIntoTheSameEventKeepsTheSession(): void
    {
        $this->db->upsertParticipant($this->ev, 'operator', 'Net Control', 'Net Control', null, 'tok-abc');
        $old = $this->db->participantByToken('tok-abc');

        $id = $this->db->rehomeSession($this->ev, $old, 'tok-abc');

        $this->assertSame((int)$old['id'], $id);
        $this->assertSame('tok-abc', $this->db->participantById($id)['token']);
    }

    /** A transcriber channel's token lives in its config file forever, so it hits the
     *  same trap an operator's browser token does — and rehoming has to carry the kind
     *  across, not assume 'operator'. */
    public function testRehomingKeepsTheParticipantKind(): void
    {
        $this->db->upsertParticipant($this->ev, 'transcriber', '146520@rx1', '146.520', null, 'tok-rx');
        $old = $this->db->participantByToken('tok-rx');

        $id = $this->db->rehomeSession('Next Event', $old, 'tok-rx');

        $moved = $this->db->participantById($id);
        $this->assertSame('transcriber', $moved['kind']);
        $this->assertSame('146.520', $moved['display_name']);
        $this->assertSame('Next Event', $moved['event']);
    }

    // ── the channel registry ──────────────────────────────────────────────────

    /** Writes a channel registry and returns a $ctx pointing at it. */
    private function registry(array $channels): array
    {
        $f = tempnam(sys_get_temp_dir(), 'chan_') . '.json';
        file_put_contents($f, json_encode(['channels' => $channels]));
        $this->tmpFiles[] = $f;
        return ['event' => $this->ev, 'channelFile' => $f];
    }
    private array $tmpFiles = [];

    public function testAKnownChannelTokenResolves(): void
    {
        $ctx = $this->registry([['id'=>'146520@rx1', 'label'=>'146.520', 'token'=>'tok-rx']]);

        $ch = _msg_find_channel($ctx, 'tok-rx');

        $this->assertSame('146520@rx1', $ch['id']);
        $this->assertSame('146.520', $ch['label']);
    }

    public function testAnUnknownTokenResolvesToNothing(): void
    {
        $ctx = $this->registry([['id'=>'146520@rx1', 'label'=>'146.520', 'token'=>'tok-rx']]);

        $this->assertNull(_msg_find_channel($ctx, 'wrong'));
        $this->assertNull(_msg_find_channel($ctx, ''), 'an empty token must never match');
    }

    /** Switching a channel off in the manager stops it logging immediately, without
     *  waiting for the device to notice its config changed. */
    public function testADisabledChannelIsRefused(): void
    {
        $ctx = $this->registry([
            ['id'=>'146520@rx1', 'label'=>'146.520', 'token'=>'tok-rx', 'enabled'=>false],
        ]);

        $this->assertNull(_msg_find_channel($ctx, 'tok-rx'));
    }

    /** No registry at all is the normal state until the first Transcriber is deployed,
     *  and must not be an error on every authenticated request. */
    public function testAMissingRegistryIsHarmless(): void
    {
        $this->assertNull(_msg_find_channel(['channelFile' => '/nonexistent.json'], 'tok-rx'));
        $this->assertNull(_msg_find_channel([], 'tok-rx'));
    }

    // ── a transcriber channel writing to the log ──────────────────────────────

    /** A channel posts what it heard on the air. Same mechanism as an operator's own
     *  entry — no recipients, so no deliveries, nothing announced, nothing to
     *  acknowledge — but attributed to the frequency rather than to a person. */
    public function testATranscriberChannelCanWriteToTheLog(): void
    {
        $rx   = $this->db->upsertParticipant($this->ev, 'transcriber', '146520@rx1', '146.520', null, 'tok-rx');
        $conv = $this->db->resolveLogConversation($this->ev);

        $this->db->insertMessage($this->ev, $conv, $rx, 'aid three we have a rider down', [], false);

        $row = $this->db->thread($conv, 0)[0];
        $this->assertSame('aid three we have a rider down', $row['text']);
        $this->assertSame('Log', $row['to_label']);
        $this->assertCount(0, $this->db->pendingFor($this->op), 'heard, not sent');
        $this->assertCount(0, $this->db->pendingFor($this->phone));
    }

    /** The log reads "146.520 → Log", which is what keeps a machine-heard line
     *  distinguishable from something net control typed. */
    public function testTheChannelIsTheAuthor(): void
    {
        $rx   = $this->db->upsertParticipant($this->ev, 'transcriber', '146520@rx1', '146.520', null, 'tok-rx');
        $conv = $this->db->resolveLogConversation($this->ev);
        $this->db->insertMessage($this->ev, $conv, $rx, 'copy that', [], false);

        $this->assertSame('146.520', $this->db->thread($conv, 0)[0]['from_name']);
    }

    /** A channel writes and never reads: it must not be offered the log thread, and
     *  cannot be messaged, so it never appears in anyone's conversation list. */
    public function testAChannelIsNotOfferedTheLogToRead(): void
    {
        $rx   = $this->db->upsertParticipant($this->ev, 'transcriber', '146520@rx1', '146.520', null, 'tok-rx');
        $conv = $this->db->resolveLogConversation($this->ev);
        $this->db->insertMessage($this->ev, $conv, $rx, 'anything', [], false);

        $kinds = array_column($this->db->conversationsFor($this->ev, $rx, false), 'kind');

        $this->assertNotContains('log', $kinds);
    }

    // ── the event log ─────────────────────────────────────────────────────────

    /** A log entry is written, not sent. No deliveries means nothing is queued for
     *  anyone to poll, nothing reaches a phone or a watch, and no receipt can come
     *  back — which is the entire distinction between logging and messaging. */
    public function testLogEntryIsDeliveredToNobody(): void
    {
        $conv = $this->db->resolveLogConversation($this->ev);

        $this->db->insertMessage($this->ev, $conv, $this->op, '0930 net opened', [], false);

        $this->assertCount(0, $this->db->pendingFor($this->phone));
        $this->assertCount(0, $this->db->pendingFor($this->op));
        $this->assertCount(0, $this->db->receiptsForSender($this->op, 0));
    }

    /** It is still archived — the point of writing it down. */
    public function testLogEntryIsStoredInTheThread(): void
    {
        $conv = $this->db->resolveLogConversation($this->ev);
        $this->db->insertMessage($this->ev, $conv, $this->op, '0930 net opened', [], false);

        $texts = array_column($this->db->thread($conv, 0), 'text');

        $this->assertSame(['0930 net opened'], $texts);
    }

    /** An entry reads "<who wrote it> → Log". The thread has no members, so deriving a
     *  recipient the usual way produces an empty label and the entry looks addressed to
     *  nobody rather than filed somewhere. Both views have to agree. */
    public function testLogEntryIsLabelledAsGoingToTheLog(): void
    {
        $conv = $this->db->resolveLogConversation($this->ev);
        $this->db->insertMessage($this->ev, $conv, $this->op, '0930 net opened', [], false);

        $this->assertSame('Log', $this->db->thread($conv, 0)[0]['to_label']);

        $hist = array_values(array_filter($this->db->history($this->ev),
                                          fn($m) => (int)$m['conversation_id'] === $conv));
        $this->assertSame('Log', $hist[0]['to_label'], 'the all-messages view agrees');
    }

    /** One log per event, however many operators write to it and whoever writes first
     *  — a second thread would silently split the running log in half. */
    public function testLogConversationIsASingleton(): void
    {
        $other = $this->db->upsertParticipant($this->ev, 'operator', 'Shadow', 'Shadow', null, null);

        $a = $this->db->resolveLogConversation($this->ev);
        $this->db->insertMessage($this->ev, $a, $this->op, 'first', [], false);
        $b = $this->db->resolveLogConversation($this->ev);
        $this->db->insertMessage($this->ev, $b, $other, 'second', [], false);

        $this->assertSame($a, $b);
        $this->assertCount(2, $this->db->thread($a, 0), 'both operators wrote to one log');
    }

    /** Operators see the log listed; mobiles must not. It has no members, so there is
     *  nothing to join against and the flag is the only thing keeping it off a
     *  tracker's conversation list. */
    public function testLogIsListedForOperatorsOnly(): void
    {
        $conv = $this->db->resolveLogConversation($this->ev);
        $this->db->insertMessage($this->ev, $conv, $this->op, '0930 net opened', [], false);

        $opKinds     = array_column($this->db->conversationsFor($this->ev, $this->op, true), 'kind');
        $mobileKinds = array_column($this->db->conversationsFor($this->ev, $this->phone, false), 'kind');

        $this->assertContains('log', $opKinds);
        $this->assertNotContains('log', $mobileKinds);
    }

    /** Nobody is at the other end of the log, so the 24-hour staleness rule — which
     *  asks when the other end was last seen — must not hide it. */
    public function testLogIsNeverStale(): void
    {
        $conv = $this->db->resolveLogConversation($this->ev);
        $this->db->insertMessage($this->ev, $conv, $this->op, '0930 net opened', [], false);

        $log = null;
        foreach ($this->db->conversationsFor($this->ev, $this->op, true) as $c) {
            if ($c['kind'] === 'log') $log = $c;
        }

        $this->assertNotNull($log);
        $this->assertFalse($log['stale']);
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
