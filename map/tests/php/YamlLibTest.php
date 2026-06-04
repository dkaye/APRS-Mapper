<?php
use PHPUnit\Framework\TestCase;

class YamlLibTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'yaml_lib_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) unlink($this->tmpFile);
    }

    // ── yamlVal ───────────────────────────────────────────────────────────────

    public function testValEmpty(): void
    {
        $this->assertSame('', yamlVal(''));
        $this->assertSame('', yamlVal("''"));
        $this->assertSame('', yamlVal('""'));
    }

    public function testValDoubleQuoted(): void
    {
        $this->assertSame('hello world', yamlVal('"hello world"'));
    }

    public function testValDoubleQuotedEscapedQuote(): void
    {
        $this->assertSame('say "hi"', yamlVal('"say \\"hi\\""'));
    }

    public function testValDoubleQuotedEscapedBackslash(): void
    {
        $this->assertSame('a\\b', yamlVal('"a\\\\b"'));
    }

    public function testValSingleQuoted(): void
    {
        $this->assertSame('plain text', yamlVal("'plain text'"));
    }

    public function testValSingleQuotedEscapedApostrophe(): void
    {
        // YAML single-quoted '' → single '
        $this->assertSame("it's", yamlVal("'it''s'"));
    }

    public function testValPlainString(): void
    {
        $this->assertSame('192.168.1.1', yamlVal('192.168.1.1'));
    }

    // ── yamlStr ───────────────────────────────────────────────────────────────

    public function testStrEmpty(): void
    {
        $this->assertSame("''", yamlStr(''));
    }

    public function testStrPlainAlphanumeric(): void
    {
        $this->assertSame('hostname-01', yamlStr('hostname-01'));
    }

    public function testStrPlainWithDot(): void
    {
        $this->assertSame('192.168.1.1', yamlStr('192.168.1.1'));
    }

    public function testStrSpecialCharsGetQuoted(): void
    {
        $result = yamlStr('Name With Spaces');
        $this->assertStringStartsWith('"', $result);
        $this->assertStringEndsWith('"', $result);
        $this->assertStringContainsString('Name With Spaces', $result);
    }

    public function testStrQuoteInValueEscaped(): void
    {
        $result = yamlStr('say "hi"');
        $this->assertStringStartsWith('"', $result);
        $this->assertStringContainsString('\\"', $result);
    }

    // ── loadDevices / saveDevices roundtrip ───────────────────────────────────

    private function sampleDevices(): array
    {
        return [
            ['name' => 'igate1', 'host' => 'igate.local', 'ip' => '192.168.1.10', 'group' => 'igates',
             'enabled' => true, 'web' => false, 'ssh_user' => '', 'ssh_pass' => ''],
            ['name' => 'map server', 'host' => 'map.local', 'ip' => '192.168.1.20', 'group' => 'servers',
             'enabled' => false, 'web' => true, 'ssh_user' => 'pi', 'ssh_pass' => 'secret123'],
        ];
    }

    public function testSaveAndLoadRoundtrip(): void
    {
        $original = $this->sampleDevices();
        saveDevices($this->tmpFile, $original);
        $loaded = loadDevices($this->tmpFile);

        $this->assertCount(2, $loaded);
        $this->assertSame('igate1', $loaded[0]['name']);
        $this->assertSame('192.168.1.10', $loaded[0]['ip']);
        $this->assertSame('map server', $loaded[1]['name']);
        $this->assertSame('192.168.1.20', $loaded[1]['ip']);
    }

    public function testEnabledBooleanRoundtrip(): void
    {
        saveDevices($this->tmpFile, $this->sampleDevices());
        $loaded = loadDevices($this->tmpFile);
        $this->assertTrue($loaded[0]['enabled']);
        $this->assertFalse($loaded[1]['enabled']);
    }

    public function testWebBooleanRoundtrip(): void
    {
        saveDevices($this->tmpFile, $this->sampleDevices());
        $loaded = loadDevices($this->tmpFile);
        $this->assertFalse($loaded[0]['web']);
        $this->assertTrue($loaded[1]['web']);
    }

    public function testSshCredentialsRoundtrip(): void
    {
        saveDevices($this->tmpFile, $this->sampleDevices());
        $loaded = loadDevices($this->tmpFile);
        $this->assertSame('pi', $loaded[1]['ssh_user']);
        $this->assertSame('secret123', $loaded[1]['ssh_pass']);
    }

    public function testEmptySshCredsOmittedFromOutput(): void
    {
        saveDevices($this->tmpFile, $this->sampleDevices());
        $raw = file_get_contents($this->tmpFile);
        // First device has empty ssh_user/ssh_pass — should not appear
        $blocks = preg_split('/^(?=- )/m', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $this->assertStringNotContainsString('ssh_user', $blocks[0]);
        $this->assertStringNotContainsString('ssh_pass', $blocks[0]);
    }

    public function testGroupPreserved(): void
    {
        saveDevices($this->tmpFile, $this->sampleDevices());
        $loaded = loadDevices($this->tmpFile);
        $this->assertSame('igates', $loaded[0]['group']);
        $this->assertSame('servers', $loaded[1]['group']);
    }

    public function testLoadNonexistentReturnsEmpty(): void
    {
        $loaded = loadDevices('/nonexistent/path/devices.yaml');
        $this->assertSame([], $loaded);
    }

    public function testNameWithSpacesRoundtrip(): void
    {
        $devices = [['name' => 'My Device Name', 'host' => 'dev.local', 'ip' => '10.0.0.1',
                     'group' => '', 'enabled' => true, 'web' => false, 'ssh_user' => '', 'ssh_pass' => '']];
        saveDevices($this->tmpFile, $devices);
        $loaded = loadDevices($this->tmpFile);
        $this->assertSame('My Device Name', $loaded[0]['name']);
    }
}
