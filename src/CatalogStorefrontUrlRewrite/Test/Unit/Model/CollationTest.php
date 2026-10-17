<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontUrlRewrite\Test\Unit\Model;

use GraphCommerce\CatalogStorefrontUrlRewrite\Model\Collation;
use PHPUnit\Framework\TestCase;

class CollationTest extends TestCase
{
    private function collation(): Collation
    {
        return new Collation([mb_ord('a') => 'A', mb_ord('é') => 'E', mb_ord('ß') => 'S']);
    }

    public function testACharacterInTheMapTakesItsWeightAndAnyOtherIsItsOwn(): void
    {
        $this->assertSame('A/éB', (new Collation([mb_ord('a') => 'A']))->fold('a/éB'));
        $this->assertSame('E', $this->collation()->fold('é'));
        $this->assertSame('S', $this->collation()->fold('ß'));
    }

    public function testTrailingSpacesFoldAwayAndLeadingOnesStay(): void
    {
        $this->assertSame(' A', $this->collation()->fold(' a  '));
        $this->assertSame('A/', $this->collation()->fold('a/'));
    }

    public function testACharacterAboveTheBasicPlaneWeighsTheReplacementCharacter(): void
    {
        $this->assertSame("A\u{FFFD}", $this->collation()->fold("a\u{1F600}"));
    }

    public function testNullAndAStringThatIsNotUtf8FoldToNull(): void
    {
        $this->assertNull($this->collation()->fold(null));
        $this->assertNull($this->collation()->fold("a\xFF"));
        $this->assertSame('', $this->collation()->fold(''));
    }
}
