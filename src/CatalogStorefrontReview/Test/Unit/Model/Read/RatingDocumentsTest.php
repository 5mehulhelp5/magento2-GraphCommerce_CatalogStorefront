<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReview\Test\Unit\Model\Read;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontReview\Model\Read\RatingDocuments;
use PHPUnit\Framework\TestCase;

class RatingDocumentsTest extends TestCase
{
    public function testTheDocumentsTheScaleAndTheNameShareOneRead(): void
    {
        $storage = $this->createMock(MetadataDocumentStorageInterface::class);
        $storage->expects($this->once())->method('all')->with('rating', 'default')->willReturn([
            '4' => ['name' => 'Rating', 'position' => 0, 'values' => [['value' => '1'], ['value' => '2'], ['value' => '3']]],
        ]);
        $ratings = new RatingDocuments($storage);

        $this->assertSame(3, $ratings->scale('default', 4));
        $this->assertSame('Rating', $ratings->name('default', 4));
        $this->assertSame([4], array_keys($ratings->documents('default')));
        $this->assertNull($ratings->scale('default', 9));
        $this->assertNull($ratings->name('default', 9));
    }

    public function testResetReadsAgain(): void
    {
        $storage = $this->createMock(MetadataDocumentStorageInterface::class);
        $storage->expects($this->exactly(2))->method('all')->willReturn([]);
        $ratings = new RatingDocuments($storage);

        $ratings->documents('default');
        $ratings->_resetState();
        $ratings->documents('default');
    }
}
