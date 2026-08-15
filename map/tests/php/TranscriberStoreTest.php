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

    // ── calibration ───────────────────────────────────────────────────────────
    //
    // Devices only ever fetched before this; now one channel at a time reports that it has
    // started measuring its site and what it found. Everything here is about the two ways
    // that can go wrong: a device speaking for a channel that is not its own, and a page
    // that cannot tell a channel nobody has ever calibrated from one that has been.

    /** A request reaches only the device whose channel it is. It is fetched with the same
     *  device token as everything else, so anything else in this list would be one
     *  Transcriber able to take another's channel off the air for two minutes. */
    public function testACalibrationRequestIsOnlySeenByTheDeviceThatOwnsTheChannel(): void
    {
        $at = transcriber_request_calibration('rx2-146520', $this->file);

        $this->assertSame(['rx2-146520' => $at],
                          transcriber_calibration_requests_for('rx2', $this->file));
        $this->assertSame([], transcriber_calibration_requests_for('rx1', $this->file));
    }

    /** The other half of the same rule, on the way back. A device token authenticates the
     *  report — a channel token writes log entries and must not be able to do anything
     *  else — so the channel it names has to be checked against the device that holds it. */
    public function testAChannelIsOwnedByExactlyOneDevice(): void
    {
        $this->assertSame('rx1', transcriber_channel_device('rx1-146520', $this->file));
        $this->assertSame('rx2', transcriber_channel_device('rx2-146520', $this->file));
        $this->assertSame('', transcriber_channel_device('no-such-channel', $this->file));
        $this->assertSame('', transcriber_channel_device('', $this->file));
    }

    /** "Never calibrated" has to be a state the manager can show, distinctly from a
     *  channel that has been. A new Pi runs on the compiled-in gain and squelch — measured
     *  on somebody else's hill — until the button is pressed, and nobody presses a button
     *  they have no reason to know about. */
    public function testAChannelWithNoCalibrationReadsAsNeverMeasured(): void
    {
        $all = transcriber_calibration_load($this->file);

        $this->assertArrayNotHasKey('rx1-146520', $all);
    }

    /** Started first, then the result. The started report is what the manager's countdown
     *  runs from: devices poll once a minute, so counting from the button press would be
     *  wrong by up to a minute in the direction that claims a measurement has finished
     *  while the radio is still busy. */
    public function testTheDeviceReportsStartingAndThenWhatItMeasured(): void
    {
        transcriber_request_calibration('rx1-146520', $this->file);
        $started = transcriber_record_calibration(
            'rx1-146520', ['state' => 'started', 'expected' => 90], $this->file);

        $this->assertGreaterThan(0, $started['started']);
        $this->assertSame(90, $started['expected']);
        $this->assertSame(0, $started['finished']);

        $done = transcriber_record_calibration(
            'rx1-146520', ['state' => 'done', 'gain' => 16.6, 'squelch' => 30], $this->file);

        $this->assertSame(16.6, $done['gain']);
        $this->assertSame(30, $done['squelch']);
        $this->assertGreaterThan(0, $done['finished']);
        $this->assertSame('', $done['error']);
    }

    /** A failure says why, and leaves the last good pair alone: the channel goes back on
     *  the air using exactly what it was using before, so that is what the page must go on
     *  showing. A failed calibration that says so is worth far more than a plausible one
     *  that is wrong. */
    public function testAFailedCalibrationKeepsTheNumbersTheReceiverIsStillUsing(): void
    {
        transcriber_record_calibration(
            'rx1-146520', ['state' => 'done', 'gain' => 16.6, 'squelch' => 30], $this->file);
        $row = transcriber_record_calibration(
            'rx1-146520',
            ['state' => 'failed', 'error' => 'something was transmitting'], $this->file);

        $this->assertSame('something was transmitting', $row['error']);
        $this->assertSame(16.6, $row['gain'], 'the receiver is still using what it measured');
        $this->assertSame(30, $row['squelch']);
    }

    /** Anything that is not one of the three states is not recorded at all. This arrives
     *  over the network from a device that may be running older code than the server. */
    public function testAnUnknownStateIsRefusedRatherThanStored(): void
    {
        $this->assertArrayHasKey('error', transcriber_record_calibration(
            'rx1-146520', ['state' => 'measuring'], $this->file));
        $this->assertSame([], transcriber_calibration_load($this->file));
    }

    /** The one that would break the manager. Calibration state is written by devices at
     *  moments nobody chose — a request, a start, a finish — and the page carries a
     *  fingerprint of the registry to refuse a stale write. If these shared a file, an
     *  open page would start refusing its own Save because a receiver reported in. Same
     *  reasoning as the vocabulary, and the same separate file. */
    public function testCalibrationDoesNotMoveTheRegistryFingerprint(): void
    {
        $before = transcriber_fingerprint($this->file);

        transcriber_request_calibration('rx1-146520', $this->file);
        transcriber_record_calibration(
            'rx1-146520', ['state' => 'done', 'gain' => 20.7, 'squelch' => 20], $this->file);

        $this->assertSame($before, transcriber_fingerprint($this->file));
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

    /** The same sheet with the section the author is being asked to add. Appended rather
     *  than woven in, because that is how it will arrive: a heading at the end of a
     *  document nobody wants to restructure.
     *
     *  Every awkwardness here is one the export produces or one an author produces: a term
     *  in a table cell arrives with a leading tab, a bulleted list arrives as "* term",
     *  and the section is followed by more document that must not be swallowed. */
    private function sheetWithSection(): string
    {
        return $this->sheet() . "\n" . <<<TXT

        Transcriber Vocabulary:
        Windy Gap
        \tCardiac
        * Bootjack
        Cardiac Hill = Cardiac
        Stinson Beach

        Parking passes
        Only required at Muir Woods road crossing.
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

    // ── the vocabulary section ────────────────────────────────────────────────

    /** Place names are the one thing no pattern can find. "Windy Gap", "Bootjack" and
     *  "Stinson Beach" are multi-word proper nouns with no shape to them, and any regex
     *  wide enough to catch them would catch half the document — so the sheet states them,
     *  and this reads what it was told rather than guessing. */
    public function testTheVocabularySectionIsReadWhenTheSheetHasOne(): void
    {
        $v = transcriber_extract_vocabulary($this->sheetWithSection());

        $this->assertTrue($v['section_found']);
        $this->assertSame(['Windy Gap', 'Cardiac', 'Bootjack', 'Stinson Beach'], $v['terms'],
                          'a tab from a table cell and a bullet from a list are decoration');
        $this->assertSame(['cardiac hill' => 'Cardiac'], $v['corrections']);
    }

    /** The section ends at the first blank line, so the rest of the document is not
     *  swallowed into it. Without this a section near the top of a sheet would take
     *  everything below it, including the operators' names. */
    public function testTheSectionEndsAtTheFirstBlankLine(): void
    {
        $v = transcriber_extract_vocabulary($this->sheetWithSection());

        $this->assertNotContains('Parking passes', $v['terms']);
        $this->assertNotContains('Only required at Muir Woods road crossing.', $v['terms']);
    }

    /** The heading is a heading, not any line with the word in it. The sheet is prose as
     *  well as tables, and a sentence about vocabulary is not an instruction to read the
     *  next twenty lines as terms. */
    public function testASentenceMentioningVocabularyIsNotAHeading(): void
    {
        $prose = "Rob will send the vocabulary for the transcribers by Friday.\n"
               . "Alice Fictional\nBob Invented\n";
        $v = transcriber_extract_vocabulary($prose);

        $this->assertFalse($v['section_found']);
        $this->assertSame([], $v['terms']);
    }

    /** The real sheet has no section yet — that is the state every existing event is in,
     *  and it must extract exactly what it always did and say the section is missing.
     *
     *  Fetched rather than committed. The document is somebody's working file, full of
     *  operators' names and a mobile number, and a copy of it in a repository would be the
     *  leak this whole feature is written to avoid. Skipped when there is no network, so a
     *  test run on a train is not a failure. */
    public function testTheRealSheetStillYieldsItsRosterAndReportsNoSection(): void
    {
        [$text, $err] = transcriber_sheet_fetch(transcriber_sheet_export_url(
            'https://docs.google.com/document/d/11V2CoecKBKh9BthIphutW6V8qLZ5FsYKjZpqsR-U1rc/edit'));
        if ($err !== '') $this->markTestSkipped("the live sheet is unreachable: $err");

        $v = transcriber_extract_vocabulary($text);

        $this->assertCount(35, $v['callsigns'], 'every callsign on the real sheet');
        $this->assertCount(12, $v['tactical'], 'every tactical call on the real sheet');
        $this->assertContains('K6DRK', $v['callsigns']);
        $this->assertContains('Net Control', $v['tactical']);

        $this->assertFalse($v['section_found'], 'and it says the section is missing');
        $this->assertSame([], $v['terms']);
        $this->assertSame([], $v['corrections']);
    }

    /** A correction is keyed by what a mishearing has to be compared against, so the
     *  worker can look one up instead of scanning. Case and punctuation go; the written
     *  form is kept exactly as it was typed, because that is what lands in the log. */
    public function testACorrectionIsKeyedByTheNormalizedHeardForm(): void
    {
        $block = "Cardiff = Cardiac\nPan Toll's = Pantoll\nWINDY  GAP=Windy Gap\n";
        $v = transcriber_vocabulary_lines($block);

        $this->assertSame([
            'cardiff'    => 'Cardiac',
            'pan toll s' => 'Pantoll',
            'windy gap'  => 'Windy Gap',
        ], $v['corrections']);
    }

    /** Half a rule is not a rule. A line with nothing on one side of the "=" is a typo,
     *  and acting on it would either rewrite text into nothing or match everything.
     *
     *  A heard-form of nothing but digits goes the same way, for a different reason: it
     *  would fire on every reading of that number on the air, and it is also the one shape
     *  that turns the map into a JSON array on the way out — where the worker, which
     *  requires an object, drops every rule in it. */
    public function testARuleThatCouldNotWorkIsIgnored(): void
    {
        $v = transcriber_vocabulary_lines(
            "= Cardiac\nCardiff =\n  =  \n0 = zero\n147 = 147.33\nCardiff = Cardiac\n");

        $this->assertSame(['cardiff' => 'Cardiac'], $v['corrections']);
        // And what is left encodes as an object, which is what the worker looks up in.
        $this->assertSame('{"cardiff":"Cardiac"}', json_encode($v['corrections']));
    }

    /** The written form of a correction is also a term. Somebody who says "Cardiac Hill
     *  comes out as Cardiff" has told us Cardiac is a phrase this event says, and there is
     *  no reason to make them type it twice for the prompt to know it. */
    public function testTheWrittenFormOfACorrectionIsAlsoATerm(): void
    {
        $v = transcriber_vocabulary_lines("Cardiff = Cardiac\n");

        $this->assertSame(['Cardiac'], $v['terms']);
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

    /** A refresh keeps the section flag and the terms with the rest, so a sheet that had a
     *  section this morning and lost it is visible as having lost it. */
    public function testARefreshStoresTheSectionAndWhatItSaid(): void
    {
        $this->setSheet();
        $v = transcriber_vocabulary_refresh($this->file, fn($u) => [$this->sheetWithSection(), '']);

        $this->assertTrue($v['section_found']);
        $this->assertContains('Windy Gap', $v['terms']);
        $this->assertSame(['cardiac hill' => 'Cardiac'], $v['corrections']);

        $again = transcriber_vocabulary_refresh($this->file, fn($u) => [$this->sheet(), '']);
        $this->assertFalse($again['section_found'], 'a section that went away is reported gone');
        $this->assertSame([], $again['terms']);
    }

    /** The devices are promised four keys and given four keys. `fetched_at`, the source URL
     *  and the last error are the manager's business, not a receiver's. */
    public function testDevicesAreGivenOnlyTheFourLists(): void
    {
        $this->setSheet();
        transcriber_vocabulary_refresh($this->file, fn($u) => [$this->sheetWithSection(), '']);

        $words = transcriber_vocabulary_words($this->file);

        $this->assertSame(['callsigns', 'tactical', 'terms', 'corrections'], array_keys($words));
        $this->assertContains('Sweep 2', $words['tactical']);
        $this->assertContains('Windy Gap', $words['terms']);
        $this->assertSame(['cardiac hill' => 'Cardiac'], $words['corrections']);
    }

    // ── the manager's supplement box ──────────────────────────────────────────

    /** The box is for the case the sheet cannot serve: it is mid-event, "Cardiac" is
     *  coming out as "Cardiff", and the shared document is not yours to edit right then.
     *  So it is merged with the sheet rather than replacing it. */
    public function testTheSupplementBoxIsMergedWithWhatTheSheetGave(): void
    {
        $this->setSheet();
        transcriber_vocabulary_refresh($this->file, fn($u) => [$this->sheetWithSection(), '']);
        $this->setExtra("Pantoll\nCardiff = Cardiac\n");

        $words = transcriber_vocabulary_words($this->file);

        $this->assertContains('Windy Gap', $words['terms'], 'still what the sheet said');
        $this->assertContains('Pantoll', $words['terms'], 'and what was typed in the box');
        $this->assertSame(['cardiac hill' => 'Cardiac', 'cardiff' => 'Cardiac'],
                          $words['corrections']);
    }

    /** It takes effect on Save, with no refresh and no fetch. The whole point of the box is
     *  that the document is unreachable or uneditable at that moment; making it wait on a
     *  successful read of that document would be answering a different problem. */
    public function testTheSupplementBoxWorksWithNoSheetAtAll(): void
    {
        $this->setExtra("Windy Gap\nCardiff = Cardiac\n");

        $words = transcriber_vocabulary_words($this->file);

        $this->assertSame(['Windy Gap', 'Cardiac'], $words['terms']);
        $this->assertSame(['cardiff' => 'Cardiac'], $words['corrections']);
    }

    /** Same rule, same key: the box wins. It was typed later and it was typed by somebody
     *  watching the log go wrong. */
    public function testTheBoxOverridesTheSheetOnTheSameCorrection(): void
    {
        $this->setSheet();
        transcriber_vocabulary_refresh($this->file, fn($u) => [$this->sheetWithSection(), '']);
        $this->setExtra("Cardiac Hill = Pantoll\n");

        $this->assertSame(['cardiac hill' => 'Pantoll'],
                          transcriber_vocabulary_words($this->file)['corrections']);
    }

    /** An empty box, an absent one, and a registry hand-edited into nonsense all mean the
     *  same thing: nothing extra. This runs inside the device poll, where an exception is
     *  a receiver that does not get its channels. */
    public function testAnEmptyOrMissingSupplementIsNotAFailure(): void
    {
        foreach (['', "\n \n\t\n", null, ['not', 'a', 'string']] as $junk) {
            $reg = transcriber_load($this->file);
            $reg['settings']['vocabulary_extra'] = $junk;
            transcriber_save($reg, $this->file);

            $words = transcriber_vocabulary_words($this->file);
            $this->assertSame([], $words['terms']);
            $this->assertSame([], $words['corrections']);
        }
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
        $reg['settings']['sheet_url'] = $url;
        transcriber_save($reg, $this->file);
    }

    private function setExtra(string $text): void
    {
        $reg = transcriber_load($this->file);
        $reg['settings']['vocabulary_extra'] = $text;
        transcriber_save($reg, $this->file);
    }
}
