<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontInventory\Test\Unit\Model;

use GraphCommerce\CatalogStorefrontInventory\Model\ResourceModel\ProductSkus;
use GraphCommerce\CatalogStorefrontInventory\Model\StockFeedSchedule;
use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Mview\View\ChangelogInterface;
use Magento\Framework\Mview\ViewInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Stock rows scheduled for the stock status feed by product id or by sku.
 */
class StockFeedScheduleTest extends TestCase
{
    private ProductSkus&MockObject $skus;

    private IndexerInterface&MockObject $indexer;

    private CommerceDataExportLoggerInterface&MockObject $logger;

    private function schedule(bool $scheduled = true): StockFeedSchedule
    {
        $this->skus = $this->createMock(ProductSkus::class);
        $changelog = $this->createStub(ChangelogInterface::class);
        $changelog->method('getName')->willReturn('inventory_data_exporter_stock_status_feed_cl');
        $changelog->method('getColumnName')->willReturn('entity_id');
        $view = $this->createStub(ViewInterface::class);
        $view->method('getChangelog')->willReturn($changelog);
        $this->indexer = $this->createMock(IndexerInterface::class);
        $this->indexer->method('isScheduled')->willReturn($scheduled);
        $this->indexer->method('getView')->willReturn($view);
        $registry = $this->createStub(IndexerRegistry::class);
        $registry->method('get')->willReturn($this->indexer);
        $this->logger = $this->createMock(CommerceDataExportLoggerInterface::class);

        return new StockFeedSchedule($this->skus, $registry, $this->logger);
    }

    public function testAScheduledStockFeedGetsTheProductsSkusInItsChangelog(): void
    {
        $schedule = $this->schedule();
        $this->skus->expects($this->once())->method('queue')
            ->with([12, 7], 'inventory_data_exporter_stock_status_feed_cl', 'entity_id');
        $this->indexer->expects($this->never())->method('reindexList');

        $schedule->products(['12', 7, 12]);
    }

    public function testAScheduledStockFeedGetsSkusInItsChangelog(): void
    {
        $schedule = $this->schedule();
        $this->skus->expects($this->once())->method('queueSkus')
            ->with(['24-MB02', 'WT09'], 'inventory_data_exporter_stock_status_feed_cl', 'entity_id');

        $schedule->skus(['24-MB02', 'WT09', '24-MB02', '']);
    }

    public function testAStockFeedThatUpdatesOnSaveReindexesAtOnce(): void
    {
        // As core's source item plugin does.
        $schedule = $this->schedule(false);
        $this->skus->method('skus')->with([12])->willReturn(['WT09']);
        $this->skus->expects($this->never())->method('queue');
        $this->indexer->expects($this->exactly(2))->method('reindexList')
            ->willReturnCallback(fn(array $skus) => $this->assertContains($skus, [['WT09'], ['24-MB02']]));

        $schedule->products([12]);
        $schedule->skus(['24-MB02']);
    }

    public function testNothingToScheduleTouchesNothing(): void
    {
        $schedule = $this->schedule();
        $this->skus->expects($this->never())->method('queue');
        $this->skus->expects($this->never())->method('queueSkus');

        $schedule->products([]);
        $schedule->skus(['']);
    }

    public function testAFailureIsLoggedAndInvalidatesTheStockFeed(): void
    {
        $schedule = $this->schedule();
        $this->skus->method('queue')->willThrowException(new \RuntimeException('lock wait timeout'));
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('stock rows of 1 products failed'));
        $this->indexer->expects($this->once())->method('invalidate');

        $schedule->products([12]);
    }

    public function testAllInvalidatesTheStockFeedWithItsReason(): void
    {
        $schedule = $this->schedule();
        $this->indexer->expects($this->once())->method('invalidate');
        $this->logger->expects($this->once())->method('info')->with($this->stringContains('the products feed was resynced in full'));

        $schedule->all('the products feed was resynced in full');
    }
}
