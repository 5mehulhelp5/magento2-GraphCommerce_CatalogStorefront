<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontInventory\Plugin\DataExporter;

use GraphCommerce\CatalogStorefrontInventory\Model\StockFeedSchedule;
use Magento\DataExporter\Model\Indexer\FeedIndexer;

/**
 * Schedules the stock rows of the products the products feed exports.
 *
 * The stock slice carries a product's stock item settings (sale limits, quantity increments,
 * decimal quantity, stock management, backorders) from cataloginventory_stock_item, which the
 * stock status feed's own triggers (source items and reservations) do not watch. The products feed
 * does watch that table, by product id, and a trigger of the stock status feed on it cannot be
 * added safely: the table is shared with other views, and core builds a shared table's trigger
 * with the subscription model of the view that subscribes last, which would write product ids
 * into a changelog of skus. So every run of the products feed schedules the stock rows of its
 * products as well; the stock feed exports only the rows whose content changed. A full sync of the
 * products feed names no products, so it invalidates the stock feed for a full resync.
 *
 * The products feed's indexer is a virtual type of the feed indexer, and a `<type>` declaration for
 * a virtual type's name replaces its definition (the name is then taken for a class that does not
 * exist), so the plugin is on the feed indexer class and acts for the products feed only.
 */
class QueueStockOfExportedProducts
{
    private const PRODUCTS_FEED = 'products';

    public function __construct(
        private readonly StockFeedSchedule $schedule,
    ) {
    }

    /**
     * @param FeedIndexer $subject
     * @param mixed $result
     * @param int[] $ids
     * @return mixed
     */
    public function afterExecute(FeedIndexer $subject, $result, $ids)
    {
        if ($this->isProductsFeed($subject)) {
            $this->schedule->products((array)$ids);
        }

        return $result;
    }

    /**
     * @param FeedIndexer $subject
     * @param mixed $result
     * @param int[] $ids
     * @return mixed
     */
    public function afterExecuteList(FeedIndexer $subject, $result, array $ids)
    {
        if ($this->isProductsFeed($subject)) {
            $this->schedule->products($ids);
        }

        return $result;
    }

    /**
     * @param FeedIndexer $subject
     * @param mixed $result
     * @param int $id
     * @return mixed
     */
    public function afterExecuteRow(FeedIndexer $subject, $result, $id)
    {
        if ($this->isProductsFeed($subject)) {
            $this->schedule->products([$id]);
        }

        return $result;
    }

    /**
     * @param FeedIndexer $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterExecuteFull(FeedIndexer $subject, $result)
    {
        if ($this->isProductsFeed($subject)) {
            $this->schedule->all('the products feed was resynced in full');
        }

        return $result;
    }

    /**
     * Only the products feed's ids are products.
     */
    private function isProductsFeed(FeedIndexer $indexer): bool
    {
        return $indexer->getFeedIndexMetadata()->getFeedName() === self::PRODUCTS_FEED;
    }
}
