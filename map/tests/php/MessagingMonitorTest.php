<?php
/** Tests for the opt-in monitor feed — the read-only view of an event's traffic that
 *  lets a phone hear everything rather than only what was addressed to it.
 *
 *  The feed exists because pollFor()/pendingFor() are INNER JOINs on `deliveries`, so
 *  a message with no delivery row for you is unreachable by construction. The cheap
 *  way to lift that — give the monitoring device delivery rows and reuse pollFor() —
 *  silently corrupts every sender's receipts, event-wide. That is what most of this
 *  file is guarding. */
use PHPUnit\Framework\TestCase;

class MessagingMonitorTest extends TestCase
{
    private string $dbFile;
    private MessagingDb $db;
    private string $ev = 'Monitor Test';
    private int $op, $alice, $bob, $carol, $radio;

    protected function setUp(): void
    {
        $this->dbFile = tempnam(sys_get_temp_dir(), 'msgmon_') . '.db';
        $this->db     = new MessagingDb($this->dbFile);
        $this->op     = $this->db->upsertParticipant($this->ev, 'operator', 'Net Control', 'Net Control', null, null);
        $this->alice  = $this->db->upsertParticipant($this->ev, 'mobile', 'MARSQ-001', 'Alice', 'M001', null);
        $this->bob    = $this->db->upsertParticipant($this->ev, 'mobile', 'MARSQ-002', 'Bob',   'M002', null);
        // Carol monitors. She is addressed by nobody in any of these tests.
        $this->carol  = $this->db->upsertParticipant($this->ev, 'mobile', 'MARSQ-003', 'Carol', 'M003', null);
        $this->radio  = $this->db->upsertParticipant($this->ev, 'transcriber', 'ch-simulcast', 'Simulcast', null, null);
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

    /** A Transcriber entry: a message addressed to nobody, in the log thread. */
    private function logEntry(string $text): int
    {
        return $this->db->insertMessage($this->ev, $this->db->resolveLogConversation($this->ev),
                                        $this->radio, $text, [], false);
    }

    private function monitorAll(int $since = 0): array
    {
        return $this->db->monitor($this->ev, $since, true, true);
    }

    // ── the point of the feature ──────────────────────────────────────────────

    /** Carol is not a recipient, so pollFor() cannot reach this message. The monitor
     *  feed is the only thing that can, and that is what it is for. */
    public function testMonitorSeesTrafficAddressedToSomebodyElse(): void
    {
        $conv = $this->direct($this->op, $this->alice);
        $this->db->insertMessage($this->ev, $conv, $this->op, 'Alice, report to Rest 3', [$this->alice], false);

        $this->assertSame([], $this->db->pollFor($this->carol, 0), 'precondition: not deliverable to Carol');

        $texts = array_column($this->monitorAll()['messages'], 'text');
        $this->assertSame(['Alice, report to Rest 3'], $texts);
    }

    /** Log entries have no delivery rows at all, so they are invisible to every
     *  client protocol. `includeLog` is what surfaces the radio. */
    public function testLogEntriesAppearOnlyWhenIncludeLog(): void
    {
        $this->logEntry('KJ6ABC clear of Bolinas');

        $this->assertSame([], $this->db->monitor($this->ev, 0, true, false)['messages'],
                          'radio traffic must not arrive for a subscriber who asked only for messages');
        $this->assertSame(['KJ6ABC clear of Bolinas'],
                          array_column($this->db->monitor($this->ev, 0, false, true)['messages'], 'text'));
    }

    /** "Radio only" must exclude ordinary traffic — the two flags are independent
     *  filters, not a single verbosity dial. */
    public function testRadioOnlyExcludesOrdinaryMessages(): void
    {
        $conv = $this->direct($this->op, $this->alice);
        $this->db->insertMessage($this->ev, $conv, $this->op, 'private note', [$this->alice], false);
        $this->logEntry('heard on the air');

        $texts = array_column($this->db->monitor($this->ev, 0, false, true)['messages'], 'text');
        $this->assertSame(['heard on the air'], $texts);
    }

    /** Subscribed to nothing means nothing, not everything. */
    public function testNoSubscriptionReturnsNothing(): void
    {
        $this->logEntry('anything');
        $res = $this->db->monitor($this->ev, 0, false, false);
        $this->assertSame([], $res['messages']);
        $this->assertSame(0, $res['skipped']);
    }

    // ── the regression that must never ship ───────────────────────────────────

    /** THE test in this file. Monitoring must not create delivery rows, because
     *  receiptsForSender() counts every delivery row a message has: one extra turns
     *  a 1:1 into "Delivered to 1 of 2" for the sender, forever, and its `pending`
     *  flag stops clearing. This is silent and event-wide. */
    public function testMonitoringDoesNotCorruptSenderReceipts(): void
    {
        $conv = $this->direct($this->op, $this->alice);
        $mid  = $this->db->insertMessage($this->ev, $conv, $this->op, 'Radio check', [$this->alice], false);

        $this->monitorAll();
        $this->monitorAll();                      // polled repeatedly, as a phone would

        $receipts = $this->db->receiptsForSender($this->op, 0);
        $row = null;
        foreach ($receipts as $r) if ((int)$r['message_id'] === $mid) $row = $r;

        $this->assertNotNull($row);
        $this->assertSame(1, (int)$row['total'],
                          'a monitoring device must not be counted as a recipient');
    }

    /** The same guarantee stated directly against the table, so a future
     *  implementation that reaches the right receipt total by some other route still
     *  fails if it writes rows. */
    public function testMonitoringWritesNoDeliveryRows(): void
    {
        $conv = $this->direct($this->op, $this->alice);
        $this->db->insertMessage($this->ev, $conv, $this->op, 'Radio check', [$this->alice], false);
        $this->logEntry('and a log entry');

        $before = $this->countDeliveries();
        $this->monitorAll();
        $this->assertSame($before, $this->countDeliveries());
    }

    private function countDeliveries(): int
    {
        $db = new SQLite3($this->dbFile);
        $n  = (int)$db->querySingle('SELECT COUNT(*) FROM deliveries');
        $db->close();
        return $n;
    }

    /** A monitoring client must not be able to mark somebody else's message read.
     *  markRead() on an id it holds no delivery row for is already a no-op; this
     *  pins that, because the client does hold those ids. */
    public function testMonitorCannotMarkAnotherPartysMessageRead(): void
    {
        $conv = $this->direct($this->op, $this->alice);
        $mid  = $this->db->insertMessage($this->ev, $conv, $this->op, 'Radio check', [$this->alice], false);

        $this->db->markRead($this->carol, [$mid]);
        $this->db->markDelivered($this->carol, [$mid]);

        $row = null;
        foreach ($this->db->receiptsForSender($this->op, 0) as $r) {
            if ((int)$r['message_id'] === $mid) $row = $r;
        }
        $this->assertSame(1, (int)$row['total']);
        $this->assertSame(0, (int)$row['read']);
        $this->assertSame(0, (int)$row['delivered']);
    }

    // ── bounded catch-up ──────────────────────────────────────────────────────

    /** After an outage the feed is bounded — but it says so. A silent truncation
     *  reads to the user as "nothing happened", which is the opposite of the truth. */
    public function testBacklogIsBoundedAndReportsWhatItSkipped(): void
    {
        $conv = $this->direct($this->op, $this->alice);
        $n    = MessagingDb::MONITOR_MAX_RESULTS + 20;
        for ($i = 0; $i < $n; $i++) {
            $this->db->insertMessage($this->ev, $conv, $this->op, "msg $i", [$this->alice], false);
        }

        $res = $this->monitorAll();

        $this->assertCount(MessagingDb::MONITOR_MAX_RESULTS, $res['messages']);
        $this->assertSame(20, $res['skipped']);
    }

    /** What survives the bound is the RECENT end. Coming back from an outage mid-net,
     *  the last few minutes are what is worth hearing; the start of the backlog is
     *  history the event has already moved past. */
    public function testBoundedBacklogKeepsTheNewestMessages(): void
    {
        $conv = $this->direct($this->op, $this->alice);
        $n    = MessagingDb::MONITOR_MAX_RESULTS + 5;
        for ($i = 0; $i < $n; $i++) {
            $this->db->insertMessage($this->ev, $conv, $this->op, "msg $i", [$this->alice], false);
        }

        $texts = array_column($this->monitorAll()['messages'], 'text');

        $this->assertSame('msg ' . ($n - 1), end($texts), 'newest must be present');
        $this->assertSame('msg 5', $texts[0], 'oldest 5 must be the ones dropped');
    }

    /** Messages older than the age bound are skipped, not returned. */
    public function testMessagesOlderThanTheAgeBoundAreSkipped(): void
    {
        $conv = $this->direct($this->op, $this->alice);
        $old  = $this->db->insertMessage($this->ev, $conv, $this->op, 'ancient', [$this->alice], false);
        $this->backdate($old, time() - MessagingDb::MONITOR_MAX_AGE - 60);
        $this->db->insertMessage($this->ev, $conv, $this->op, 'recent', [$this->alice], false);

        $res = $this->monitorAll();

        $this->assertSame(['recent'], array_column($res['messages'], 'text'));
        $this->assertSame(1, $res['skipped']);
    }

    /** last_id is the high-water mark of everything matched, not of what was sent.
     *  If it only covered the returned rows, a client that was bounded would ask for
     *  the same skipped range on every poll and never get past it. */
    public function testCursorAdvancesPastSkippedMessages(): void
    {
        $conv = $this->direct($this->op, $this->alice);
        $old  = $this->db->insertMessage($this->ev, $conv, $this->op, 'ancient', [$this->alice], false);
        $this->backdate($old, time() - MessagingDb::MONITOR_MAX_AGE - 60);
        $newest = $this->db->insertMessage($this->ev, $conv, $this->op, 'recent', [$this->alice], false);

        $first = $this->monitorAll();
        $this->assertSame($newest, $first['last_id']);

        $second = $this->monitorAll($first['last_id']);
        $this->assertSame([], $second['messages'], 'the skipped range must not come back');
        $this->assertSame(0, $second['skipped']);
    }

    /** The long-outage case, and the one that actually distinguishes a correct cursor
     *  from `max(id of the rows I returned)`: after half an hour away, EVERY pending
     *  message is older than the age bound, so nothing is returned at all. If the
     *  cursor tracked the returned rows it would not move, and the phone would be told
     *  "200 messages skipped" again on every poll for the rest of the event. */
    public function testCursorAdvancesEvenWhenEverythingIsTooOldToReturn(): void
    {
        $conv = $this->direct($this->op, $this->alice);
        $ids  = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->db->insertMessage($this->ev, $conv, $this->op, "old $i", [$this->alice], false);
        }
        foreach ($ids as $id) $this->backdate($id, time() - MessagingDb::MONITOR_MAX_AGE - 600);

        $first = $this->monitorAll();
        $this->assertSame([], $first['messages']);
        $this->assertSame(5, $first['skipped']);
        $this->assertSame(end($ids), $first['last_id'], 'cursor must clear the skipped range');

        $second = $this->monitorAll($first['last_id']);
        $this->assertSame(0, $second['skipped'], 'the same gap must not be reported twice');
    }

    /** An empty poll leaves the cursor where it was rather than resetting it to 0. */
    public function testEmptyPollPreservesTheCursor(): void
    {
        $conv = $this->direct($this->op, $this->alice);
        $mid  = $this->db->insertMessage($this->ev, $conv, $this->op, 'only one', [$this->alice], false);

        $res = $this->monitorAll($mid);
        $this->assertSame([], $res['messages']);
        $this->assertSame($mid, $res['last_id']);
    }

    private function backdate(int $mid, int $ts): void
    {
        $db = new SQLite3($this->dbFile);
        $db->exec('UPDATE messages SET ts=' . (int)$ts . ' WHERE id=' . (int)$mid);
        $db->close();
    }

    // ── shape ─────────────────────────────────────────────────────────────────

    /** The client renders "From → To", so to_label has to be on the wire. history()
     *  already derived it and the monitor feed shares that code. */
    public function testCarriesToLabel(): void
    {
        $conv = $this->direct($this->op, $this->alice);
        $this->db->insertMessage($this->ev, $conv, $this->op, 'hello', [$this->alice], false);

        $m = $this->monitorAll()['messages'][0];
        $this->assertSame('Net Control', $m['from_name']);
        $this->assertSame('M001 Alice', $m['to_label']);
    }

    /** A log entry has no recipients, so the only honest label is where it went. */
    public function testLogEntriesAreLabelledLog(): void
    {
        $this->logEntry('KJ6ABC clear');
        $m = $this->db->monitor($this->ev, 0, false, true)['messages'][0];
        $this->assertSame('Log', $m['to_label']);
        $this->assertSame('Simulcast', $m['from_name']);
    }

    /** has_audio is a flag, never bytes: a phone that did not opt into audio must be
     *  able to read the feed without spending anything on it. */
    public function testAudioIsAdvertisedNotEmbedded(): void
    {
        $mid = $this->logEntry('with a recording');
        $this->db->setAudio($mid, '123-abcdef012345.m4a', 4.5);

        $m = $this->db->monitor($this->ev, 0, false, true)['messages'][0];
        $this->assertTrue($m['has_audio']);
        $this->assertSame(4.5, $m['audio_secs']);
        $this->assertStringContainsString('123-abcdef012345.m4a', $m['audio_url']);
        $this->assertArrayNotHasKey('audio_data', $m);
    }

    /** An entry with no clip says so, rather than offering a URL that 404s. */
    public function testEntryWithoutAudioAdvertisesNone(): void
    {
        $this->logEntry('no recording');
        $m = $this->db->monitor($this->ev, 0, false, true)['messages'][0];
        $this->assertFalse($m['has_audio']);
        $this->assertNull($m['audio_url']);
    }

    /** Audio does not travel in the photo column, so an entry with a clip must not
     *  read as an entry with a picture. */
    public function testAudioDoesNotMasqueradeAsAPhoto(): void
    {
        $mid = $this->logEntry('with a recording');
        $this->db->setAudio($mid, '123-abcdef012345.m4a', 4.5);

        $m = $this->db->monitor($this->ev, 0, false, true)['messages'][0];
        $this->assertFalse($m['photo']);
    }

    /** The feed is event-scoped. A participant left homed in a previous event must
     *  not be served the current one's traffic — the reason `mobile` was added to the
     *  rehomeSession list. */
    public function testFeedIsScopedToOneEvent(): void
    {
        $other = 'Some Other Event';
        $op2   = $this->db->upsertParticipant($other, 'operator', 'Net Control', 'Net Control', null, null);
        $ph2   = $this->db->upsertParticipant($other, 'mobile', 'MARSQ-009', 'Elsewhere', 'M009', null);
        [$c2, ] = $this->db->resolveConversation($other, $op2, [$ph2], false, null);
        $this->db->insertMessage($other, $c2, $op2, 'other event traffic', [$ph2], false);

        $conv = $this->direct($this->op, $this->alice);
        $this->db->insertMessage($this->ev, $conv, $this->op, 'this event traffic', [$this->alice], false);

        $texts = array_column($this->monitorAll()['messages'], 'text');
        $this->assertSame(['this event traffic'], $texts);
    }

    // ── audio-first entries ───────────────────────────────────────────────────

    /** The recording is posted the moment the over ends and the words follow, so the
     *  same row gains its text later rather than a second row appearing beside it. */
    public function testTextCanBeFilledInLater(): void
    {
        $mid = $this->db->insertMessage($this->ev, $this->db->resolveLogConversation($this->ev),
                                        $this->radio, '', [], false);
        $this->assertTrue($this->db->setMessageText($mid, 'KJ6ABC clear of Bolinas'));
        $this->assertSame('KJ6ABC clear of Bolinas', $this->db->messageById($mid)['text']);
    }

    /** The outbox retries, so the same completion can arrive twice. The second must not
     *  land — otherwise a late duplicate could overwrite a corrected entry. */
    public function testFillingInTextTwiceIsRefused(): void
    {
        $mid = $this->db->insertMessage($this->ev, $this->db->resolveLogConversation($this->ev),
                                        $this->radio, '', [], false);
        $this->assertTrue($this->db->setMessageText($mid, 'first'));
        $this->assertFalse($this->db->setMessageText($mid, 'second'),
                           'an entry that already has text must not be rewritten');
        $this->assertSame('first', $this->db->messageById($mid)['text']);
    }

    /** history() is the WRITTEN log. An entry that only ever had audio -- because its
     *  transcription was discarded as a hallucination -- has nothing to show there. */
    public function testTextlessEntriesAreNotInTheWrittenLog(): void
    {
        $withText = $this->logEntry('KJ6ABC clear');
        $audioOnly = $this->db->insertMessage($this->ev, $this->db->resolveLogConversation($this->ev),
                                              $this->radio, '', [], false);

        $ids = array_column($this->db->history($this->ev), 'id');
        $this->assertContains($withText, $ids);
        $this->assertNotContains($audioOnly, $ids, 'a blank row has nothing to write');
    }

    /** But the monitor feed DOES carry them, because that is how the audio is reached.
     *  Filtering them there would make an unlogged over silent as well as unwritten. */
    public function testTextlessEntriesStillReachTheMonitorFeed(): void
    {
        $mid = $this->db->insertMessage($this->ev, $this->db->resolveLogConversation($this->ev),
                                        $this->radio, '', [], false);
        $this->db->setAudio($mid, $mid . '-aaaaaaaaaaaa.m4a', 3.0);

        $msgs = $this->db->monitor($this->ev, 0, false, true)['messages'];
        $ids = array_column($msgs, 'id');
        $this->assertContains($mid, $ids);
        $this->assertTrue($msgs[0]['has_audio']);
    }

    /** The row is only ever removed in the one case where its clip could not be stored,
     *  a moment after it was made and before anyone could have seen it. */
    public function testDeleteRemovesTheRow(): void
    {
        $mid = $this->db->insertMessage($this->ev, $this->db->resolveLogConversation($this->ev),
                                        $this->radio, '', [], false);
        $this->db->deleteMessage($mid);
        $this->assertNull($this->db->messageById($mid));
    }

    // ── audio storage and expiry ──────────────────────────────────────────────

    /** Clips expire; the log entry does not. The transcription is the record and the
     *  recording is a check on it, so losing the audio must not lose the line. */
    public function testPruneRemovesOldClipsButKeepsTheEntry(): void
    {
        $dir = MessagingDb::audioDir($this->ev);
        @mkdir($dir, 0755, true);
        $old = $this->logEntry('an hour ago');
        $new = $this->logEntry('just now');
        foreach ([$old, $new] as $id) {
            $fn = $id . '-aaaaaaaaaaaa.m4a';
            file_put_contents($dir . '/' . $fn, 'x');
            $this->db->setAudio($id, $fn, 3.0);
        }
        $this->backdate($old, time() - MessagingDb::AUDIO_MAX_AGE - 60);

        $this->assertSame(1, $this->db->pruneAudio($this->ev));

        // history(), not monitor(): the backdated entry is also outside the monitor
        // feed's own age bound, which would hide it for an unrelated reason.
        $msgs = $this->db->history($this->ev);
        $this->assertCount(2, $msgs, 'the entries themselves must survive');
        $this->assertFalse($msgs[0]['has_audio'], 'expired clip must not still be advertised');
        $this->assertTrue($msgs[1]['has_audio']);
        $this->assertFileDoesNotExist($dir . '/' . $old . '-aaaaaaaaaaaa.m4a');
        $this->assertFileExists($dir . '/' . $new . '-aaaaaaaaaaaa.m4a');

        foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($dir);
    }

    /** A URL is only ever advertised for a clip that is actually there. */
    public function testPruneIsIdempotent(): void
    {
        $this->logEntry('no clip at all');
        $this->assertSame(0, $this->db->pruneAudio($this->ev));
        $this->assertSame(0, $this->db->pruneAudio($this->ev));
    }

    /** Radio audio lives inside the web root so Apache can serve it; photos must not
     *  follow it there. If these two ever return the same tree, the reasoning in
     *  audioDir() has been lost and private attachments have become public. */
    public function testAudioAndPhotoStorageAreSeparateTrees(): void
    {
        $this->assertNotSame(MessagingDb::audioDir($this->ev), MessagingDb::photoDir($this->ev));
        $this->assertStringNotContainsString(MessagingDb::photoBaseDir(), MessagingDb::audioDir($this->ev));
    }

    /** Event names reach the filesystem, so they are sanitised the same way photoDir()
     *  does it — a path separator in an event name must not escape the audio root. */
    public function testAudioDirSanitisesTheEventName(): void
    {
        $dir = MessagingDb::audioDir('../../etc');
        $this->assertStringNotContainsString('..', $dir);
        $this->assertStringStartsWith(MARSAPRS_AUDIO_ROOT . '/', $dir);
        $url = MessagingDb::audioUrl('../../etc', 'x.m4a');
        $this->assertStringNotContainsString('..', $url);
    }

    /** Ordering is by id, ascending, like every other feed — a device with a skewed
     *  clock must not be able to reorder a net's traffic. */
    public function testOrdersByIdAscending(): void
    {
        $conv = $this->direct($this->op, $this->alice);
        $a = $this->db->insertMessage($this->ev, $conv, $this->op, 'first',  [$this->alice], false);
        $b = $this->db->insertMessage($this->ev, $conv, $this->op, 'second', [$this->alice], false);

        $this->assertSame([$a, $b], array_column($this->monitorAll()['messages'], 'id'));
    }

    // ── late transcriptions, and the promise made to older clients ────────────
    //
    // A Transcriber posts the audio the instant the over ends -- that POST creates the
    // entry -- and comes back with the words once whisper has them. A client polling on
    // an id cursor can fetch the entry inside that window, take the audio, advance past
    // the id, and never be offered the text again. That is what happened on 2026-08-29:
    // four consecutive overs arrived on the phones as recordings with no transcription
    // while the server held both halves correctly.

    /** The bug, stated as a test: an id cursor cannot reach a row that changed. */
    public function testAnIdCursorNeverSeesATranscriptionThatArrivedLate(): void
    {
        $id = $this->logEntry('');                 // audio posted; whisper still running
        $first = $this->monitorAll(0);
        $this->assertSame([''], array_column($first['messages'], 'text'));
        $cursor = $first['last_id'];

        $this->db->setMessageText($id, 'Aid three we have a rider down.');

        $this->assertSame([], $this->monitorAll($cursor)['messages'],
            'an id cursor is blind to a row it has already passed');
    }

    /** The fix, and it must be asked for. */
    public function testAskingForLateTextGetsTheCompletedRowBack(): void
    {
        $id = $this->logEntry('');
        $first = $this->db->monitor($this->ev, 0, true, true, 0, 1);
        $cursor = $first['last_id'];
        $textCursor = $first['text_ts'];

        $this->db->setMessageText($id, 'Aid three we have a rider down.');

        $again = $this->db->monitor($this->ev, $cursor, true, true, 0, $textCursor);
        $this->assertSame(['Aid three we have a rider down.'],
                          array_column($again['messages'], 'text'));
        $this->assertSame($id, (int)$again['messages'][0]['id'],
                          'the same row, not a second one');
    }

    /** The promise to v1.25.4, which is the whole reason this is opt-in. That client
     *  appends monitor results without deduping and queues clips with no msgId, so a
     *  row it already holds would replay the recording. It must never be sent one. */
    public function testAClientThatDoesNotAskIsNeverSentARowTwice(): void
    {
        $id = $this->logEntry('');
        $cursor = $this->monitorAll(0)['last_id'];
        $this->db->setMessageText($id, 'Aid three we have a rider down.');

        $this->assertSame([], $this->monitorAll($cursor)['messages'],
            'no since_text_ts means no repeats, exactly as before this existed');
    }

    /** Having been given the completed row once, do not give it again. */
    public function testACompletedRowIsNotResentOnceItsStampIsPassed(): void
    {
        $id = $this->logEntry('');
        $first = $this->db->monitor($this->ev, 0, true, true, 0, 1);
        $this->db->setMessageText($id, 'Aid three we have a rider down.');
        $second = $this->db->monitor($this->ev, $first['last_id'], true, true, 0, $first['text_ts']);
        $this->assertCount(1, $second['messages']);

        $third = $this->db->monitor($this->ev, $second['last_id'], true, true, 0, $second['text_ts']);
        $this->assertSame([], $third['messages'], 'the text cursor has to advance too');
    }

    /** An ordinary message carries its text from the start, so nothing stamps it and
     *  it cannot come back a second time to a client that asked for late text. */
    public function testAMessageThatWasNeverEmptyIsNotTreatedAsUpdated(): void
    {
        $this->logEntry('Net control, all stations, radio check.');
        $first = $this->db->monitor($this->ev, 0, true, true, 0, 1);
        $second = $this->db->monitor($this->ev, $first['last_id'], true, true, 0, $first['text_ts']);
        $this->assertSame([], $second['messages']);
    }

    /** setMessageText refuses to overwrite, and must not restamp either -- an outbox
     *  retry landing after the words are in would otherwise re-send a finished row to
     *  every monitoring phone. */
    public function testADuplicateFillInDoesNotRestampTheRow(): void
    {
        $id = $this->logEntry('');
        $this->db->setMessageText($id, 'Aid three we have a rider down.');
        $after = $this->db->monitor($this->ev, 0, true, true, 0, 1);

        $this->assertFalse($this->db->setMessageText($id, 'something else entirely'));
        $again = $this->db->monitor($this->ev, $after['last_id'], true, true, 0, $after['text_ts']);
        $this->assertSame([], $again['messages']);
    }

}
