<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReviewFrontend\Test\Unit\Plugin\Detail;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontReview\Model\Read\RatingDocuments;
use GraphCommerce\CatalogStorefrontReviewFrontend\Model\ReviewDocuments;
use GraphCommerce\CatalogStorefrontReviewFrontend\Plugin\Detail\RateVotesFromDocuments;
use GraphCommerce\CatalogStorefrontReviewFrontend\Plugin\Detail\ReviewCollectionFlag;
use GraphCommerce\CatalogStorefrontReviewFrontend\Plugin\Detail\ReviewCountFromDocuments;
use GraphCommerce\CatalogStorefrontReviewFrontend\Plugin\Detail\ReviewsFromDocuments;
use Magento\Catalog\Model\Product;
use Magento\Review\Block\Product\View as ReviewList;
use Magento\Review\Model\ResourceModel\Review\Collection;
use Magento\Review\Model\Review;
use Magento\Review\Model\ReviewFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The reviews a product page renders, as a loaded collection answers them.
 */
class ReviewsFromDocumentsTest extends TestCase
{
    private const PRODUCT_ID = 1812;

    private bool $proceeded = false;

    /** @var Review[] what the collection was given */
    private array $items = [];

    /** @var array<string, mixed> the flags of the collection */
    private array $flags = [];

    private ReviewDocuments&MockObject $documents;

    private function collection(?int $flaggedProduct = self::PRODUCT_ID, int $pageSize = 10): Collection&MockObject
    {
        if ($flaggedProduct !== null) {
            $this->flags[ReviewCollectionFlag::PRODUCT] = $flaggedProduct;
        }
        $collection = $this->createMock(Collection::class);
        $collection->method('getFlag')->willReturnCallback(fn(string $key) => $this->flags[$key] ?? null);
        $collection->method('setFlag')->willReturnCallback(
            function (string $key, $value) use ($collection) {
                $this->flags[$key] = $value;

                return $collection;
            }
        );
        $collection->method('addItem')->willReturnCallback(
            function ($item) use ($collection) {
                $this->items[] = $item;

                return $collection;
            }
        );
        $collection->method('getPageSize')->willReturn($pageSize);
        $collection->method('getCurPage')->willReturn(1);

        return $collection;
    }

    private function stores(): StoreManagerInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);

        return $stores;
    }

    private function strict(?LoggerInterface $logger = null): Strict
    {
        return new Strict($this->createStub(StorefrontKey::class), $logger ?? $this->createStub(LoggerInterface::class));
    }

    /**
     * @param array[] $documents the review documents of the page
     * @param array<int, int> $scales the value count per rating id
     */
    private function plugin(array $documents, array $scales = [1 => 5], bool $throws = false, ?LoggerInterface $logger = null): ReviewsFromDocuments
    {
        $this->documents = $this->createMock(ReviewDocuments::class);
        if ($throws) {
            $this->documents->method('page')->willThrowException(new \RuntimeException('no alive nodes'));
        } else {
            $this->documents->method('page')->willReturn(['documents' => $documents, 'total' => count($documents)]);
        }
        $ratings = $this->createStub(RatingDocuments::class);
        $ratings->method('scale')->willReturnCallback(static fn(string $store, int $id) => $scales[$id] ?? null);
        $ratings->method('name')->willReturnCallback(
            static fn(string $store, int $id) => isset($scales[$id]) ? 'Quality' : null
        );
        $factory = $this->createStub(ReviewFactory::class);
        $factory->method('create')->willReturnCallback(fn() => new class extends Review {
            /** @phpstan-ignore-next-line the model is built by hand in the test */
            public function __construct()
            {
            }
        });

        return new ReviewsFromDocuments($this->documents, $ratings, $factory, $this->stores(), $this->strict($logger));
    }

    private function proceed(): \Closure
    {
        return function () {
            $this->proceeded = true;

            return $this->createStub(Collection::class);
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function document(int $id, array $votes = [1 => 4]): array
    {
        return [
            'reviewId' => $id,
            'productId' => self::PRODUCT_ID,
            'title' => 'Good tank',
            'text' => 'Fits well',
            'nickname' => 'Mark',
            'createdAt' => '2026-09-01 10:00:00',
            'votes' => $votes,
        ];
    }

    public function testTheDocumentsFillTheCollection(): void
    {
        $plugin = $this->plugin([self::document(7), self::document(8)]);

        $plugin->aroundLoad($this->collection(), $this->proceed());

        $this->assertFalse($this->proceeded, 'the review query is what this avoids');
        $this->assertCount(2, $this->items);
        $this->assertSame('Good tank', $this->items[0]->getTitle());
        $this->assertSame('Fits well', $this->items[0]->getDetail(), 'the document names the body text');
        $this->assertSame('Mark', $this->items[0]->getNickname());
        $this->assertSame('2026-09-01 10:00:00', $this->items[0]->getCreatedAt());
    }

    public function testAVoteAnswersTheRatingCodeAndThePercent(): void
    {
        // The vote table holds the percent of the value over the rating's value count, rounded.
        $plugin = $this->plugin([self::document(7, [1 => 4])]);

        $plugin->aroundLoad($this->collection(), $this->proceed());
        $votes = $this->items[0]->getRatingVotes();

        $this->assertCount(1, $votes);
        $this->assertSame(80, $votes[0]->getPercent());
        $this->assertSame('Quality', $votes[0]->getRatingCode());
    }

    public function testAVoteOfARatingWithoutADocumentStopsTheReadAndIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('Rating 9 of review 7 has no document'));
        $plugin = $this->plugin([self::document(7, [9 => 4])], logger: $logger);

        $this->expectException(DocumentReadException::class);
        $plugin->aroundLoad($this->collection(), $this->proceed());
    }

    public function testTheCollectionIsFilledOncePerRequest(): void
    {
        // Every read of the items loads the collection again.
        $plugin = $this->plugin([self::document(7)]);
        $collection = $this->collection();

        $plugin->aroundLoad($collection, $this->proceed());
        $plugin->aroundLoad($collection, $this->proceed());

        $this->assertCount(1, $this->items);
    }

    public function testAnUnmarkedCollectionLoads(): void
    {
        // The review page's own collection and the admin's carry no mark.
        $plugin = $this->plugin([self::document(7)]);
        $this->documents->expects($this->never())->method('page');

        $plugin->aroundLoad($this->collection(null), $this->proceed());

        $this->assertTrue($this->proceeded);
    }

    public function testAFailedReadStopsTheRead(): void
    {
        $plugin = $this->plugin([], [1 => 5], true);

        $this->expectException(DocumentReadException::class);
        $this->expectExceptionMessage('no alive nodes');
        $plugin->aroundLoad($this->collection(), $this->proceed());
    }

    public function testTheSizeIsTheNumberOfDocuments(): void
    {
        $this->plugin([self::document(7), self::document(8)]);
        $count = new ReviewCountFromDocuments($this->documents, $this->stores(), $this->strict());

        $size = $count->aroundGetSize($this->collection(), $this->proceed());

        $this->assertSame(2, $size);
        $this->assertFalse($this->proceeded, 'the count query is what this avoids');
    }

    public function testAFailedCountStopsTheRead(): void
    {
        $this->plugin([], [1 => 5], true);
        $count = new ReviewCountFromDocuments($this->documents, $this->stores(), $this->strict());

        $this->expectException(DocumentReadException::class);
        $count->aroundGetSize($this->collection(), $this->proceed());
    }

    public function testTheVotesOfAFilledCollectionAreLeftAlone(): void
    {
        $this->flags[ReviewCollectionFlag::SERVED] = true;

        (new RateVotesFromDocuments())->aroundAddRateVotes($this->collection(), $this->proceed());

        $this->assertFalse($this->proceeded, 'a query per review is what this avoids');
    }

    public function testTheVotesOfALoadedCollectionAreCores(): void
    {
        (new RateVotesFromDocuments())->aroundAddRateVotes($this->collection(null), $this->proceed());

        $this->assertTrue($this->proceeded);
    }

    public function testOnlyAProductServedFromADocumentIsMarked(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getData')->willReturn(['sku' => 'WT09']);
        $product->method('getId')->willReturn(self::PRODUCT_ID);
        $block = $this->createStub(ReviewList::class);
        $block->method('getProduct')->willReturn($product);
        $this->flags = [];

        (new ReviewCollectionFlag())->afterGetReviewsCollection($block, $this->collection(null));

        $this->assertSame(self::PRODUCT_ID, $this->flags[ReviewCollectionFlag::PRODUCT] ?? null);
    }

    public function testAProductLoadedFromTheDatabaseIsNotMarked(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getData')->with(ProductDocumentsInterface::DOCUMENT_KEY)->willReturn(null);
        $block = $this->createStub(ReviewList::class);
        $block->method('getProduct')->willReturn($product);
        $this->flags = [];

        (new ReviewCollectionFlag())->afterGetReviewsCollection($block, $this->collection(null));

        $this->assertSame([], $this->flags);
    }
}
