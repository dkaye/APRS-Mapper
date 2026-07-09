<?php
/** Tests for raw APRS packet parsing utilities. */
use PHPUnit\Framework\TestCase;

class AprsParserTest extends TestCase
{
    // ── Uncompressed position ─────────────────────────────────────────────────

    public function testUncompressedBasic(): void
    {
        // Standard !-DTI, N/W hemisphere
        [$lat, $lon] = parseAprsPosition('W6SG-4>APRS,WIDE2-1:!3756.78N/12232.40W>Test');
        $this->assertEqualsWithDelta(37.9463, $lat, 0.0001);
        $this->assertEqualsWithDelta(-122.5400, $lon, 0.0001);
    }

    public function testUncompressedSouthEast(): void
    {
        // S/E hemisphere
        [$lat, $lon] = parseAprsPosition('VK2XYZ>APRS:!3320.00S/15110.00E>Test');
        $this->assertEqualsWithDelta(-33.3333, $lat, 0.0001);
        $this->assertEqualsWithDelta(151.1667, $lon, 0.0001);
    }

    public function testUncompressedNorthEast(): void
    {
        [$lat, $lon] = parseAprsPosition('JA1ABC>APRS:!3540.00N/13940.00E>Test');
        $this->assertEqualsWithDelta(35.6667, $lat, 0.0001);
        $this->assertEqualsWithDelta(139.6667, $lon, 0.0001);
    }

    public function testUncompressedSouthWest(): void
    {
        [$lat, $lon] = parseAprsPosition('LU1ZZ>APRS:!3430.00S/05830.00W>Test');
        $this->assertEqualsWithDelta(-34.5000, $lat, 0.0001);
        $this->assertEqualsWithDelta(-58.5000, $lon, 0.0001);
    }

    public function testUncompressedTimestampedSlash(): void
    {
        // / DTI with 7-byte DDHHMMz timestamp prefix
        [$lat, $lon] = parseAprsPosition('W6SG-4>APRS,WIDE2-1:/010000z3756.78N/12232.40W>Test');
        $this->assertEqualsWithDelta(37.9463, $lat, 0.0001);
        $this->assertEqualsWithDelta(-122.5400, $lon, 0.0001);
    }

    public function testUncompressedTimestampedAt(): void
    {
        // @ DTI
        [$lat, $lon] = parseAprsPosition('W6SG-4>APRS,WIDE2-1:@010000z3756.78N/12232.40W>Test');
        $this->assertEqualsWithDelta(37.9463, $lat, 0.0001);
        $this->assertEqualsWithDelta(-122.5400, $lon, 0.0001);
    }

    public function testUncompressedOverlaySymbol(): void
    {
        // Overlay symbol char between lat and lon (A-Z)
        [$lat, $lon] = parseAprsPosition('W6SG-4>APRS:!3756.78NA12232.40W>Test');
        $this->assertEqualsWithDelta(37.9463, $lat, 0.0001);
        $this->assertEqualsWithDelta(-122.5400, $lon, 0.0001);
    }

    public function testUncompressedEqualsSign(): void
    {
        // = DTI (no messaging)
        [$lat, $lon] = parseAprsPosition('W6SG-4>APRS,WIDE2-1:=3756.78N/12232.40W>Test');
        $this->assertEqualsWithDelta(37.9463, $lat, 0.0001);
        $this->assertEqualsWithDelta(-122.5400, $lon, 0.0001);
    }

    // ── Compressed Base91 position ────────────────────────────────────────────

    public function testCompressedBase91Basic(): void
    {
        // Known test vector: lat=49.5°N lon=72.75°W (from APRS spec example)
        // latVal = (90 - 49.5) * 380926 = 15427203 → chars at base91
        // lonVal = (72.75 + 180) * 190463 = 48304378 → chars at base91
        // Computed manually: latChars = chr(33+26).chr(33+71).chr(33+63).chr(33+17)
        // = '!', 'h', '`', '2' → but let's use a known-good APRS string
        // Use a real packet: position 37.9463°N, 122.54°W
        $latVal = (int)round((90.0 - 37.9463) * 380926);
        $c1 = chr(33 + intdiv($latVal, 91**3));
        $c2 = chr(33 + intdiv($latVal % 91**3, 91**2));
        $c3 = chr(33 + intdiv($latVal % 91**2, 91));
        $c4 = chr(33 + $latVal % 91);
        $lonVal = (int)round((-122.54 + 180.0) * 190463);
        $d1 = chr(33 + intdiv($lonVal, 91**3));
        $d2 = chr(33 + intdiv($lonVal % 91**3, 91**2));
        $d3 = chr(33 + intdiv($lonVal % 91**2, 91));
        $d4 = chr(33 + $lonVal % 91);
        $symCode = chr(0x5f); // '_' = wx symbol (safe printable Base91)
        $packet = 'W6SG-4>APRS,WIDE2-1:!/' . $c1.$c2.$c3.$c4 . $d1.$d2.$d3.$d4 . $symCode;
        [$lat, $lon] = parseAprsPosition($packet);
        $this->assertNotNull($lat);
        $this->assertEqualsWithDelta(37.9463, $lat, 0.001);
        $this->assertEqualsWithDelta(-122.54, $lon, 0.001);
    }

    public function testCompressedBase91BackslashSymTable(): void
    {
        // Symbol table char '\' is also valid
        $latVal = (int)round((90.0 - 37.0) * 380926);
        $c1 = chr(33 + intdiv($latVal, 91**3));
        $c2 = chr(33 + intdiv($latVal % 91**3, 91**2));
        $c3 = chr(33 + intdiv($latVal % 91**2, 91));
        $c4 = chr(33 + $latVal % 91);
        $lonVal = (int)round((-122.0 + 180.0) * 190463);
        $d1 = chr(33 + intdiv($lonVal, 91**3));
        $d2 = chr(33 + intdiv($lonVal % 91**3, 91**2));
        $d3 = chr(33 + intdiv($lonVal % 91**2, 91));
        $d4 = chr(33 + $lonVal % 91);
        $packet = 'W6SG-4>APRS:!\\' . $c1.$c2.$c3.$c4 . $d1.$d2.$d3.$d4 . chr(0x5f);
        [$lat, $lon] = parseAprsPosition($packet);
        $this->assertNotNull($lat);
        $this->assertEqualsWithDelta(37.0, $lat, 0.001);
        $this->assertEqualsWithDelta(-122.0, $lon, 0.001);
    }

    // ── Mic-E position ────────────────────────────────────────────────────────

    public function testMicENorthWest(): void
    {
        // Real Mic-E packet from a APRS tracker in North America (captured)
        // Destination S32U6T encodes lat 37°56.78'N with lon offset
        // Using a synthetic but spec-compliant packet
        // lat = 37°56.78'N → digits 3,7,5,6,7,8 (from DDmm.hhN)
        // dest chars for digits: '3'=0x33 '7'=0x37 '5'=0x35 '6'=0x36 '7'=0x37 '8'=0x38
        // N indicator at dest[3]: use 'A'-'J' range → dest[3] = chr(0x41+6)='G' → North
        // lon offset at dest[4]: 'A'-'J' → add 100 → dest[4] = chr(0x41+0)='A'
        // West at dest[5]: 'A'-'J' → West → dest[5] = chr(0x41+0)='A'
        // So destination = '375' + 'G' + 'A' + 'A' = '375GAA'
        // lon: payload[1] = lon_deg - 100 + 28 = 22 - 100 + 28... wait let me be more careful.
        // lon_deg = 122, minus 100 offset = 22, payload[1] = 22 + 28 = 50 = chr(50) = '2'
        // lon_min = 32, payload[2] = 32 + 28 = 60 = chr(60) = '<'  but ≥60 triggers isWest legacy
        // Actually use lon_min=20: payload[2] = 20+28 = 48 = '0'
        // lon_h = 40, payload[3] = 40+28 = 68 = 'D'
        // DTI = '`', payload = '`' + chr(50) + chr(48) + chr(68) + rest (4+ more bytes needed)
        $dest = '375GAA';
        $payload = '`' . chr(50) . chr(48) . chr(68) . '>Test extra bytes';
        $line = 'W6SG-4>' . $dest . ',WIDE2-1:' . $payload;
        [$lat, $lon] = parseAprsPosition($line);
        $this->assertNotNull($lat, 'Mic-E parse should succeed');
        // digits[0..5]=[3,7,5,6,0,0] → lat=37+56/60=37.9333, lon: offset+100, payload gives 122.34W
        $this->assertEqualsWithDelta(37.9333, $lat, 0.001);
        $this->assertEqualsWithDelta(-122.34, $lon, 0.01);
    }

    public function testMicESouthWest(): void
    {
        // South indicator: dest[3] NOT in A-J or P-Y range → use digit '0'-'9'
        // lat = 33° 20.00'S → digits 3,3,2,0,0,0
        // dest[3]='0' (not A-J or P-Y) → South
        $dest = '332000';
        // lon = 151°10.00'E, no offset (dest[4]='0' → no +100)
        // payload[1] = 151+28 = 179 = chr(179) → but ≥180 triggers remap
        // Actually 151+28=179 <180, so lon_deg = 179-28 = 151 ✓
        // payload[2] = 10+28=38='&', lon_min_raw=38-28=10 <60, isWest per dest[5]
        // dest[5]='0' (not A-J) → NOT west → East
        // payload[3] = 0+28=28=chr(28)
        $payload = '`' . chr(179) . chr(38) . chr(28) . '>Extra bytes here';
        $line = 'VK2ZZZ>' . $dest . ',WIDE2-1:' . $payload;
        [$lat, $lon] = parseAprsPosition($line);
        $this->assertNotNull($lat, 'Mic-E South parse should succeed');
        // Should be negative lat (south) and positive lon (east)
        $this->assertLessThan(0, $lat, 'South should be negative');
        $this->assertGreaterThan(0, $lon, 'East should be positive');
    }

    public function testMicEOldFormat(): void
    {
        // DTI = "'" (old position)
        $dest = '375GAA';
        $payload = "'" . chr(50) . chr(48) . chr(68) . '>Test extra bytes';
        $line = 'W6SG-4>' . $dest . ',WIDE2-1:' . $payload;
        [$lat, $lon] = parseAprsPosition($line);
        $this->assertNotNull($lat, "Mic-E old format (') should parse");
    }

    // ── Malformed / no-match ──────────────────────────────────────────────────

    public function testMalformedNoColon(): void
    {
        [$lat, $lon] = parseAprsPosition('W6SG-4>APRS,WIDE2-1 3756.78N/12232.40W');
        $this->assertNull($lat);
        $this->assertNull($lon);
    }

    public function testMalformedEmptyPayload(): void
    {
        [$lat, $lon] = parseAprsPosition('W6SG-4>APRS:');
        $this->assertNull($lat);
        $this->assertNull($lon);
    }

    public function testMalformedShortMicePayload(): void
    {
        // Mic-E DTI but payload too short (< 8 bytes)
        $line = 'W6SG-4>375GAA,WIDE2-1:`' . chr(50) . chr(48);
        [$lat, $lon] = parseAprsPosition($line);
        $this->assertNull($lat);
        $this->assertNull($lon);
    }

    public function testMalformedStatusPacket(): void
    {
        // Status packet with > — no position
        [$lat, $lon] = parseAprsPosition('W6SG-4>APRS,WIDE2-1:>Testing status message');
        $this->assertNull($lat);
        $this->assertNull($lon);
    }

    public function testMalformedCommentOnly(): void
    {
        [$lat, $lon] = parseAprsPosition('# This is a server comment line');
        $this->assertNull($lat);
        $this->assertNull($lon);
    }

    public function testUncompressedRoundingPrecision(): void
    {
        // Verify 6-decimal-place rounding
        [$lat, $lon] = parseAprsPosition('W6SG-4>APRS:!0000.00N/00000.00W>Test');
        $this->assertSame(0.0, $lat);
        $this->assertSame(0.0, $lon);
    }
}
