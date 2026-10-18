<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReviewFrontend\Test\Unit\Plugin\Detail;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontReviewFrontend\Model\ReviewIndex;
use GraphCommerce\CatalogStorefrontReviewFrontend\Model\ReviewSummaries;
use GraphCommerce\CatalogStorefrontReviewFrontend\Plugin\Detail\ReviewSummaryFromDocuments;
use Magento\Catalog\Model\Product;
use Magento\Review\Model\AppendSummaryData;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The review summary of a product page, as core's summary row gives it.
 */
class ReviewSummaryFromDocumentsTest extends TestCase
{
    private const MARKER = ['0' => ['count' => 1, 'avg' => null]];

    private bool $proceeded = false;

    /** @var array<string, mixed> what the product was given */
    private array $added = [];

    /**
     * @param array<string, array>|\Throwable $stats the aggregation by product id, or the read failure
     */
    private function append(array|\Throwable $stats, bool $withDocument = true, int $storeId = 1, string $entityCode = 'product', ?LoggerInterface $logger = null): void
    {
        $storage = $this->createMock(MetadataDocumentStorageInterface::class);
        if ($stats instanceof \Throwable) {
            $storage->method('stats')->willThrowException($stats);
        } else {
            $storage->method('stats')->with('review', 'default', 'productId', ['1812', '0'], 'percents')->willReturn($stats);
        }
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getCode')->willReturn('default');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(1812);
        $product->method('getData')->with(ProductDocumentsInterface::DOCUMENT_KEY)->willReturn($withDocument ? ['sku' => 'WT09'] : null);
        $product->method('addData')->willReturnCallback(function (array $data) use ($product) {
            $this->added = $data;

            return $product;
        });

        $summaries = new ReviewSummaries($storage, new ReviewIndex($this->createStub(MetadataDocumentStorageInterface::class)));
        $strict = new Strict($this->createStub(StorefrontKey::class), $logger ?? $this->createStub(LoggerInterface::class));
        (new ReviewSummaryFromDocuments($summaries, $stores, $strict))->aroundExecute(
            $this->createStub(AppendSummaryData::class),
            function () {
                $this->proceeded = true;
            },
            $product,
            $storeId,
            $entityCode
        );
    }

    public function testTheSummaryComesFromTheReviewDocuments(): void
    {
        $this->append(['1812' => ['count' => 3, 'avg' => 86.67]] + self::MARKER);

        $this->assertSame(['reviews_count' => '3', 'rating_summary' => '87'], $this->added);
        $this->assertFalse($this->proceeded, 'the summary row read is what this avoids');
    }

    public function testAProductWithoutReviewsHasNoSummary(): void
    {
        // The read of no row gives null for both.
        $this->append(self::MARKER);

        $this->assertSame(['reviews_count' => null, 'rating_summary' => null], $this->added);
        $this->assertFalse($this->proceeded);
    }

    public function testAProductWithoutADocumentReadsTheRow(): void
    {
        $this->append([], false);

        $this->assertTrue($this->proceeded);
        $this->assertSame([], $this->added);
    }

    public function testAnotherStoreViewOrEntityReadsTheRow(): void
    {
        $this->append([], true, 2);
        $this->assertTrue($this->proceeded);

        $this->proceeded = false;
        $this->append([], true, 1, 'customer');
        $this->assertTrue($this->proceeded);
    }

    public function testAStoreViewWithoutReviewDocumentsStopsTheReadAndIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('has no review documents'));

        $this->expectException(DocumentReadException::class);
        $this->append(['1812' => ['count' => 3, 'avg' => 86.67]], logger: $logger);
    }

    public function testAFailedReadStopsTheRead(): void
    {
        $this->expectException(DocumentReadException::class);
        $this->expectExceptionMessage('No alive nodes');
        $this->append(new \RuntimeException('No alive nodes'));
    }
}
