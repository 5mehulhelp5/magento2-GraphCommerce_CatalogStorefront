<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontInventory\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * The skus of products by id, as the stock status feed keys its rows and its changelog, and their
 * insertion into that changelog.
 */
class ProductSkus extends AbstractDb
{
    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('catalog_product_entity', 'entity_id');
    }

    /**
     * @param int[] $productIds
     * @return string[] the skus of the products that exist
     */
    public function skus(array $productIds): array
    {
        $connection = $this->getConnection();

        return array_map('strval', $connection->fetchCol(
            $connection->select()->from($this->getMainTable(), ['sku'])->where('entity_id IN (?)', $productIds)
        ));
    }

    /**
     * Inserts the skus of the products into a changelog, in one statement.
     *
     * @param int[] $productIds
     */
    public function queue(array $productIds, string $changelog, string $column): void
    {
        $connection = $this->getConnection();
        $connection->query($connection->insertFromSelect(
            $connection->select()->from($this->getMainTable(), ['sku'])->where('entity_id IN (?)', $productIds),
            $this->getTable($changelog),
            [$column]
        ));
    }

    /**
     * Inserts skus into a changelog, in one statement.
     *
     * @param string[] $skus
     */
    public function queueSkus(array $skus, string $changelog, string $column): void
    {
        $this->getConnection()->insertArray(
            $this->getTable($changelog),
            [$column],
            array_map(static fn(string $sku): array => [$sku], $skus)
        );
    }
}
