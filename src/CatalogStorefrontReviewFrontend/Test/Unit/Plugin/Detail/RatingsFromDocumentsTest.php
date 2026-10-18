<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReviewFrontend\Test\Unit\Plugin\Detail;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontReview\Model\Read\RatingDocuments;
use GraphCommerce\CatalogStorefrontReviewFrontend\Plugin\Detail\RatingsFromDocuments;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\Collection;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Data\CollectionFactory;
use Magento\Framework\DataObject;
use Magento\Review\Block\Form;
use Magento\Review\Model\Rating\OptionFactory;
use Magento\Review\Model\RatingFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The ratings of the review form, as core's rating collection gives them.
 */
class RatingsFromDocumentsTest extends TestCase
{
    private bool $proceeded = false;

    /**
     * @param array<int, array>|\Throwable $documents the rating documents by rating id, or the read failure
     */
    private function ratings(array|\Throwable $documents, bool $withDocument = true, bool $singleStore = false, ?LoggerInterface $logger = null): mixed
    {
        $ratings = $this->createMock(RatingDocuments::class);
        if ($documents instanceof \Throwable) {
            $ratings->method('documents')->willThrowException($documents);
        } else {
            $ratings->method('documents')->with('default')->willReturn($documents);
        }
        $ratingFactory = $this->createStub(RatingFactory::class);
        $ratingFactory->method('create')->willReturnCallback(static fn() => new DataObject());
        $optionFactory = $this->createStub(OptionFactory::class);
        $optionFactory->method('create')->willReturnCallback(static fn() => new DataObject());
        $collections = $this->createStub(CollectionFactory::class);
        $collections->method('create')->willReturnCallback(
            fn() => new Collection($this->createStub(EntityFactoryInterface::class))
        );
        $store = $this->createStub(Store::class);
        $store->method('getCode')->willReturn('default');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $stores->method('isSingleStoreMode')->willReturn($singleStore);

        $product = $this->createMock(Product::class);
        $product->method('getData')->with(ProductDocumentsInterface::DOCUMENT_KEY)->willReturn($withDocument ? ['sku' => 'WT09'] : null);
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('id', false)->willReturn('1812');
        $form = $this->createStub(Form::class);
        $form->method('getRequest')->willReturn($request);
        $form->method('getProductInfo')->willReturn($product);

        $strict = new Strict($this->createStub(StorefrontKey::class), $logger ?? $this->createStub(LoggerInterface::class));

        return (new RatingsFromDocuments($ratings, $ratingFactory, $optionFactory, $collections, $stores, $strict))->aroundGetRatings(
            $form,
            function () {
                $this->proceeded = true;

                return 'core';
            }
        );
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}> $values option id, value and position
     * @return array<string, mixed>
     */
    private static function rating(string $name, int $position, array $values, string $entityCode = 'product'): array
    {
        return [
            'name' => $name,
            'position' => $position,
            'entityCode' => $entityCode,
            'values' => array_map(
                static fn(array $value) => ['valueId' => base64_encode((string)$value[0]), 'value' => $value[1], 'position' => $value[2]],
                $values
            ),
        ];
    }

    public function testTheProductRatingsComeByPositionWithTheirOptionsByPosition(): void
    {
        $collection = $this->ratings([
            4 => self::rating('Value', 1, [[17, '2', 2], [16, '1', 1]]),
            2 => self::rating('Price', 1, [[6, '1', 1]]),
            3 => self::rating('Quality', 0, [[11, '1', 1]]),
            9 => self::rating('Seller', 0, [[40, '1', 1]], 'customer'),
        ]);

        $this->assertFalse($this->proceeded, 'the rating and option reads are what this avoids');
        $this->assertSame(3, $collection->getSize());
        $this->assertSame(['3', '2', '4'], array_map(static fn($rating) => $rating->getData('rating_id'), array_values($collection->getItems())));
        $value = array_values($collection->getItems())[2];
        $this->assertSame('Value', $value->getData('rating_code'));
        $this->assertSame(
            [['option_id' => '16', 'rating_id' => '4', 'value' => '1', 'position' => '1'], ['option_id' => '17', 'rating_id' => '4', 'value' => '2', 'position' => '2']],
            array_map(static fn($option) => $option->getData(), $value->getData('options'))
        );
    }

    public function testAStoreViewWithoutRatingDocumentsHasNoRatings(): void
    {
        // Core's form shows none where the store view carries no active rating.
        $collection = $this->ratings([]);

        $this->assertFalse($this->proceeded);
        $this->assertSame(0, $collection->getSize());
    }

    public function testAProductWithoutADocumentIsCores(): void
    {
        $this->assertSame('core', $this->ratings([3 => self::rating('Quality', 0, [])], false));
    }

    public function testSingleStoreModeIsCores(): void
    {
        // Core then lists a rating whatever store views it is assigned to.
        $this->assertSame('core', $this->ratings([3 => self::rating('Quality', 0, [])], true, true));
    }

    public function testADocumentWithoutThePositionOrTheEntityStopsTheReadAndIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('has no position or entityCode'));

        $this->expectException(DocumentReadException::class);
        $this->ratings([3 => ['name' => 'Quality', 'values' => []]], logger: $logger);
    }

    public function testAFailedReadStopsTheRead(): void
    {
        $this->expectException(DocumentReadException::class);
        $this->expectExceptionMessage('No alive nodes');
        $this->ratings(new \RuntimeException('No alive nodes'));
    }
}
