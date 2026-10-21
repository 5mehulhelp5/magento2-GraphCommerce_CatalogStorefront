<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontInventory\Plugin\InventoryIndexer;

use GraphCommerce\CatalogStorefrontInventory\Model\StockFeedSchedule;
use Magento\InventoryIndexer\Model\Queue\ReservationData;
use Magento\InventoryIndexer\Model\Queue\UpdateIndexSalabilityStatus;

/**
 * Schedules the stock rows of the products whose salability MSI recalculated after an order.
 *
 * An order places reservations, and the stock status feed's reservation trigger exports the
 * product's row at once, before MSI's inventory.reservations.updateSalabilityStatus consumer
 * recalculates salability with the reservations: it writes cataloginventory_stock_status for the
 * default stock and the stock's index for another, which the stock feed does not watch. The
 * consumer's handler returns the skus whose salability changed (their parents among them), so a
 * product sold out on its last order goes out of stock in its document too.
 *
 * Core also reaches the document through the products feed (Magento_CatalogInventoryDataExporter,
 * then QueueStockOfExportedProducts), but only with that feed on schedule, and a failure there is
 * only a warning. Here a failure invalidates the stock feed.
 */
class QueueStockOfSalabilityChanges
{
    public function __construct(
        private readonly StockFeedSchedule $schedule,
    ) {
    }

    /**
     * @param UpdateIndexSalabilityStatus $subject
     * @param array<string, bool> $result the new salability by sku, of the skus whose salability changed
     * @param ReservationData $reservationData
     * @return array<string, bool>
     */
    public function afterExecute(UpdateIndexSalabilityStatus $subject, array $result, ReservationData $reservationData): array
    {
        $this->schedule->skus(array_map('strval', array_keys($result)));

        return $result;
    }
}
