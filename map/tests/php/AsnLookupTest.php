<?php
/** Tests for the local IP → network table.
 *
 *  The index is written by a Python builder and read by PHP, so the binary layout is a
 *  contract between two languages with nothing but these tests holding them to it. Each
 *  test writes the format by hand rather than calling the builder: a bug that changed both
 *  sides together would otherwise pass. */
use PHPUnit\Framework\TestCase;

class AsnLookupTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/asn_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        asn_dir($this->dir);        // per-test; a constant could only be set once
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') as $f) unlink($f);
        @rmdir($this->dir);
    }

    /** Write an index the way build-asn-table.py does. $ranges is [start, end, asn, name]. */
    private function writeTable(array $ranges): void
    {
        $blob = '';
        $offs = [];
        $v4 = '';
        $v6 = '';
        foreach ($ranges as [$start, $end, $asn, $name]) {
            if (!isset($offs[$name])) {
                $offs[$name] = strlen($blob);
                $blob .= chr(strlen($name)) . $name;
            }
            $lo = inet_pton($start);
            $hi = inet_pton($end);
            $rec = $lo . $hi . pack('NN', $asn, $offs[$name]);
            if (strlen($lo) === 16) $v6 .= $rec; else $v4 .= $rec;
        }
        file_put_contents($this->dir . '/v4.idx', $v4);
        file_put_contents($this->dir . '/v6.idx', $v6);
        file_put_contents($this->dir . '/names.bin', $blob);
    }

    // ── finding the right range ───────────────────────────────────────────────

    public function testFindsTheRangeAnAddressFallsIn(): void
    {
        // Sorted by start address, as the builder writes them.
        $this->writeTable([
            ['1.0.0.0',    '1.0.0.255',    13335, 'CLOUDFLARENET'],
            ['8.8.8.0',    '8.8.8.255',    15169, 'GOOGLE'],
            ['172.58.0.0', '172.58.255.255', 21928, 'T-MOBILE-AS21928'],
        ]);
        $this->assertSame('T-Mobile', asn_lookup('172.58.12.34'), 'a known AS number is named');
        $this->assertSame('Google',   asn_lookup('8.8.8.8'));
        $this->assertSame('Cloudflare', asn_lookup('1.0.0.1'));
    }

    public function testTheEdgesOfARangeAreInsideIt(): void
    {
        $this->writeTable([['10.20.0.0', '10.20.0.255', 21928, 'T-MOBILE-AS21928']]);
        $this->assertSame('T-Mobile', asn_lookup('10.20.0.0'),   'first address in the range');
        $this->assertSame('T-Mobile', asn_lookup('10.20.0.255'), 'last address in the range');
    }

    /** The table does not cover every address. Landing past the end of the nearest range
     *  is a gap, not a match — the binary search finds that record either way, so this is
     *  the check that stops it naming the wrong network. */
    public function testAnAddressInAGapMatchesNothing(): void
    {
        $this->writeTable([
            ['1.0.0.0', '1.0.0.255', 13335, 'CLOUDFLARENET'],
            ['9.0.0.0', '9.0.0.255', 15169, 'GOOGLE'],
        ]);
        $this->assertNull(asn_lookup('5.5.5.5'), 'between two ranges');
        $this->assertNull(asn_lookup('1.0.1.1'), 'just past the end of the first');
        $this->assertNull(asn_lookup('0.255.255.255'), 'below every range');
    }

    // ── IPv6, which is where the cellular users are ───────────────────────────

    /** US carriers are heavily IPv6 and Cloudflare passes an IPv6 CF-Connecting-IP, so an
     *  IPv4-only table would fail on exactly the devices this feature is for. */
    public function testIPv6AddressesResolve(): void
    {
        $this->writeTable([
            ['2600:1000::', '2600:100f:ffff:ffff:ffff:ffff:ffff:ffff', 6167, 'CELLCO-PART'],
            ['2607:fb90::', '2607:fb90:ffff:ffff:ffff:ffff:ffff:ffff', 21928, 'T-MOBILE-AS21928'],
        ]);
        $this->assertSame('Verizon',  asn_lookup('2600:1005:b062:61e4::1'));
        $this->assertSame('T-Mobile', asn_lookup('2607:fb90:1234::abcd'));
        $this->assertNull(asn_lookup('2001:db8::1'), 'outside every range');
    }

    /** Addresses are compared as raw big-endian bytes, which is only a numeric comparison
     *  if the high byte is treated as unsigned. A range starting above 128.0.0.0 is where
     *  a signed comparison would go wrong, and it is most of the IPv4 space. */
    public function testHighAddressesCompareUnsigned(): void
    {
        $this->writeTable([
            ['1.0.0.0',     '1.0.0.255',     13335, 'CLOUDFLARENET'],
            ['200.0.0.0',   '200.0.0.255',   15169, 'GOOGLE'],
            ['255.0.0.0',   '255.0.0.255',   7922,  'COMCAST-7922'],
        ]);
        $this->assertSame('Google',  asn_lookup('200.0.0.7'));
        $this->assertSame('Comcast', asn_lookup('255.0.0.7'));
    }

    // ── what it decides to call things ────────────────────────────────────────

    /** A carrier announces from several AS numbers, which is why the number is the key. */
    public function testSeveralAsNumbersCanShareOneName(): void
    {
        $this->writeTable([
            ['1.1.0.0', '1.1.0.255', 6167,  'CELLCO-PART'],
            ['2.2.0.0', '2.2.0.255', 22394, 'CELLCO-PART'],
            ['3.3.0.0', '3.3.0.255', 701,   'UUNET'],
        ]);
        $this->assertSame('Verizon', asn_lookup('1.1.0.1'));
        $this->assertSame('Verizon', asn_lookup('2.2.0.1'));
        $this->assertSame('Verizon', asn_lookup('3.3.0.1'));
    }

    /** An unknown AS falls back to the description, tidied. Registry handles carry the AS
     *  number and shout, and neither belongs in front of an operator. */
    public function testAnUnknownNetworkFallsBackToATidiedDescription(): void
    {
        $this->writeTable([
            ['1.1.0.0', '1.1.0.255', 64500, 'EXAMPLE-NET-AS64500'],
            ['2.2.0.0', '2.2.0.255', 64501, 'SOME-REGIONAL-ISP'],
            ['3.3.0.0', '3.3.0.255', 64502, 'Sonic Telecom LLC'],
        ]);
        $this->assertSame('Example-Net', asn_lookup('1.1.0.1'), 'the AS suffix goes');
        $this->assertSame('Some-Regional-Isp', asn_lookup('2.2.0.1'), 'shouting is toned down');
        $this->assertSame('Sonic Telecom LLC', asn_lookup('3.3.0.1'),
            'a name a human wrote is left exactly as it is');
    }

    // ── refusing to answer ────────────────────────────────────────────────────

    /** Every failure is null rather than a guess: a wrong carrier on a tracker is worse
     *  than a blank one, and the caller shows nothing either way. */
    public function testUnanswerableInputsReturnNull(): void
    {
        $this->writeTable([['1.0.0.0', '1.0.0.255', 13335, 'CLOUDFLARENET']]);
        $this->assertNull(asn_lookup('not-an-address'));
        $this->assertNull(asn_lookup(''));
        $this->assertNull(asn_lookup('999.1.1.1'));
    }

    public function testAMissingTableIsNotAnError(): void
    {
        // Nothing written at all — a server that has never run the builder.
        $this->assertNull(asn_lookup('8.8.8.8'));
        $this->assertNull(asn_table_meta());
    }

    /** A truncated index must not be read as a short table of nonsense. */
    public function testATruncatedIndexReturnsNull(): void
    {
        $this->writeTable([['1.0.0.0', '1.0.0.255', 13335, 'CLOUDFLARENET']]);
        file_put_contents($this->dir . '/v4.idx', 'abc');   // shorter than one record
        $this->assertNull(asn_lookup('1.0.0.1'));
    }
}
