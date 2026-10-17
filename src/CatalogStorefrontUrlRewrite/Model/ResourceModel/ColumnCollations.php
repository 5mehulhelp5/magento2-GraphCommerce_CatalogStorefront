<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontUrlRewrite\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * The collation of each column of the url rewrite table, which decides how a lookup compares it.
 */
class ColumnCollations
{
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    /**
     * @return array<string, string|null> by column name; null for a column without a collation
     */
    public function byColumn(): array
    {
        $connection = $this->resource->getConnection();
        $columns = $connection->fetchAll(
            'SHOW FULL COLUMNS FROM ' . $connection->quoteIdentifier($this->resource->getTableName('url_rewrite'))
        );

        return array_column($columns, 'Collation', 'Field');
    }
}
