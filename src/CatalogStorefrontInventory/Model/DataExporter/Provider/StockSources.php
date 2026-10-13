<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontInventory\Model\DataExporter\Provider;

use Magento\Framework\App\ResourceConnection;

/**
 * Adds the source items of a stock status row: one entry per enabled source of the stock that
 * holds the sku, in source priority order, with the physical quantity and the source location.
 */
class StockSources
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    public function get(array $values): array
    {
        $skus = array_values(array_unique(array_map(static fn(array $value) => (string)$value['sku'], $values)));
        $stockIds = array_values(array_unique(array_map(static fn(array $value) => (int)$value['stockId'], $values)));
        if (!$skus) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();
        $sourceTable = $this->resourceConnection->getTableName('inventory_source');
        $pickup = $connection->tableColumnExists($sourceTable, 'is_pickup_location_active') ? 's.is_pickup_location_active' : '0';
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['i' => $this->resourceConnection->getTableName('inventory_source_item')], ['sku', 'source_code', 'quantity', 'status'])
                ->join(['l' => $this->resourceConnection->getTableName('inventory_source_stock_link')], 'l.source_code = i.source_code', ['stock_id'])
                ->join(['s' => $sourceTable], 's.source_code = i.source_code AND s.enabled = 1', ['name', 'latitude', 'longitude', 'postcode', 'city', 'country_id', 'pickup' => new \Zend_Db_Expr($pickup)])
                ->where('i.sku IN (?)', $skus)
                ->where('l.stock_id IN (?)', $stockIds)
                ->order(['l.stock_id ASC', 'l.priority ASC', 'i.source_code ASC'])
        );
        $output = [];
        foreach ($rows as $row) {
            $output[$row['stock_id'] . '_' . $row['sku'] . '_' . $row['source_code']] = [
                'sku' => $row['sku'],
                'stockId' => $row['stock_id'],
                'sources' => [
                    'sourceCode' => $row['source_code'],
                    'name' => $row['name'],
                    'quantity' => (float)$row['quantity'],
                    'inStock' => (bool)$row['status'],
                    'pickupLocation' => (bool)$row['pickup'],
                    'latitude' => $row['latitude'] === null ? null : (float)$row['latitude'],
                    'longitude' => $row['longitude'] === null ? null : (float)$row['longitude'],
                    'postcode' => $row['postcode'],
                    'city' => $row['city'],
                    'countryCode' => $row['country_id'],
                ],
            ];
        }

        return $output;
    }
}
