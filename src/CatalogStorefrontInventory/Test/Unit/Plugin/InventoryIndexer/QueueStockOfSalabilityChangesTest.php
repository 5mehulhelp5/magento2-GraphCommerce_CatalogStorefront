<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontInventory\Test\Unit\Plugin\InventoryIndexer;

use GraphCommerce\CatalogStorefrontInventory\Model\StockFeedSchedule;
use GraphCommerce\CatalogStorefrontInventory\Plugin\InventoryIndexer\QueueStockOfSalabilityChanges;
use Magento\InventoryIndexer\Model\Queue\ReservationData;
use Magento\InventoryIndexer\Model\Queue\UpdateIndexSalabilityStatus;
use PHPUnit\Framework\TestCase;

/**
 * MSI's salability update after an order schedules the stock rows whose salability changed.
 */
class QueueStockOfSalabilityChangesTest extends TestCase
{
    public function testTheSkusWhoseSalabilityChangedAreScheduled(): void
    {
        // The handler returns only the changed skus, with the parents of changed children.
        $schedule = $this->createMock(StockFeedSchedule::class);
        $schedule->expects($this->once())->method('skus')->with(['24-MB02', 'WT09']);
        $result = ['24-MB02' => false, 'WT09' => true];

        $this->assertSame($result, (new QueueStockOfSalabilityChanges($schedule))->afterExecute(
            $this->createStub(UpdateIndexSalabilityStatus::class),
            $result,
            new ReservationData(['24-MB02'], 1)
        ));
    }

    public function testNoChangeSchedulesNothing(): void
    {
        $schedule = $this->createMock(StockFeedSchedule::class);
        $schedule->expects($this->once())->method('skus')->with([]);

        (new QueueStockOfSalabilityChanges($schedule))->afterExecute(
            $this->createStub(UpdateIndexSalabilityStatus::class),
            [],
            new ReservationData(['24-MB02'], 1)
        );
    }
}
