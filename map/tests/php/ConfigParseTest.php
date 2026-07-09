<?php
/** Tests for config.yaml parsing and validation logic in config_parse.php. */
use PHPUnit\Framework\TestCase;

class ConfigParseTest extends TestCase
{
    private string $fixtures;

    protected function setUp(): void
    {
        $this->fixtures = dirname(__DIR__) . '/fixtures';
    }

    private function writeTmp(string $content): string
    {
        $f = tempnam(sys_get_temp_dir(), 'aprs_cfg_');
        file_put_contents($f, $content);
        return $f;
    }

    private function cleanTmp(string $f): void
    {
        if (file_exists($f)) unlink($f);
    }

    // ── yamlScalar ────────────────────────────────────────────────────────────

    public function testScalarEmpty(): void
    {
        $this->assertSame('', yamlScalar(''));
    }

    public function testScalarDoubleQuoted(): void
    {
        $this->assertSame('hello world', yamlScalar('"hello world"'));
    }

    public function testScalarDoubleQuotedEscapes(): void
    {
        $this->assertSame("line1\nline2", yamlScalar('"line1\\nline2"'));
    }

    public function testScalarDoubleQuotedInnerQuote(): void
    {
        $this->assertSame('say "hi"', yamlScalar('"say \\"hi\\""'));
    }

    public function testScalarSingleQuoted(): void
    {
        $this->assertSame('plain string', yamlScalar("'plain string'"));
    }

    public function testScalarBoolTrue(): void
    {
        $this->assertSame(true, yamlScalar('true'));
    }

    public function testScalarBoolFalse(): void
    {
        $this->assertSame(false, yamlScalar('false'));
    }

    public function testScalarInteger(): void
    {
        $this->assertSame(42, yamlScalar('42'));
    }

    public function testScalarNegativeInteger(): void
    {
        $this->assertSame(-5, yamlScalar('-5'));
    }

    public function testScalarFloat(): void
    {
        $this->assertSame(37.9456, yamlScalar('37.9456'));
    }

    public function testScalarNegativeFloat(): void
    {
        $this->assertSame(-122.54, yamlScalar('-122.54'));
    }

    public function testScalarPlainString(): void
    {
        $this->assertSame('W6SG-4', yamlScalar('W6SG-4'));
    }

    // ── parseConfigYaml ───────────────────────────────────────────────────────

    public function testMissingFileReturnsDefaults(): void
    {
        $cfg = parseConfigYaml('/nonexistent/path/config.yaml');
        $this->assertSame([], $cfg['trackers']);
        $this->assertNull($cfg['map']);
        $this->assertSame([], $cfg['backgrounds']);
    }

    public function testSampleFixtureLoads(): void
    {
        $cfg = parseConfigYaml($this->fixtures . '/sample_config.yaml');
        $this->assertSame('Test Race 2026', $cfg['event']);
        $this->assertCount(2, $cfg['trackers']);
    }

    public function testTrackerFields(): void
    {
        $cfg = parseConfigYaml($this->fixtures . '/sample_config.yaml');
        $this->assertSame('W6SG-4', $cfg['trackers'][0]['callsign']);
        $this->assertSame('S4',     $cfg['trackers'][0]['id']);
        $this->assertSame('Alice',  $cfg['trackers'][0]['name']);
    }

    public function testTrackerOrdering(): void
    {
        $cfg = parseConfigYaml($this->fixtures . '/sample_config.yaml');
        $this->assertSame('K6DRK-9', $cfg['trackers'][1]['callsign']);
    }

    public function testMapSection(): void
    {
        $cfg = parseConfigYaml($this->fixtures . '/sample_config.yaml');
        $this->assertEqualsWithDelta(37.9456, $cfg['map']['lat'], 0.0001);
        $this->assertEqualsWithDelta(-122.54, $cfg['map']['lon'], 0.0001);
        $this->assertSame(13, $cfg['map']['zoom']);
    }

    public function testBackgroundsSection(): void
    {
        $cfg = parseConfigYaml($this->fixtures . '/sample_config.yaml');
        $this->assertCount(1, $cfg['backgrounds']);
        $this->assertSame('OpenStreetMap', $cfg['backgrounds'][0]['name']);
    }

    public function testCoursesSection(): void
    {
        $cfg = parseConfigYaml($this->fixtures . '/sample_config.yaml');
        $this->assertCount(1, $cfg['courses']);
        $this->assertSame('Main Course', $cfg['courses'][0]['name']);
    }

    public function testAidStationsSection(): void
    {
        $cfg = parseConfigYaml($this->fixtures . '/sample_config.yaml');
        $this->assertCount(1, $cfg['aidstations']);
        $this->assertSame('Start/Finish', $cfg['aidstations'][0]['name']);
    }

    public function testIGatesSection(): void
    {
        $cfg = parseConfigYaml($this->fixtures . '/sample_config.yaml');
        $this->assertCount(1, $cfg['igates']);
        $this->assertSame('K6DRK iGate', $cfg['igates'][0]['name']);
    }

    public function testCommentsAndBlanksIgnored(): void
    {
        $f = $this->writeTmp("# comment\n\ntrackers:\n  # inline comment\n  - callsign: W1AW\n    id: W\n    name: Test\n");
        $cfg = parseConfigYaml($f);
        $this->cleanTmp($f);
        $this->assertCount(1, $cfg['trackers']);
        $this->assertSame('W1AW', $cfg['trackers'][0]['callsign']);
    }

    public function testSectionVisibilityBooleans(): void
    {
        $cfg = parseConfigYaml($this->fixtures . '/sample_config.yaml');
        $this->assertSame(true, $cfg['section_visibility']['trackers']);
        $this->assertSame(true, $cfg['section_visibility']['courses']);
    }

    public function testEmptyTrackersDefault(): void
    {
        $f = $this->writeTmp("map:\n  lat: 37.0\n  lon: -122.0\n  zoom: 10\n");
        $cfg = parseConfigYaml($f);
        $this->cleanTmp($f);
        $this->assertSame([], $cfg['trackers']);
    }

    public function testMissingOptionalSectionsDefault(): void
    {
        $f = $this->writeTmp("trackers:\n  - callsign: W1AW\n    id: W\n    name: Test\n");
        $cfg = parseConfigYaml($f);
        $this->cleanTmp($f);
        $this->assertSame([], $cfg['aidstations']);
        $this->assertSame([], $cfg['igates']);
        $this->assertSame([], $cfg['courses']);
        $this->assertNull($cfg['map']);
    }
}
