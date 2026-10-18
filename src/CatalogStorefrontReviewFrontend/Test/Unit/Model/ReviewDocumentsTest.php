<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReviewFrontend\Test\Unit\Model;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontReviewFrontend\Model\ReviewDocuments;
use GraphCommerce\CatalogStorefrontReviewFrontend\Model\ReviewIndex;
use PHPUnit\Framework\TestCase;

class ReviewDocumentsTest extends TestCase
{
    private function index(): ReviewIndex
    {
        $index = new ReviewIndex($this->createStub(MetadataDocumentStorageInterface::class));
        $index->found('default');

        return $index;
    }

    public function testThePageIsTheOrderAndTheSliceTheListRenders(): void
    {
        // The demo catalogue writes every review of a product with one date, and core, which
        // orders on the date alone, answers those by id ascending.
        $storage = $this->createMock(MetadataDocumentStorageInterface::class);
        $storage->expects(self::once())->method('find')->with(
            'review',
            'default',
            ['productId' => ['1812']],
            [['createdAt', 'desc'], ['reviewId', 'asc']],
            10,
            10
        )->willReturn(['documents' => [7 => ['reviewId' => 7]], 'total' => 11]);

        $page = (new ReviewDocuments($storage, $this->index()))->page('default', 1812, 10, 2);

        self::assertSame([['reviewId' => 7]], $page['documents']);
        self::assertSame(11, $page['total']);
    }

    public function testThePageIsReadOncePerRequest(): void
    {
        // The collection and the pager both ask for it.
        $storage = $this->createMock(MetadataDocumentStorageInterface::class);
        $storage->expects(self::once())->method('find')->willReturn(['documents' => [], 'total' => 0]);
        $documents = new ReviewDocuments($storage, $this->index());

        $documents->page('default', 1812, 10, 1);
        $documents->page('default', 1812, 10, 1);
    }

    public function testResetDropsThePage(): void
    {
        $storage = $this->createMock(MetadataDocumentStorageInterface::class);
        $storage->expects(self::exactly(2))->method('find')->willReturn(['documents' => [], 'total' => 0]);
        $documents = new ReviewDocuments($storage, $this->index());

        $documents->page('default', 1812, 10, 1);
        $documents->_resetState();
        $documents->page('default', 1812, 10, 1);
    }

    public function testAListWithoutAPageSizeReadsTheWholeWindow(): void
    {
        // The block renders every review where no pager limits it.
        $storage = $this->createMock(MetadataDocumentStorageInterface::class);
        $storage->expects(self::once())->method('find')
            ->with('review', 'default', self::anything(), self::anything(), 0, 10000)
            ->willReturn(['documents' => [], 'total' => 0]);

        (new ReviewDocuments($storage, $this->index()))->page('default', 1812, 0, 1);
    }

    public function testAStoreViewWithoutTheMarkerStopsTheRead(): void
    {
        $storage = $this->createMock(MetadataDocumentStorageInterface::class);
        $storage->method('get')->willReturn([]);
        $storage->expects(self::never())->method('find');

        $this->expectException(\UnexpectedValueException::class);
        (new ReviewDocuments($storage, new ReviewIndex($storage)))->page('default', 1812, 10, 1);
    }
}
