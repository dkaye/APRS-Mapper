<?php
/** Tests for renaming an operator: that the write actually lands, that a name held by a
 *  session which has gone can be taken back, and that taking it does not relabel the
 *  traffic that session already sent. */
use PHPUnit\Framework\TestCase;

class MessagingRenameTest extends TestCase
{
    private string $dbFile;
    private MessagingDb $db;
    private string $ev = 'Test Event';

    protected function setUp(): void
    {
        $this->dbFile = tempnam(sys_get_temp_dir(), 'msgren_') . '.db';
        $this->db     = new MessagingDb($this->dbFile);
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbFile, $this->dbFile . '-wal', $this->dbFile . '-shm'] as $f) {
            if (file_exists($f)) unlink($f);
        }
    }

    private function row(int $id): ?array
    {
        return $this->db->participantById($id);
    }

    // ── a write that does not happen must not look like one that did ──────────

    /** The bug underneath all of this: every write went through run(), and run() never
     *  looked at what execute() returned. A statement that violated a constraint warned
     *  into the Apache log, changed nothing, and returned to the caller as success. */
    public function testAWriteThatFailsThrowsInsteadOfReportingSuccess(): void
    {
        $this->db->upsertParticipant($this->ev, 'operator', 'Net Control', 'Net Control', null, null);

        $run = new ReflectionMethod(MessagingDb::class, 'run');

        // A second operator row with the same key in the same event: exactly the
        // collision a rename used to hit, and used to survive without a word.
        $this->expectException(RuntimeException::class);
        $run->invoke($this->db,
            "INSERT INTO participants (event,kind,key,display_name,last_seen)
             VALUES (:e,'operator',:k,:k,0)",
            [':e' => $this->ev, ':k' => 'Net Control']);
    }

    // ── taking a name back from a session that has gone ───────────────────────

    /** UNIQUE(event,kind,key) does not care that a name's holder left days ago, and the
     *  endpoint's clash test — not seen for 90 seconds, so treat it as gone — does. While
     *  they disagreed, every operator name an event had ever used was unclaimable
     *  forever, and asking for one silently did nothing. */
    public function testANameHeldByARetiredSessionCanBeTakenBack(): void
    {
        $old = $this->db->upsertParticipant($this->ev, 'operator', 'Net Control', 'Net Control', null, 'tok-old');
        $new = $this->db->upsertParticipant($this->ev, 'operator', 'test9', 'test9', null, 'tok-new');
        $this->db->disconnectParticipant($old);      // the earlier session has gone

        $this->db->renameParticipant($new, 'Net Control');

        $this->assertSame('Net Control', $this->row($new)['key'],
            'the rename must actually land in the table');
        $this->assertSame('Net Control', $this->row($new)['display_name'],
            'and it is display_name the picker reads');
    }

    /** The retired row keeps its display_name, because that is what old messages are
     *  attributed by. Moving it would relabel traffic that identity really did send. */
    public function testTakingANameDoesNotRelabelWhatTheOldSessionSent(): void
    {
        $old = $this->db->upsertParticipant($this->ev, 'operator', 'Net Control', 'Net Control', null, 'tok-old');
        $new = $this->db->upsertParticipant($this->ev, 'operator', 'test9', 'test9', null, 'tok-new');
        $this->db->disconnectParticipant($old);

        $this->db->renameParticipant($new, 'Net Control');

        $this->assertSame('Net Control', $this->row($old)['display_name'],
            'history stays attributed to the name that sent it');
        $this->assertNotSame('Net Control', $this->row($old)['key'],
            'but the key moves, because that is the column with the constraint on it');
        $this->assertSame(0, (int)$this->row($old)['last_seen'],
            'and the retired row drops out of the addressable list');
        $this->assertNull($this->row($old)['token'],
            'a retired session holds no token');
    }

    /** Two rows, one name, one of them signed out — the picker must not offer it twice.
     *  last_seen=0 is what keeps the retired one out, not the key it was moved to. */
    public function testOnlyOneRowIsLeftAddressableUnderThatName(): void
    {
        $old = $this->db->upsertParticipant($this->ev, 'operator', 'Net Control', 'Net Control', null, 'tok-old');
        $new = $this->db->upsertParticipant($this->ev, 'operator', 'test9', 'test9', null, 'tok-new');
        $this->db->disconnectParticipant($old);
        $this->db->renameParticipant($new, 'Net Control');

        $live = array_filter(
            $this->db->listParticipants($this->ev),
            fn($p) => ($p['kind'] ?? '') === 'operator'
                   && ($p['display_name'] ?? '') === 'Net Control'
                   && (int)($p['last_seen'] ?? 0) > 0);

        $this->assertCount(1, $live);
        $this->assertSame($new, (int)array_values($live)[0]['id']);
    }

    /** The ordinary case still works and touches nothing else. */
    public function testRenamingToAnUnusedNameJustWorks(): void
    {
        $me = $this->db->upsertParticipant($this->ev, 'operator', 'test9', 'test9', null, 'tok');

        $this->db->renameParticipant($me, 'Shuttle Net');

        $this->assertSame('Shuttle Net', $this->row($me)['key']);
        $this->assertSame('Shuttle Net', $this->row($me)['display_name']);
        $this->assertNotEmpty($this->row($me)['token'], 'a live session keeps its token');
    }

    /** Renaming to the name you already have must not retire you into your own way —
     *  the clash lookup excludes the row being renamed. */
    public function testRenamingToYourOwnNameIsHarmless(): void
    {
        $me = $this->db->upsertParticipant($this->ev, 'operator', 'Net Control', 'Net Control', null, 'tok');

        $this->db->renameParticipant($me, 'Net Control');

        $this->assertSame('Net Control', $this->row($me)['key']);
        $this->assertNotEmpty($this->row($me)['token'], 'still signed in');
        $this->assertGreaterThan(0, (int)$this->row($me)['last_seen'], 'still addressable');
    }

    // ── renaming somebody else ────────────────────────────────────────────────
    //
    // Manage operators grew a Rename button on 2026-08-30. The endpoint it calls is the
    // one an operator already used on themselves, now taking an optional id -- so the
    // property worth pinning is that renaming another row leaves the caller's own name
    // alone, which is what a shared code path most easily gets wrong.

    public function testRenamingAnotherOperatorLeavesTheCallerUntouched(): void
    {
        $me    = $this->db->upsertParticipant($this->ev, 'operator', 'Net Control', 'Net Control', null, null);
        $other = $this->db->upsertParticipant($this->ev, 'operator', 'Shadow', 'Shadow', null, null);

        $this->db->renameParticipant($other, 'North Gate');

        $this->assertSame('North Gate', $this->row($other)['display_name']);
        $this->assertSame('North Gate', $this->row($other)['key'], 'key moves with the name');
        $this->assertSame('Net Control', $this->row($me)['display_name'], 'the caller is not renamed');
    }

    /** The id has to select the row, not the position: a rename aimed at one operator
     *  must not land on another with a similar name. */
    public function testARenameLandsOnTheOperatorItNames(): void
    {
        $a = $this->db->upsertParticipant($this->ev, 'operator', 'Net Control', 'Net Control', null, null);
        $b = $this->db->upsertParticipant($this->ev, 'operator', 'Net Control 2', 'Net Control 2', null, null);

        $this->db->renameParticipant($b, 'Relay');

        $this->assertSame('Net Control', $this->row($a)['display_name']);
        $this->assertSame('Relay', $this->row($b)['display_name']);
    }

}
