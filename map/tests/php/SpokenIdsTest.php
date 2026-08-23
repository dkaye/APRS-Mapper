<?php
/** Tests for the tracker-ID name list in spoken_ids.php. */
use PHPUnit\Framework\TestCase;

class SpokenIdsTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'aprs_ids_');
        unlink($this->path);   // start absent, which is the shipped state
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) unlink($this->path);
    }

    private function seed(string $text): void
    {
        spoken_ids_save($text, $this->path);
    }

    public function testMissingFileIsEmptyNotAnError(): void
    {
        $this->assertSame([], spoken_ids_map($this->path));
        $this->assertSame('', spoken_ids_load($this->path)['text']);
    }

    public function testParsesPairsAndIgnoresCommentsAndJunk(): void
    {
        $this->seed("# Aid stations\nCAR = Cardiac\nH1 = Hiker One\n\nno equals here\nEMPTY =\n= nokey\n");
        $this->assertSame(['CAR' => 'Cardiac', 'H1' => 'Hiker One'], spoken_ids_map($this->path));
    }

    /** A half-finished "CAR =" must leave the label alone rather than blank it. */
    public function testHalfWrittenLineDoesNotBlankTheLabel(): void
    {
        $this->seed("CAR =\n");
        $this->assertNull(spoken_id_for('CAR', $this->path));
        $this->assertSame('CAR Stanton', spoken_label('CAR', 'Stanton', ' ', $this->path));
    }

    public function testLookupIsCaseInsensitiveOnBothSides(): void
    {
        $this->seed("car = Cardiac\n");
        $this->assertSame('Cardiac', spoken_id_for('CAR', $this->path));
        $this->assertSame('Cardiac', spoken_id_for('Car', $this->path));
    }

    public function testUnknownIdIsLeftExactlyAsItWas(): void
    {
        $this->seed("CAR = Cardiac\n");
        $this->assertNull(spoken_id_for('M141', $this->path));
        $this->assertSame('M141 Dirck', spoken_label('M141', 'Dirck', ' ', $this->path));
    }

    /** The two separators are the whole reason the label builder takes one. */
    public function testSeparatorDistinguishesScreenFromSpeech(): void
    {
        $this->seed("H1 = Hiker One\nINS = Insult\n");
        $this->assertSame('Hiker One Germain',  spoken_label('H1', 'Germain', ' ',  $this->path));
        $this->assertSame('Hiker One, Germain', spoken_label('H1', 'Germain', ', ', $this->path));
        $this->assertSame('Insult, Charlie B',  spoken_label('INS', 'Charlie B', ', ', $this->path));
    }

    public function testNameEqualToIdIsNotRepeated(): void
    {
        $this->seed("CAR = Cardiac\n");
        $this->assertSame('Cardiac', spoken_label('CAR', 'CAR', ' ', $this->path));
        $this->assertSame('Cardiac', spoken_label('CAR', '',    ' ', $this->path));
    }

    public function testNoIdFallsBackToTheNameAlone(): void
    {
        $this->assertSame('Net Control', spoken_label('', 'Net Control', ' ', $this->path));
    }

    public function testFingerprintChangesOnSaveSoStaleWritesAreCaught(): void
    {
        $before = spoken_ids_load($this->path)['fingerprint'];
        $this->seed("CAR = Cardiac\n");
        $after = spoken_ids_load($this->path)['fingerprint'];
        $this->assertNotSame($before, $after);
        $this->assertSame($after, spoken_ids_load($this->path)['fingerprint'], 'stable between reads');
    }

    // ── Expansion inside transcribed text ────────────────────────────────────

    public function testExpandsWholeTokensInText(): void
    {
        $this->seed("H1 = Hiker One\nINS = Insult\n");
        $this->assertSame('Hiker One to net control',
            spoken_ids_expand_text('H1 to net control', $this->path));
        $this->assertSame('Insult is clear',
            spoken_ids_expand_text('INS is clear', $this->path));
    }

    /** The whole reason for lookarounds: a bare replace rewrites the middle of words. */
    public function testDoesNotMatchInsideLongerWords(): void
    {
        $this->seed("CAR = Cardiac\n");
        foreach (['CARDIAC checkpoint', 'he was SCARED', 'CARRY on', 'the CART'] as $line) {
            $this->assertSame($line, spoken_ids_expand_text($line, $this->path));
        }
    }

    public function testPunctuationAndPossessivesStillMatch(): void
    {
        $this->seed("H1 = Hiker One\n");
        $this->assertSame('Hiker One, go ahead.', spoken_ids_expand_text('H1, go ahead.', $this->path));
        $this->assertSame("Hiker One's location",  spoken_ids_expand_text("H1's location", $this->path));
    }

    /** One pass: a phrase that contains another id must not be expanded again. */
    public function testSubstitutionsDoNotChain(): void
    {
        $this->seed("CAR = Cardiac Aid\nAID = First Aid\n");
        $this->assertSame('Cardiac Aid is open', spoken_ids_expand_text('CAR is open', $this->path));
    }

    /** Longest first, so a short id cannot claim the front of a longer one. */
    public function testLongerIdWinsOverShorterPrefix(): void
    {
        $this->seed("H1 = Hiker One\nH10 = Hiker Ten\n");
        $this->assertSame('Hiker Ten reporting', spoken_ids_expand_text('H10 reporting', $this->path));
        $this->assertSame('Hiker One reporting', spoken_ids_expand_text('H1 reporting', $this->path));
    }

    public function testMatchIsCaseInsensitive(): void
    {
        $this->seed("H1 = Hiker One\n");
        $this->assertSame('Hiker One rolling', spoken_ids_expand_text('h1 rolling', $this->path));
    }

    public function testEmptyListLeavesTextUntouched(): void
    {
        $this->assertSame('H1 to net control',
            spoken_ids_expand_text('H1 to net control', $this->path));
    }

    /** The cap trims to a line boundary; half a line matches nothing while looking fine. */
    public function testOversizeInputIsTrimmedToWholeLines(): void
    {
        $line  = "AB = " . str_repeat('x', 60) . "\n";
        $saved = spoken_ids_save(str_repeat($line, 1000), $this->path);
        $this->assertLessThanOrEqual(SPOKEN_IDS_MAX_BYTES, strlen($saved['text']));
        $this->assertNotSame('', trim($saved['text']), 'trimming must not empty the list');
        // The property that matters: nothing was cut mid-line, so every surviving line
        // is still a parseable pair rather than a fragment that quietly matches nothing.
        foreach (explode("\n", trim($saved['text'])) as $l) {
            $this->assertStringContainsString('=', $l);
        }
    }
}
