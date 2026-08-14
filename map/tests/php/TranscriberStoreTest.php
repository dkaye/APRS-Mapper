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
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'chanreg_') . '.json';
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
        foreach ([$this->file, $this->file . '.tmp'] as $f) {
            if (file_exists($f)) unlink($f);
        }
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
}
