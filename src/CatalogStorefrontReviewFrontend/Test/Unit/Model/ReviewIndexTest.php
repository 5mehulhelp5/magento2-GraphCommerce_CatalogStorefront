<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReviewFrontend\Test\Unit\Model;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontReviewFrontend\Model\ReviewIndex;
use PHPUnit\Framework\TestCase;

class ReviewIndexTest extends TestCase
{
    public function testTheMarkerIsReadOncePerRequest(): void
    {
        $storage = $this->createMock(MetadataDocumentStorageInterface::class);
        $storage->expects($this->exactly(2))->method('get')->with('review', 'default', [0])->willReturn([0 => ['productId' => '0']]);
        $index = new ReviewIndex($storage);

        $index->assertIndexed('default');
        $index->assertIndexed('default');
        $index->_resetState();
        $index->assertIndexed('default');
    }

    public function testAMarkerAReadFoundIsNotReadAgain(): void
    {
        $storage = $this->createMock(MetadataDocumentStorageInterface::class);
        $storage->expects($this->never())->method('get');
        $index = new ReviewIndex($storage);

        $index->found('default');
        $index->assertIndexed('default');
    }

    public function testAStoreViewWithoutTheMarkerHasNoReviewDocuments(): void
    {
        $storage = $this->createStub(MetadataDocumentStorageInterface::class);
        $storage->method('get')->willReturn([]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Store view "default" has no review documents');
        (new ReviewIndex($storage))->assertIndexed('default');
    }
}
