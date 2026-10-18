<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReviewFrontend\Test\Unit\Model;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontReviewFrontend\Model\ReviewIndex;
use GraphCommerce\CatalogStorefrontReviewFrontend\Model\ReviewSummaries;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\TestCase;

class ReviewSummariesTest extends TestCase
{
    private function store(): StoreInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getCode')->willReturn('default');

        return $store;
    }

    public function testTheSummariesOfARequestShareTheirReads(): void
    {
        $asked = [];
        $storage = $this->createMock(MetadataDocumentStorageInterface::class);
        $storage->expects($this->exactly(2))->method('stats')->willReturnCallback(
            function (string $entity, string $code, string $group, array $ids) use (&$asked) {
                $asked[] = $ids;

                return array_intersect_key(
                    ['1812' => ['count' => 3, 'avg' => 86.67], '1811' => ['count' => 1, 'avg' => null], '0' => ['count' => 1, 'avg' => null]],
                    array_flip($ids)
                );
            }
        );
        $summaries = new ReviewSummaries($storage, new ReviewIndex($this->createStub(MetadataDocumentStorageInterface::class)));

        $this->assertSame([1812 => ['reviews_count' => 3, 'rating_summary' => 87]], $summaries->summaries($this->store(), [1812]));
        // A second ask reads only the products the first did not name.
        $this->assertSame(
            [1812 => ['reviews_count' => 3, 'rating_summary' => 87], 1811 => ['reviews_count' => 1, 'rating_summary' => 0]],
            $summaries->summaries($this->store(), [1812, 1811, 1810])
        );
        $this->assertSame([], $summaries->summaries($this->store(), [1810]));
        $this->assertSame([['1812', '0'], ['1811', '1810', '0']], $asked, 'every aggregation asks for the marker too');
    }

    public function testTheAggregationThatFindsTheMarkerSparesTheIndexARead(): void
    {
        $storage = $this->createStub(MetadataDocumentStorageInterface::class);
        $storage->method('stats')->willReturn(['0' => ['count' => 1, 'avg' => null]]);
        $markerReads = $this->createMock(MetadataDocumentStorageInterface::class);
        $markerReads->expects($this->never())->method('get');
        $index = new ReviewIndex($markerReads);

        (new ReviewSummaries($storage, $index))->summaries($this->store(), [1812]);
        $index->assertIndexed('default');
    }

    public function testAStoreViewWithoutTheMarkerHasNoReviewDocuments(): void
    {
        $storage = $this->createStub(MetadataDocumentStorageInterface::class);
        $storage->method('stats')->willReturn(['1812' => ['count' => 3, 'avg' => 86.67]]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Store view "default" has no review documents');
        (new ReviewSummaries($storage, new ReviewIndex($this->createStub(MetadataDocumentStorageInterface::class))))
            ->summaries($this->store(), [1812]);
    }
}
