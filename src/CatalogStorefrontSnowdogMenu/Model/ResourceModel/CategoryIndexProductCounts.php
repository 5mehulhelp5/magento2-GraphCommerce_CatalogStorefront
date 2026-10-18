<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Model\ResourceModel;

use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;

/**
 * The rows of a category in the category product index of a store view, counted as a category node of
 * a Snowdog menu counts them: every row, whatever the visibility of the product.
 */
class CategoryIndexProductCounts extends AbstractDb
{
    /**
     * @param Context $context
     * @param TableMaintainer $tableMaintainer
     * @param string $connectionName
     */
    public function __construct(
        Context $context,
        private readonly TableMaintainer $tableMaintainer,
        $connectionName = null,
    ) {
        parent::__construct($context, $connectionName);
    }

    /**
     * @inheritDoc
     */
    protected function _construct(): void
    {
        $this->_init('catalog_category_product_index', 'category_id');
    }

    /**
     * @param int[] $categoryIds
     * @return array<int, int> by category id, the count of the categories with rows in the index of the store view
     */
    public function counts(int $storeId, array $categoryIds): array
    {
        $connection = $this->getConnection();

        return array_map('intval', $connection->fetchPairs(
            $connection->select()
                ->from(
                    $this->tableMaintainer->getMainTable($storeId),
                    ['category_id', 'count' => new Expression('COUNT(product_id)')]
                )
                ->where('category_id IN (?)', $categoryIds)
                ->group('category_id')
        ));
    }
}
