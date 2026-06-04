<?php
use PHPUnit\Framework\TestCase;

class AdminConfigTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'aprs_adm_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) unlink($this->tmpFile);
    }

    private function baseCfg(): array
    {
        return [
            'event'   => 'Test Race 2026',
            'legend'  => '',
            'trackers' => [
                ['callsign' => 'W6SG-4', 'id' => 'S4', 'name' => 'Alice'],
                ['callsign' => 'K6DRK-9', 'id' => 'D9', 'name' => 'Bob'],
            ],
            'tracker_style' => ['icon' => 'circle', 'label_color' => '#000000'],
            'section_visibility' => ['trackers' => true, 'courses' => true, 'aidstations' => true, 'igates' => true, 'backgrounds' => true],
            'map' => ['lat' => 37.9456, 'lon' => -122.54, 'zoom' => 13],
            'backgrounds' => [
                ['name' => 'OpenStreetMap', 'url' => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', 'attribution' => '&copy; OSM'],
            ],
            'courses'     => [],
            'aidstations' => [],
            'igates'      => [],
            'background_url' => '',
        ];
    }

    private function buildAndReparse(array $cfg, array $history = []): array
    {
        $yaml = buildConfigYaml($cfg, $history);
        file_put_contents($this->tmpFile, $yaml);
        return parseConfigYaml($this->tmpFile);
    }

    // ── Basic roundtrip ───────────────────────────────────────────────────────

    public function testEventNameRoundtrip(): void
    {
        $reparsed = $this->buildAndReparse($this->baseCfg());
        $this->assertSame('Test Race 2026', $reparsed['event']);
    }

    public function testTrackerCountRoundtrip(): void
    {
        $reparsed = $this->buildAndReparse($this->baseCfg());
        $this->assertCount(2, $reparsed['trackers']);
    }

    public function testTrackerFieldsRoundtrip(): void
    {
        $reparsed = $this->buildAndReparse($this->baseCfg());
        $this->assertSame('W6SG-4', $reparsed['trackers'][0]['callsign']);
        $this->assertSame('S4',     $reparsed['trackers'][0]['id']);
        $this->assertSame('Alice',  $reparsed['trackers'][0]['name']);
    }

    public function testMapCoordinatesRoundtrip(): void
    {
        $reparsed = $this->buildAndReparse($this->baseCfg());
        $this->assertEqualsWithDelta(37.9456, $reparsed['map']['lat'], 0.0001);
        $this->assertEqualsWithDelta(-122.54, $reparsed['map']['lon'], 0.0001);
        $this->assertSame(13, $reparsed['map']['zoom']);
    }

    public function testBackgroundsRoundtrip(): void
    {
        $reparsed = $this->buildAndReparse($this->baseCfg());
        $this->assertCount(1, $reparsed['backgrounds']);
        $this->assertSame('OpenStreetMap', $reparsed['backgrounds'][0]['name']);
    }

    // ── Tracker add / remove ──────────────────────────────────────────────────

    public function testAddTrackerRoundtrip(): void
    {
        $cfg = $this->baseCfg();
        $cfg['trackers'][] = ['callsign' => 'W1AW', 'id' => 'AW', 'name' => 'Carol'];
        $reparsed = $this->buildAndReparse($cfg);
        $this->assertCount(3, $reparsed['trackers']);
        $this->assertSame('W1AW', $reparsed['trackers'][2]['callsign']);
    }

    public function testRemoveTrackerRoundtrip(): void
    {
        $cfg = $this->baseCfg();
        $cfg['trackers'] = [['callsign' => 'W6SG-4', 'id' => 'S4', 'name' => 'Alice']];
        $reparsed = $this->buildAndReparse($cfg);
        $this->assertCount(1, $reparsed['trackers']);
    }

    // ── Special characters ────────────────────────────────────────────────────

    public function testSpecialCharsInTrackerName(): void
    {
        // These chars are safe in plain YAML context (not special YAML chars)
        $cfg = $this->baseCfg();
        $cfg['trackers'][0]['name'] = 'Alice & Bob';
        $reparsed = $this->buildAndReparse($cfg);
        $this->assertSame('Alice & Bob', $reparsed['trackers'][0]['name']);
    }

    public function testCallsignWithDashRoundtrip(): void
    {
        $cfg = $this->baseCfg();
        $cfg['trackers'][0]['callsign'] = 'W6SG-14';
        $reparsed = $this->buildAndReparse($cfg);
        $this->assertSame('W6SG-14', $reparsed['trackers'][0]['callsign']);
    }

    // ── Section visibility booleans ───────────────────────────────────────────

    public function testSectionVisibilityTrueRoundtrip(): void
    {
        $cfg = $this->baseCfg();
        $cfg['section_visibility']['courses'] = true;
        $reparsed = $this->buildAndReparse($cfg);
        $this->assertSame(true, $reparsed['section_visibility']['courses']);
    }

    public function testSectionVisibilityFalseRoundtrip(): void
    {
        $cfg = $this->baseCfg();
        $cfg['section_visibility']['aidstations'] = false;
        $reparsed = $this->buildAndReparse($cfg);
        $this->assertSame(false, $reparsed['section_visibility']['aidstations']);
    }

    // ── Save history ──────────────────────────────────────────────────────────

    public function testSaveHistoryPreserved(): void
    {
        $history = ['2026-06-01 10:00:00 UTC', '2026-06-02 11:00:00 UTC'];
        $yaml = buildConfigYaml($this->baseCfg(), $history);
        $extracted = extractHistory($yaml);
        $this->assertCount(2, $extracted);
        $this->assertSame('2026-06-01 10:00:00 UTC', $extracted[0]);
    }

    public function testEmptyHistoryProducesNoBlock(): void
    {
        $yaml = buildConfigYaml($this->baseCfg(), []);
        $this->assertStringNotContainsString('Save history', $yaml);
    }
}
