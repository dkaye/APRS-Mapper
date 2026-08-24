<?php
/** Tests for turning an Apple hardware identifier into words.
 *
 *  The table is generated from a published list, so these do not re-check every entry —
 *  they pin the behaviour around it: that the identifiers this fleet actually carries
 *  resolve, that the numbering trap is covered, and that an unknown one is refused rather
 *  than guessed at. */
use PHPUnit\Framework\TestCase;

class DeviceModelsTest extends TestCase
{
    /** Every identifier seen in the tracker file, so a regenerated table that dropped one
     *  of these fails here rather than in front of an operator. */
    public function testTheIdentifiersThisFleetCarriesAllResolve(): void
    {
        $seen = [
            'iPhone16,2' => 'iPhone 15 Pro Max',   // the one that prompted this
            'iPhone17,1' => 'iPhone 16 Pro',
            'iPhone18,2' => 'iPhone 17 Pro Max',
            'iPhone15,2' => 'iPhone 14 Pro',
            'iPhone14,3' => 'iPhone 13 Pro Max',
        ];
        foreach ($seen as $id => $name) {
            $this->assertSame($name, apple_device_name($id), $id);
        }
        $this->assertNotNull(apple_device_name('iPad13,18'), 'iPads are trackers too');
    }

    /** The identifier's generation does NOT track the marketing name, which is the whole
     *  reason this table exists: 16,2 is a 15 and 15,4 is also a 15. Reading a generation
     *  off the number gets you the wrong phone. */
    public function testTheNumberIsNotTheModelName(): void
    {
        $this->assertSame('iPhone 15 Pro Max', apple_device_name('iPhone16,2'));
        $this->assertStringContainsString('15', apple_device_name('iPhone15,4') ?? '',
            'the plain 15 sits in the 15,x range while its Pro sits in 16,x');
    }

    /** An unmapped identifier is refused, not guessed. The caller then shows the raw
     *  string, which is honest and still diagnostic; an invented name would be neither. */
    public function testAnUnknownIdentifierIsNull(): void
    {
        $this->assertNull(apple_device_name('iPhone99,9'));
        $this->assertNull(apple_device_name('SM-A166U'), 'Android reports its own way');
        $this->assertNull(apple_device_name(''));
        $this->assertNull(apple_device_name(null));
        $this->assertNull(apple_device_name('   '));
    }

    public function testSurroundingWhitespaceIsIgnored(): void
    {
        $this->assertSame('iPhone 15 Pro Max', apple_device_name('  iPhone16,2 '));
    }
}
