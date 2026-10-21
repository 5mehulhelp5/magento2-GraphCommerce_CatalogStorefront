<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontInventory\Model;

use GraphCommerce\CatalogStorefrontInventory\Model\ResourceModel\ProductSkus;
use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface;
use Magento\Framework\Indexer\IndexerRegistry;

/**
 * Schedules stock rows for the stock status feed when something its own triggers do not watch
 * changed them: the stock item settings (through the products feed) and the salability MSI
 * recalculates after an order.
 *
 * A stock feed on schedule gets the skus in its changelog; one that updates on save reindexes them
 * at once, as core's source item plugin does. A failure is logged and invalidates the stock feed
 * for a full resync: the change has happened, and a missed schedule would leave the documents
 * stale without a sign.
 */
class StockFeedSchedule
{
    private const STOCK_STATUS_FEED = 'inventory_data_exporter_stock_status';

    public function __construct(
        private readonly ProductSkus $productSkus,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly CommerceDataExportLoggerInterface $logger,
    ) {
    }

    /**
     * @param array<int|string> $productIds
     */
    public function products(array $productIds): void
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return;
        }
        $this->schedule(
            count($productIds),
            fn(string $changelog, string $column) => $this->productSkus->queue($productIds, $changelog, $column),
            fn(): array => $this->productSkus->skus($productIds)
        );
    }

    /**
     * @param string[] $skus
     */
    public function skus(array $skus): void
    {
        $skus = array_values(array_unique(array_filter(array_map('strval', $skus), static fn(string $sku): bool => $sku !== '')));
        if ($skus === []) {
            return;
        }
        $this->schedule(
            count($skus),
            fn(string $changelog, string $column) => $this->productSkus->queueSkus($skus, $changelog, $column),
            static fn(): array => $skus
        );
    }

    /**
     * Invalidates the stock status feed, so the index cron resyncs every stock row.
     *
     * @param string $reason why, for the log
     */
    public function all(string $reason): void
    {
        try {
            $this->indexerRegistry->get(self::STOCK_STATUS_FEED)->invalidate();
            $this->logger->info(sprintf('Stock documents: %s, so the stock status feed is invalidated for a full resync.', $reason));
        } catch (\Throwable $e) {
            $this->logger->critical(
                sprintf('Stock documents: invalidating the stock status feed failed (%s); run a stock feed resync: %s', $reason, $e->getMessage()),
                ['exception' => $e]
            );
        }
    }

    /**
     * @param int $count the products, for the log
     * @param \Closure(string, string): void $queue inserts the skus into the changelog and its column
     * @param \Closure(): string[] $skus the skus, for a feed that updates on save
     */
    private function schedule(int $count, \Closure $queue, \Closure $skus): void
    {
        try {
            $indexer = $this->indexerRegistry->get(self::STOCK_STATUS_FEED);
            if ($indexer->isScheduled()) {
                $changelog = $indexer->getView()->getChangelog();
                $queue($changelog->getName(), $changelog->getColumnName());

                return;
            }
            $list = $skus();
            if ($list !== []) {
                $indexer->reindexList($list);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf(
                    'Stock documents: scheduling the stock rows of %d products failed, so the stock status feed is invalidated for a full resync: %s',
                    $count,
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
            $this->all('scheduling stock rows failed');
        }
    }
}
