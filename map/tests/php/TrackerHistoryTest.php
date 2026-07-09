<?php
/** Tests for tracker history/breadcrumb retrieval from aprs-daemon. */
use PHPUnit\Framework\TestCase;

class TrackerHistoryTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'aprs_hist_');
        // Reset globals before each test
        global $trackerHistory, $historyFilePath;
        $trackerHistory  = [];
        $historyFilePath = null;
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) unlink($this->tmpFile);
    }

    private function setHistoryPath(?string $path): void
    {
        global $historyFilePath;
        $historyFilePath = $path;
    }

    private function setHistory(array $data): void
    {
        global $trackerHistory;
        $trackerHistory = $data;
    }

    private function getHistory(): array
    {
        global $trackerHistory;
        return $trackerHistory;
    }

    // ── Write then read roundtrip ─────────────────────────────────────────────

    public function testWriteReadRoundtrip(): void
    {
        $this->setHistoryPath($this->tmpFile);
        $this->setHistory([
            'W6SG-4' => [
                ['lat' => 37.9456, 'lon' => -122.54, 'path' => 'WIDE2-1', 'ts' => 1717500000],
                ['lat' => 37.9460, 'lon' => -122.541, 'path' => '', 'ts' => 1717499800],
            ],
        ]);

        writeTrackerHistoryFile();

        // Reset and re-read
        $this->setHistory([]);
        readTrackerHistoryFile();

        $history = $this->getHistory();
        $this->assertArrayHasKey('W6SG-4', $history);
        $this->assertCount(2, $history['W6SG-4']);
        $this->assertEqualsWithDelta(37.9456, $history['W6SG-4'][0]['lat'], 0.00001);
        $this->assertSame(1717500000, $history['W6SG-4'][0]['ts']);
    }

    public function testPathFieldRoundtrip(): void
    {
        $this->setHistoryPath($this->tmpFile);
        $this->setHistory([
            'K6DRK-9' => [
                ['lat' => 37.93, 'lon' => -122.51, 'path' => 'WIDE2-1,qAR,K6DRK', 'ts' => 1717500100],
            ],
        ]);

        writeTrackerHistoryFile();
        $this->setHistory([]);
        readTrackerHistoryFile();

        $history = $this->getHistory();
        $this->assertSame('WIDE2-1,qAR,K6DRK', $history['K6DRK-9'][0]['path']);
    }

    public function testEntryWithoutPath(): void
    {
        $this->setHistoryPath($this->tmpFile);
        $this->setHistory([
            'W1AW' => [
                ['lat' => 38.0, 'lon' => -121.0, 'path' => '', 'ts' => 1717500200],
            ],
        ]);

        writeTrackerHistoryFile();
        $this->setHistory([]);
        readTrackerHistoryFile();

        $history = $this->getHistory();
        $this->assertArrayHasKey('W1AW', $history);
        $this->assertSame('', $history['W1AW'][0]['path']);
    }

    public function testMultipleCallsigns(): void
    {
        $this->setHistoryPath($this->tmpFile);
        $this->setHistory([
            'W6SG-4' => [['lat' => 37.9, 'lon' => -122.5, 'path' => '', 'ts' => 1000]],
            'K6DRK-9' => [['lat' => 37.8, 'lon' => -122.4, 'path' => '', 'ts' => 2000]],
        ]);

        writeTrackerHistoryFile();
        $this->setHistory([]);
        readTrackerHistoryFile();

        $history = $this->getHistory();
        $this->assertArrayHasKey('W6SG-4', $history);
        $this->assertArrayHasKey('K6DRK-9', $history);
    }

    // ── 10-entry cap ──────────────────────────────────────────────────────────

    public function testTenEntryCap(): void
    {
        $this->setHistoryPath($this->tmpFile);
        $entries = [];
        for ($i = 0; $i < 12; $i++) {
            $entries[] = ['lat' => 37.0 + $i * 0.01, 'lon' => -122.0, 'path' => '', 'ts' => 1717500000 + $i];
        }
        $this->setHistory(['W6SG-4' => $entries]);

        writeTrackerHistoryFile();
        $this->setHistory([]);
        readTrackerHistoryFile();

        $history = $this->getHistory();
        $this->assertCount(10, $history['W6SG-4'], 'History should be capped at 10 entries');
    }

    public function testTenEntryCapPreservesFirst10(): void
    {
        $this->setHistoryPath($this->tmpFile);
        $entries = [];
        for ($i = 0; $i < 12; $i++) {
            $entries[] = ['lat' => 37.0 + $i * 0.01, 'lon' => -122.0, 'path' => '', 'ts' => 1717500000 + $i];
        }
        $this->setHistory(['W6SG-4' => $entries]);

        writeTrackerHistoryFile();
        $this->setHistory([]);
        readTrackerHistoryFile();

        $history = $this->getHistory();
        // First entry in file is oldest since we wrote them in order
        $this->assertSame(1717500000, $history['W6SG-4'][0]['ts']);
        $this->assertSame(1717500009, $history['W6SG-4'][9]['ts']);
    }

    // ── Missing / empty file ──────────────────────────────────────────────────

    public function testMissingFileDoesNotCrash(): void
    {
        $this->setHistoryPath('/nonexistent/path/tracker_history.yaml');
        readTrackerHistoryFile();
        $history = $this->getHistory();
        $this->assertSame([], $history);
    }

    public function testNullPathDoesNotCrash(): void
    {
        $this->setHistoryPath(null);
        readTrackerHistoryFile();
        $history = $this->getHistory();
        $this->assertSame([], $history);
    }

    public function testEmptyFileDoesNotCrash(): void
    {
        file_put_contents($this->tmpFile, '');
        $this->setHistoryPath($this->tmpFile);
        readTrackerHistoryFile();
        $history = $this->getHistory();
        $this->assertSame([], $history);
    }

    // ── Fixture file ──────────────────────────────────────────────────────────

    public function testFixtureFileLoads(): void
    {
        $fixture = dirname(__DIR__) . '/fixtures/sample_history.yaml';
        $this->setHistoryPath($fixture);
        readTrackerHistoryFile();

        $history = $this->getHistory();
        $this->assertArrayHasKey('W6SG-4', $history);
        $this->assertArrayHasKey('K6DRK-9', $history);
        $this->assertCount(5, $history['W6SG-4']);
        $this->assertCount(3, $history['K6DRK-9']);
    }

    public function testFixtureLatsCorrect(): void
    {
        $fixture = dirname(__DIR__) . '/fixtures/sample_history.yaml';
        $this->setHistoryPath($fixture);
        readTrackerHistoryFile();

        $history = $this->getHistory();
        $this->assertEqualsWithDelta(37.9456, $history['W6SG-4'][0]['lat'], 0.0001);
    }
}
