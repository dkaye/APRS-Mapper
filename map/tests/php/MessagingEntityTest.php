<?php
/** Tests for multi-device entities: one person carrying several phones is one
 *  recipient, one thread, and one row in the picker. */
use PHPUnit\Framework\TestCase;

class MessagingEntityTest extends TestCase
{
    private string $dbFile;
    private MessagingDb $db;
    private string $ev = 'Test Event';
    private int $op, $d1, $d2, $other;

    protected function setUp(): void
    {
        $this->dbFile = tempnam(sys_get_temp_dir(), 'msgent_') . '.db';
        $this->db     = new MessagingDb($this->dbFile);
        $this->op    = $this->db->upsertParticipant($this->ev, 'operator', 'Net Control', 'Net Control', null, null);
        $this->d1    = $this->db->upsertParticipant($this->ev, 'mobile', 'MARSQ-015', 'Stanton', 'CRD', null);
        $this->d2    = $this->db->upsertParticipant($this->ev, 'mobile', 'MARSQ-020', 'Stanton', 'CRD', null);
        $this->other = $this->db->upsertParticipant($this->ev, 'mobile', 'MARSQ-017', 'Dirck', 'LKL', null);
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbFile, $this->dbFile . '-wal', $this->dbFile . '-shm'] as $f) {
            if (file_exists($f)) unlink($f);
        }
    }

    private function send(int $conv, int $from, string $text, array $to): int
    {
        return $this->db->insertMessage($this->ev, $conv, $from, $text, $to, false);
    }

    // ── finding a person's thread from one of their devices ───────────────────

    /** Addressing a device by callsign — what right-clicking a tracker does — must land
     *  in that person's existing entity thread. A direct thread beside it renders from
     *  the same display_id and name, so the operator sees one person listed twice with
     *  their history split between the rows. */
    public function testFindsTheEntityThreadFromOneOfItsDevices(): void
    {
        $ent = $this->db->resolveEntityConversation($this->ev, $this->op, 'CRD', 'Stanton',
                                                    [$this->d1, $this->d2]);

        $this->assertSame($ent, $this->db->entityConversationForDevice($this->ev, $this->op, $this->d1));
        $this->assertSame($ent, $this->db->entityConversationForDevice($this->ev, $this->op, $this->d2),
                          'either phone finds the same person');
    }

    /** A device with no entity thread has nothing to find, and must not be dragged into
     *  somebody else's. */
    public function testDeviceWithNoEntityThreadFindsNothing(): void
    {
        $this->db->resolveEntityConversation($this->ev, $this->op, 'CRD', 'Stanton', [$this->d1, $this->d2]);

        $this->assertNull($this->db->entityConversationForDevice($this->ev, $this->op, $this->other));
    }

    /** The guard that matters most: a "(multiple)" thread is a station's whole group, so
     *  routing one person's message into it would put a private reply in front of
     *  everyone at that station. */
    public function testMultipleThreadIsNeverTreatedAsAPersonsThread(): void
    {
        $this->db->resolveEntityConversation($this->ev, $this->op, 'CRD', '*', [$this->d1, $this->d2]);

        $this->assertNull($this->db->entityConversationForDevice($this->ev, $this->op, $this->d1));
    }

    /** One operator's entity thread is not another's — threads are keyed per operator,
     *  and a second operator must not be dropped into the first one's conversation. */
    public function testAnotherOperatorDoesNotInheritTheThread(): void
    {
        $this->db->resolveEntityConversation($this->ev, $this->op, 'CRD', 'Stanton', [$this->d1]);
        $op2 = $this->db->upsertParticipant($this->ev, 'operator', 'Shadow', 'Shadow', null, null);

        $this->assertNull($this->db->entityConversationForDevice($this->ev, $op2, $this->d1));
    }

    // ── whose name is on the thread ───────────────────────────────────────────

    /** The addresser sees who they addressed. */
    public function testTheAddresserSeesTheEntityTitle(): void
    {
        $ent = $this->db->resolveEntityConversation($this->ev, $this->op, 'CRD', 'Stanton', [$this->d1]);
        $this->send($ent, $this->op, 'Radio check', [$this->d1]);

        $this->assertSame('CRD Stanton', $this->conv($this->op, $ent)['title']);
    }

    /** The person addressed must not. The title names *them*, so showing it would put
     *  their own name in their conversation list where the caller should be — the
     *  clients prefer title over members, so they would never see who called. */
    public function testTheEntitySeesTheCallerNotItsOwnName(): void
    {
        $ent = $this->db->resolveEntityConversation($this->ev, $this->op, 'CRD', 'Stanton', [$this->d1]);
        $this->send($ent, $this->op, 'Radio check', [$this->d1]);

        $row = $this->conv($this->d1, $ent);

        $this->assertNull($row['title'], 'the title is about the viewer, so it is suppressed');
        $this->assertSame(['Net Control'], array_column($row['members'], 'display_name'),
                          'leaving the caller as the only thing to label it with');
    }

    /** Mobile-to-mobile entity threads have the same shape and the same trap. */
    public function testTheSameHoldsBetweenTwoMobiles(): void
    {
        $ent = $this->db->resolveEntityConversation($this->ev, $this->other, 'CRD', 'Stanton', [$this->d1]);
        $this->send($ent, $this->other, 'You there?', [$this->d1]);

        $this->assertSame('CRD Stanton', $this->conv($this->other, $ent)['title']);
        $this->assertNull($this->conv($this->d1, $ent)['title']);
    }

    /** A direct thread has no title to suppress, and must keep working untouched. */
    public function testDirectThreadsAreUnaffected(): void
    {
        [$c, ] = $this->db->resolveConversation($this->ev, $this->op, [$this->d1], false, null);
        $this->send($c, $this->op, 'Hello', [$this->d1]);

        $row = $this->conv($this->d1, $c);

        $this->assertNull($row['title'] ?? null);
        $this->assertSame(['Net Control'], array_column($row['members'], 'display_name'));
    }

    private function conv(int $me, int $conversationId): array
    {
        foreach ($this->db->conversationsFor($this->ev, $me, true) as $c) {
            if ((int)$c['id'] === $conversationId) return $c;
        }
        $this->fail("conversation $conversationId not returned for participant $me");
    }

    // ── entity key ────────────────────────────────────────────────────────────

    public function testEntityHashIgnoresSurroundingWhitespace(): void
    {
        $this->assertSame(MessagingDb::entityHash('CRD', 'Stanton'),
                          MessagingDb::entityHash('  CRD ', ' Stanton  '));
    }

    public function testDifferentNamesUnderOneIdAreDifferentEntities(): void
    {
        $this->assertNotSame(MessagingDb::entityHash('LKL', 'Dirck'),
                             MessagingDb::entityHash('LKL', 'Jerry'));
    }

    // ── thread stability ──────────────────────────────────────────────────────

    /** The point of the entity thread: phones come and go, the conversation doesn't. */
    public function testThreadSurvivesDeviceSetChanges(): void
    {
        $both = $this->db->resolveEntityConversation($this->ev, $this->op, 'CRD', 'Stanton', [$this->d1, $this->d2]);
        $one  = $this->db->resolveEntityConversation($this->ev, $this->op, 'CRD', 'Stanton', [$this->d1]);
        $back = $this->db->resolveEntityConversation($this->ev, $this->op, 'CRD', 'Stanton', [$this->d1, $this->d2]);
        $this->assertSame($both, $one, 'a phone going offline must not fork the thread');
        $this->assertSame($both, $back, 'the phone returning must not fork the thread');
    }

    public function testEachOperatorGetsItsOwnEntityThread(): void
    {
        $op2 = $this->db->upsertParticipant($this->ev, 'operator', 'Starlink Control', 'Starlink Control', null, null);
        $a = $this->db->resolveEntityConversation($this->ev, $this->op, 'CRD', 'Stanton', [$this->d1]);
        $b = $this->db->resolveEntityConversation($this->ev, $op2,      'CRD', 'Stanton', [$this->d1]);
        $this->assertNotSame($a, $b);
    }

    public function testEntityAndMultipleAreDistinctKinds(): void
    {
        $ent  = $this->db->resolveEntityConversation($this->ev, $this->op, 'LKL', 'Dirck', [$this->other]);
        $mult = $this->db->resolveEntityConversation($this->ev, $this->op, 'LKL', '*', [$this->other]);
        $this->assertNotSame($ent, $mult);
        $this->assertSame('entity',       $this->db->conversationKind($ent));
        $this->assertSame('entity_multi', $this->db->conversationKind($mult));
    }

    public function testMultipleThreadIsTitledForTheStation(): void
    {
        $mult = $this->db->resolveEntityConversation($this->ev, $this->op, 'LKL', '*', [$this->other]);
        $this->assertSame('LKL (multiple)', $this->db->conversationTitle($mult));
    }

    public function testEntityOfConversationRoundTrips(): void
    {
        $c = $this->db->resolveEntityConversation($this->ev, $this->op, 'CRD', 'Stanton', [$this->d1]);
        $this->assertSame(['CRD', 'Stanton'], $this->db->entityOfConversation($c));
        $m = $this->db->resolveEntityConversation($this->ev, $this->op, 'LKL', '*', [$this->other]);
        $this->assertSame(['LKL', '*'], $this->db->entityOfConversation($m));
    }

    // ── merge-time migration ──────────────────────────────────────────────────

    public function testMergeFoldsPriorOneToOneHistoryIn(): void
    {
        [$c1] = $this->db->resolveConversation($this->ev, $this->op, [$this->d1], false, null);
        [$c2] = $this->db->resolveConversation($this->ev, $this->op, [$this->d2], false, null);
        $this->send($c1, $this->op, 'to phone one', [$this->d1]);
        $this->send($c2, $this->op, 'to phone two', [$this->d2]);

        $ent   = $this->db->resolveEntityConversation($this->ev, $this->op, 'CRD', 'Stanton', [$this->d1, $this->d2]);
        $moved = $this->db->migrateThreadsIntoEntity($this->ev, $ent, $this->op, [$this->d1, $this->d2]);

        $this->assertSame(2, $moved);
        $this->assertCount(2, $this->db->thread($ent, 0), 'both histories now live in the entity thread');
    }

    /** The emptied threads must vanish from the list — that duplicate row is the
     *  whole reason merging exists — without being deleted, so undo has a home. */
    public function testEmptiedThreadsAreHiddenButNotDeleted(): void
    {
        [$c1] = $this->db->resolveConversation($this->ev, $this->op, [$this->d1], false, null);
        $this->send($c1, $this->op, 'hello', [$this->d1]);
        $ent = $this->db->resolveEntityConversation($this->ev, $this->op, 'CRD', 'Stanton', [$this->d1, $this->d2]);
        $this->db->migrateThreadsIntoEntity($this->ev, $ent, $this->op, [$this->d1, $this->d2]);

        $listed = array_column($this->db->conversationsFor($this->ev, $this->op), 'id');
        $this->assertNotContains($c1, $listed, 'emptied thread must not still be listed');
        $this->assertContains($ent, $listed);
        $this->assertNotNull($this->db->conversationKind($c1), 'row must survive for undo');
    }

    /** A mistyped display_id must never drag in someone else's conversation. */
    public function testMigrationIgnoresThreadsWithoutTheOperator(): void
    {
        [$peer] = $this->db->resolveConversation($this->ev, $this->d1, [$this->other], false, null);
        $this->send($peer, $this->d1, 'mobile to mobile', [$this->other]);

        $ent   = $this->db->resolveEntityConversation($this->ev, $this->op, 'CRD', 'Stanton', [$this->d1]);
        $moved = $this->db->migrateThreadsIntoEntity($this->ev, $ent, $this->op, [$this->d1]);

        $this->assertSame(0, $moved);
        $this->assertCount(1, $this->db->thread($peer, 0), 'peer conversation untouched');
    }

    public function testMigrationIgnoresGroupThreads(): void
    {
        [$grp, $kind] = $this->db->resolveConversation($this->ev, $this->op, [$this->d1, $this->other], false, null);
        $this->assertSame('group', $kind);
        $this->send($grp, $this->op, 'group note', [$this->d1, $this->other]);

        $ent   = $this->db->resolveEntityConversation($this->ev, $this->op, 'CRD', 'Stanton', [$this->d1]);
        $moved = $this->db->migrateThreadsIntoEntity($this->ev, $ent, $this->op, [$this->d1]);

        $this->assertSame(0, $moved);
        $this->assertCount(1, $this->db->thread($grp, 0));
    }

    // ── undo ──────────────────────────────────────────────────────────────────

    public function testUndoRestoresEveryMessageToItsOriginalThread(): void
    {
        [$c1] = $this->db->resolveConversation($this->ev, $this->op, [$this->d1], false, null);
        [$c2] = $this->db->resolveConversation($this->ev, $this->op, [$this->d2], false, null);
        $this->send($c1, $this->op, 'one', [$this->d1]);
        $this->send($c1, $this->op, 'two', [$this->d1]);
        $this->send($c2, $this->op, 'three', [$this->d2]);

        $ent = $this->db->resolveEntityConversation($this->ev, $this->op, 'CRD', 'Stanton', [$this->d1, $this->d2]);
        $this->db->migrateThreadsIntoEntity($this->ev, $ent, $this->op, [$this->d1, $this->d2]);
        $this->assertCount(3, $this->db->thread($ent, 0));

        $ts   = $this->db->lastMergeTs($this->ev);
        $back = $this->db->undoMerge($this->ev, $ts);

        $this->assertSame(3, $back);
        $this->assertCount(2, $this->db->thread($c1, 0));
        $this->assertCount(1, $this->db->thread($c2, 0));
        $this->assertCount(0, $this->db->thread($ent, 0));
        $listed = array_column($this->db->conversationsFor($this->ev, $this->op), 'id');
        $this->assertContains($c1, $listed, 'restored thread must be listable again');
        $this->assertContains($c2, $listed);
    }
}
