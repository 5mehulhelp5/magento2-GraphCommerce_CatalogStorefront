<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontInventory\Test\Unit\Plugin\DataExporter;

use GraphCommerce\CatalogStorefrontInventory\Model\StockFeedSchedule;
use GraphCommerce\CatalogStorefrontInventory\Plugin\DataExporter\QueueStockOfExportedProducts;
use Magento\DataExporter\Model\Indexer\FeedIndexer;
use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A run of the products feed schedules the stock rows of its products.
 */
class QueueStockOfExportedProductsTest extends TestCase
{
    private StockFeedSchedule&MockObject $schedule;

    private function plugin(): QueueStockOfExportedProducts
    {
        $this->schedule = $this->createMock(StockFeedSchedule::class);

        return new QueueStockOfExportedProducts($this->schedule);
    }

    /**
     * The indexer of a feed, the products feed unless named otherwise.
     */
    private function feed(string $name = 'products'): FeedIndexer
    {
        $metadata = $this->createStub(FeedIndexMetadata::class);
        $metadata->method('getFeedName')->willReturn($name);
        $indexer = $this->createStub(FeedIndexer::class);
        $indexer->method('getFeedIndexMetadata')->willReturn($metadata);

        return $indexer;
    }

    public function testEveryPartialReindexOfTheProductsFeedSchedulesItsProducts(): void
    {
        // The products feed exports through execute (mview), executeList and executeRow alike.
        $plugin = $this->plugin();
        $this->schedule->expects($this->exactly(3))->method('products')
            ->willReturnCallback(fn(array $ids) => $this->assertContains($ids, [['12', 7], [5], [6]]));

        $this->assertSame('done', $plugin->afterExecute($this->feed(), 'done', ['12', 7]));
        $plugin->afterExecuteList($this->feed(), null, [5]);
        $plugin->afterExecuteRow($this->feed(), null, 6);
    }

    public function testAFullProductsResyncResyncsTheStockFeed(): void
    {
        // A full sync names no products; the stock rows of every product follow through a full stock resync.
        $plugin = $this->plugin();
        $this->schedule->expects($this->once())->method('all')->with('the products feed was resynced in full');
        $this->schedule->expects($this->never())->method('products');

        $this->assertSame('done', $plugin->afterExecuteFull($this->feed(), 'done'));
    }

    public function testAnotherFeedSchedulesNothing(): void
    {
        // The plugin is on the feed indexer class; another feed's ids are not product ids.
        $plugin = $this->plugin();
        $this->schedule->expects($this->never())->method('products');
        $this->schedule->expects($this->never())->method('all');

        $plugin->afterExecute($this->feed('categories'), null, [12]);
        $plugin->afterExecuteList($this->feed('productAttributes'), null, [93]);
        $plugin->afterExecuteFull($this->feed('categories'), null);
    }
}
