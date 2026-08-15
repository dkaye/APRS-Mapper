<?php
/** Tests for the Transcriber channel registry.
 *
 *  The registry holds two kinds of token — one a Pi fetches its configuration with, one
 *  a channel writes log entries with — so the checks around it are the part worth
 *  pinning. They live in store.php rather than get.php precisely so they can be reached
 *  from here; get.php reads a fixed path and exits.
 */
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../server/www/transcriber/store.php';

class TranscriberStoreTest extends TestCase
{
    private string $dir;
    private string $file;

    protected function setUp(): void
    {
        // A directory of its own, not just a file. The state and vocabulary files are
        // derived from the registry's directory, so two tests sharing /tmp would share
        // those — and one test's refresh would be another's starting condition.
        $this->dir  = sys_get_temp_dir() . '/chanreg_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);
        $this->file = $this->dir . '/transcriber.json';
        transcriber_save([
            'devices' => [
                ['host' => 'rx1', 'token' => 'dev-one'],
                ['host' => 'rx2', 'token' => 'dev-two'],
                ['host' => 'rx3', 'token' => ''],
            ],
            'channels' => [
                ['id'=>'rx1-146520', 'device'=>'rx1', 'label'=>'146.520', 'token'=>'ch-a',
                 'frequency'=>'146520000', 'serial'=>'00000001', 'squelch'=>0,
                 'model'=>'ggml-tiny.en.bin', 'enabled'=>true],
                ['id'=>'rx1-445000', 'device'=>'rx1', 'label'=>'445.000', 'token'=>'ch-b',
                 'frequency'=>'445000000', 'serial'=>'00000002', 'squelch'=>12,
                 'model'=>'ggml-base.en.bin', 'enabled'=>false],
                ['id'=>'rx2-146520', 'device'=>'rx2', 'label'=>'146.520', 'token'=>'ch-c',
                 'frequency'=>'146520000', 'serial'=>'00000003', 'squelch'=>0,
                 'model'=>'ggml-tiny.en.bin', 'enabled'=>true],
            ],
        ], $this->file);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) unlink($f);
        @rmdir($this->dir);
    }

    // ── the device token ──────────────────────────────────────────────────────

    public function testACorrectDeviceTokenIsAccepted(): void
    {
        $this->assertTrue(transcriber_device_ok('rx1', 'dev-one', $this->file));
        $this->assertTrue(transcriber_device_ok('rx2', 'dev-two', $this->file));
    }

    /** A token is only good for the device it was issued to. Otherwise one Transcriber
     *  left somewhere insecure would hand over the whole fleet's configuration. */
    public function testATokenDoesNotWorkForAnotherDevice(): void
    {
        $this->assertFalse(transcriber_device_ok('rx2', 'dev-one', $this->file));
        $this->assertFalse(transcriber_device_ok('rx1', 'dev-two', $this->file));
    }

    public function testAWrongOrUnknownTokenIsRefused(): void
    {
        $this->assertFalse(transcriber_device_ok('rx1', 'nope', $this->file));
        $this->assertFalse(transcriber_device_ok('ghost', 'dev-one', $this->file));
    }

    /** The shape of bug that turns an unconfigured device into an authenticated one:
     *  an empty supplied token matching an empty stored one. */
    public function testEmptyTokensNeverMatch(): void
    {
        $this->assertFalse(transcriber_device_ok('rx3', '', $this->file),
                           'a device with no token must not be reachable with no token');
        $this->assertFalse(transcriber_device_ok('rx1', '', $this->file));
        $this->assertFalse(transcriber_device_ok('', 'dev-one', $this->file));
        $this->assertFalse(transcriber_device_ok('', '', $this->file));
    }

    public function testAMissingRegistryRefusesEveryone(): void
    {
        $this->assertFalse(transcriber_device_ok('rx1', 'dev-one', '/nonexistent/reg.json'));
    }

    // ── what a device is given ────────────────────────────────────────────────

    /** Only its own channels. One device's token must not disclose another's log
     *  tokens, which would let it write to the event log as a frequency it does not
     *  even receive. */
    public function testADeviceGetsOnlyItsOwnChannels(): void
    {
        $ids = array_column(transcriber_channels_for('rx1', $this->file), 'id');
        $this->assertSame(['rx1-146520', 'rx1-445000'], $ids);

        $this->assertSame(['rx2-146520'],
                          array_column(transcriber_channels_for('rx2', $this->file), 'id'));
        $this->assertSame([], transcriber_channels_for('ghost', $this->file));
    }

    /** Disabled channels are still sent. The worker exits cleanly on one, and the
     *  server refuses its token, so switching a channel off takes effect immediately
     *  rather than waiting for the device to notice — but the device still needs to
     *  know the channel exists in order to stop it. */
    public function testDisabledChannelsAreStillSentSoTheyCanBeStopped(): void
    {
        $rows = transcriber_channels_for('rx1', $this->file);

        $this->assertCount(2, $rows);
        $this->assertFalse($rows[1]['enabled']);
    }

    public function testTheWorkerGetsEveryFieldItNeeds(): void
    {
        $c = transcriber_channels_for('rx1', $this->file)[0];

        foreach (['id','label','token','frequency','serial','squelch','model','enabled'] as $k) {
            $this->assertArrayHasKey($k, $c);
        }
        $this->assertSame(0, $c['squelch'], 'squelch is an int, not the string JSON gave us');
        $this->assertIsBool($c['enabled']);
    }

    // ── storage ───────────────────────────────────────────────────────────────

    public function testSaveAndLoadRoundTrip(): void
    {
        $back = transcriber_load($this->file);

        $this->assertCount(3, $back['devices']);
        $this->assertCount(3, $back['channels']);
        $this->assertSame('dev-one', $back['devices'][0]['token']);
    }

    /** A device fetching while the manager is mid-write must never see half a file.
     *  The write goes to a temp path and is renamed, so no reader observes it partly
     *  written — auto-update.sh validates the JSON it receives for the same reason. */
    public function testNoTemporaryFileIsLeftBehind(): void
    {
        transcriber_save(transcriber_load($this->file), $this->file);

        $this->assertFileDoesNotExist($this->file . '.tmp');
        $this->assertNotNull(json_decode((string)file_get_contents($this->file), true));
    }

    public function testAnEmptyRegistryReadsAsEmptyRatherThanFailing(): void
    {
        $empty = transcriber_load('/nonexistent/reg.json');

        $this->assertSame([], $empty['devices']);
        $this->assertSame([], $empty['channels']);
    }

    public function testIssuedTokensAreUnguessable(): void
    {
        $a = transcriber_token();
        $b = transcriber_token();

        $this->assertSame(32, strlen($a));
        $this->assertNotSame($a, $b);
    }

    // ── the assignment sheet ──────────────────────────────────────────────────

    /** An excerpt with the same shapes as the real Dipsea assignment sheet, which is
     *  where every awkward case here came from: a frequency table full of things that
     *  look like callsigns, a tactical call split across a tab by the table export, one
     *  written in lower case in prose, and a role word used as an ordinary noun.
     *
     *  The names and the phone number are invented. Everything the extraction is meant to
     *  ignore is represented, because "it ignored it" is the assertion — but there is no
     *  reason for a real operator's mobile number to be in a repository to prove it. */
    private function sheet(): string
    {
        return <<<TXT
        MARS Radio Assignments: Test Race, Sunday June 14, 2026
        Frequencies
        VHF Primary
        \t147.330MHz +
        439.875MHz simplex (CHANGED)
        \tPl 192.8Hz
        PL192.8Hz
        \tDMR (test only)
        \t440.1375MHz
        \tCC3,  NorCal TS1
        \t462.7MHz +
        (Ch21R) pl 100 TSQ
        I147.330!
        IAP briefing 630am. Zoom https://us05web.zoom.us/j/81957964153?pwd=SoXr2rpmB65XT8Dau6qGgEjXzWpa8M.1

        Operator Assignments.
        \tCall Sign
        \tNames
        \tMill Valley Start (Depot Bookstore)
        APRS iGate: MARS-11, cellular, Timing mat
        \tKD6SWU
        KM6AOW
        \tAlice Fictional
        Bob Invented
        \tFinish line, 1130 cutoff
        \tK6DRK
        KM6ZDM
        \tCarol Placeholder
        \tNet Control in the Sheriff's Van
        \tKM6ASI
        \tSAG Wagon Starting at Muir Woods
        \tKC6YYP
        \tHiker 1 Patrolling Cardiac to Muir Woods Road
        \tN6DVS-7
        \tHiker 2 Ben Johnson trail junction
        \tK6EZX-7
        KO6MFS-9?
        \tAid 4 water stop, 1115 cutoff
        \tEvent Sweeps, Tam West, GMRS, DMR
           Sweep 1 APRS/KM6BON-7
        \t   Sweep\t2 APRS/KM6ZSW-7
        \t   sweep 3 APRS/W6SG-2
        net control\t
        The last sweep, lenny, has a tracker.
        Dan Notreal
        (415) 555 0142
        TXT;
    }

    /** Nobody types an export URL. They paste whatever the browser had, which is the
     *  /edit link — or, occasionally, just the id out of the middle of it. */
    public function testAnExportUrlIsDerivedFromWhateverWasPasted(): void
    {
        $want = 'https://docs.google.com/document/d/11V2CoecKBKh9BthIphutW6V8qLZ5FsYKjZpqsR-U1rc/export?format=txt';

        $this->assertSame($want, transcriber_sheet_export_url(
            'https://docs.google.com/document/d/11V2CoecKBKh9BthIphutW6V8qLZ5FsYKjZpqsR-U1rc/edit?tab=t.0'));
        $this->assertSame($want, transcriber_sheet_export_url(
            'https://docs.google.com/document/d/11V2CoecKBKh9BthIphutW6V8qLZ5FsYKjZpqsR-U1rc/edit'));
        $this->assertSame($want, transcriber_sheet_export_url(
            '  11V2CoecKBKh9BthIphutW6V8qLZ5FsYKjZpqsR-U1rc  '));
        // Already an export URL: derive from it rather than double it up.
        $this->assertSame($want, transcriber_sheet_export_url($want));
    }

    public function testSomethingThatIsNotASheetYieldsNoUrl(): void
    {
        $this->assertSame('', transcriber_sheet_export_url(''));
        $this->assertSame('', transcriber_sheet_export_url('https://example.com/notadoc'));
        $this->assertSame('', transcriber_sheet_export_url('the dipsea sheet'));
    }

    public function testEveryCallsignOnTheSheetIsFound(): void
    {
        $v = transcriber_extract_vocabulary($this->sheet());

        $this->assertSame([
            'K6DRK', 'K6EZX', 'KC6YYP', 'KD6SWU', 'KM6AOW', 'KM6ASI',
            'KM6BON', 'KM6ZDM', 'KM6ZSW', 'KO6MFS', 'N6DVS', 'W6SG',
        ], $v['callsigns'], 'deduplicated and sorted, with no SSIDs');
    }

    /** The list sits beside a frequency table, and every one of these is a real string
     *  from the real sheet. A tone code in the vocabulary would be harmless; the reason
     *  to pin it is that the pattern that eats one is the pattern that has stopped being
     *  a callsign pattern. */
    public function testFrequenciesAndToneCodesAreNotMistakenForCallsigns(): void
    {
        $c = transcriber_extract_vocabulary($this->sheet())['callsigns'];

        foreach (['CC3', 'TS1', 'CH21R', 'C21R', 'I147', 'MHZ', 'MARS'] as $notACall) {
            $this->assertNotContains($notACall, $c);
        }
        // And nothing reached inside the base64 of the Zoom link, where "B65XT8" is a
        // perfectly well-formed callsign and the word boundary is all that saves us.
        $this->assertNotContains('B65XT8', $c);
        $this->assertNotContains('D41F', $c);
    }

    /** The export flattens a table, so the same call arrives with tabs in it, in lower
     *  case, and with trailing whitespace. What is stored is the spoken form, once. */
    public function testTacticalCallsAreNormalizedToOneSpokenForm(): void
    {
        $v = transcriber_extract_vocabulary($this->sheet());

        $this->assertSame([
            'Aid 4', 'Finish', 'Hiker 1', 'Hiker 2', 'Net Control',
            'SAG', 'Start', 'Sweep 1', 'Sweep 2', 'Sweep 3',
        ], $v['tactical']);
    }

    /** "The last sweep, lenny, has a tracker" is prose. A bare role word is a word
     *  whisper already knows, and putting it in the prompt only dilutes the calls that
     *  are actually worth biasing towards. */
    public function testABareRoleWordIsNotATacticalCall(): void
    {
        $t = transcriber_extract_vocabulary($this->sheet())['tactical'];

        $this->assertNotContains('Sweep', $t);
        $this->assertNotContains('Hiker', $t);
    }

    /** The sheet is somebody's working document. The vocabulary is what is needed; the
     *  rest is contact details, and a fleet of receivers has no business holding them. */
    public function testNamesAndPhoneNumbersAreNotExtracted(): void
    {
        $all = json_encode(transcriber_extract_vocabulary($this->sheet()));

        foreach (['Fictional', 'Invented', 'Placeholder', 'Notreal', 'Alice', 'Carol',
                  '555', '0142', 'Zoom', 'zoom.us'] as $private) {
            $this->assertStringNotContainsString($private, $all);
        }
    }

    // ── refreshing it ─────────────────────────────────────────────────────────

    /** A refresh stores the two lists and nothing else — the document itself is never
     *  written to disk, so there is no copy of it to leak, to go stale, or to explain. */
    public function testARefreshStoresTheTwoListsAndNotTheDocument(): void
    {
        $this->setSheet();
        $v = transcriber_vocabulary_refresh($this->file, fn($u) => [$this->sheet(), '']);

        $this->assertContains('K6DRK', $v['callsigns']);
        $this->assertContains('Net Control', $v['tactical']);
        $this->assertSame('', $v['error']);
        $this->assertGreaterThan(0, $v['fetched_at']);

        $onDisk = (string)file_get_contents(transcriber_vocabulary_path($this->file));
        $this->assertStringNotContainsString('Fictional', $onDisk);
        $this->assertStringNotContainsString('Muir Woods', $onDisk);
    }

    /** The sheet is edited on the morning of the event, which is also when the server is
     *  reached over whatever is working that day. Dropping a good vocabulary because one
     *  fetch failed would be strictly worse than holding yesterday's. */
    public function testAFailedFetchKeepsTheVocabularyItAlreadyHad(): void
    {
        $this->setSheet();
        transcriber_vocabulary_refresh($this->file, fn($u) => [$this->sheet(), '']);

        $v = transcriber_vocabulary_refresh($this->file, fn($u) => ['', 'the document is not shared']);

        $this->assertContains('K6DRK', $v['callsigns'], 'the last good list is still in force');
        $this->assertSame('the document is not shared', $v['error']);
    }

    /** Removing the URL removes the vocabulary. Otherwise the channels keep prompting
     *  with a list whose source nobody can look at. */
    public function testClearingTheSheetUrlClearsTheVocabulary(): void
    {
        $this->setSheet();
        transcriber_vocabulary_refresh($this->file, fn($u) => [$this->sheet(), '']);

        $this->setSheet('');
        $v = transcriber_vocabulary_refresh($this->file, fn($u) => [$this->sheet(), '']);

        $this->assertSame([], $v['callsigns']);
        $this->assertSame([], $v['tactical']);
    }

    /** The registry's fingerprint is what stops a stale editor overwriting somebody
     *  else's work. A vocabulary refresh happens on its own, every quarter of an hour,
     *  from a device poll — if it moved that fingerprint, an open manager page would be
     *  refused its own Save for a reason nobody could see. */
    public function testARefreshDoesNotMoveTheRegistryFingerprint(): void
    {
        $this->setSheet();
        $before = transcriber_fingerprint($this->file);

        transcriber_vocabulary_refresh($this->file, fn($u) => [$this->sheet(), '']);

        $this->assertSame($before, transcriber_fingerprint($this->file));
    }

    public function testTheSheetUrlSurvivesTheRegistryRoundTrip(): void
    {
        $this->setSheet();

        $this->assertSame('https://docs.google.com/document/d/11V2CoecKBKh9BthIphutW6V8qLZ5FsYKjZpqsR-U1rc/edit',
                          transcriber_load($this->file)['settings']['sheet_url']);
    }

    /** The devices are promised two keys and given two keys. `fetched_at`, the source URL
     *  and the last error are the manager's business, not a receiver's. */
    public function testDevicesAreGivenOnlyTheTwoLists(): void
    {
        $this->setSheet();
        transcriber_vocabulary_refresh($this->file, fn($u) => [$this->sheet(), '']);

        $words = transcriber_vocabulary_words($this->file);

        $this->assertSame(['callsigns', 'tactical'], array_keys($words));
        $this->assertContains('Sweep 2', $words['tactical']);
    }

    /** The unattended half: the device poll refreshes a stale vocabulary and leaves a
     *  fresh one alone. Without the second half, eight devices polling every sixty
     *  seconds would each fetch the document, forever. */
    public function testAStaleVocabularyIsRefreshedAndAFreshOneIsNot(): void
    {
        $this->setSheet();
        $calls = 0;
        $fetch = function ($u) use (&$calls) { $calls++; return [$this->sheet(), '']; };

        transcriber_vocabulary_refresh_if_stale($this->file, $fetch);
        $this->assertSame(1, $calls, 'never read: read it');

        transcriber_vocabulary_refresh_if_stale($this->file, $fetch);
        $this->assertSame(1, $calls, 'read a moment ago: left alone');

        // Age it past the TTL. checked_at, not fetched_at — a document that cannot be
        // reached has to be retried on the same schedule as one that can, rather than on
        // every single poll.
        $v = transcriber_vocabulary_load($this->file);
        $v['checked_at'] = time() - TRANSCRIBER_VOCABULARY_TTL - 1;
        transcriber_vocabulary_save($v, $this->file);

        transcriber_vocabulary_refresh_if_stale($this->file, $fetch);
        $this->assertSame(2, $calls, 'stale: read it again');
    }

    private function setSheet(string $url = 'https://docs.google.com/document/d/11V2CoecKBKh9BthIphutW6V8qLZ5FsYKjZpqsR-U1rc/edit'): void
    {
        $reg = transcriber_load($this->file);
        $reg['settings'] = ['sheet_url' => $url];
        transcriber_save($reg, $this->file);
    }
}
