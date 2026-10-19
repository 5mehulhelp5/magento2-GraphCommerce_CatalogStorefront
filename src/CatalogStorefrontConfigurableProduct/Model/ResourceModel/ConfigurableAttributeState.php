<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProduct\Model\ResourceModel;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;

/**
 * What a configurable's document copies from a configurable attribute and core's option label
 * resync does not compare: the attribute's labels and the swatches of its options. Also the
 * products whose documents copy them, and their queueing in a feed's changelog.
 */
class ConfigurableAttributeState extends AbstractDb
{
    /**
     * @param Context $context
     * @param MetadataPool $metadataPool
     * @param string|null $connectionName
     */
    public function __construct(
        Context $context,
        private readonly MetadataPool $metadataPool,
        $connectionName = null,
    ) {
        parent::__construct($context, $connectionName);
    }

    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('catalog_product_super_attribute', 'product_super_attribute_id');
    }

    /**
     * Whether a configurable uses the attribute.
     */
    public function isUsed(int $attributeId): bool
    {
        $connection = $this->getConnection();

        return (bool)$connection->fetchOne(
            $connection->select()
                ->from($this->getMainTable(), ['attribute_id'])
                ->where('attribute_id = ?', $attributeId)
                ->limit(1)
        );
    }

    /**
     * @return array<int, string> the attribute's labels by store id, store 0 its default label
     */
    public function labels(int $attributeId): array
    {
        $connection = $this->getConnection();
        $labels = $connection->fetchPairs(
            $connection->select()
                ->from($this->getTable('eav_attribute_label'), ['store_id', 'value'])
                ->where('attribute_id = ?', $attributeId)
        );
        $labels[0] = $connection->fetchOne(
            $connection->select()
                ->from($this->getTable('eav_attribute'), ['frontend_label'])
                ->where('attribute_id = ?', $attributeId)
        );
        ksort($labels);

        return array_map('strval', $labels);
    }

    /**
     * @return array<int, array<int, string>> per option id, per store id, the swatch's type and value
     */
    public function swatches(int $attributeId): array
    {
        $connection = $this->getConnection();
        $swatches = [];
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['swatch' => $this->getTable('eav_attribute_option_swatch')], ['option_id', 'store_id', 'type', 'value'])
                ->join(['eao' => $this->getTable('eav_attribute_option')], 'eao.option_id = swatch.option_id', [])
                ->where('eao.attribute_id = ?', $attributeId)
        );
        foreach ($rows as $row) {
            $swatches[(int)$row['option_id']][(int)$row['store_id']] = $row['type'] . ':' . $row['value'];
        }
        ksort($swatches);
        foreach ($swatches as &$stores) {
            ksort($stores);
        }

        return $swatches;
    }

    /**
     * The products that store one of the options: a configurable's children, whose parents the
     * products feed adds to a sync itself (core's configurableProductsByChildren resolver).
     *
     * @param string $backendTable the attribute's value table
     * @param int[] $optionIds
     */
    public function productsWithOptions(int $attributeId, string $backendTable, array $optionIds): Select
    {
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();

        return $this->getConnection()->select()
            ->distinct()
            ->from(['product' => $this->getTable('catalog_product_entity')], ['entity_id'])
            ->join(['eav' => $backendTable], sprintf('eav.%1$s = product.%1$s', $linkField), [])
            ->where('eav.attribute_id = ?', $attributeId)
            ->where('eav.value IN (?)', $optionIds);
    }

    /**
     * The configurables that use the attribute.
     */
    public function configurables(int $attributeId): Select
    {
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();

        return $this->getConnection()->select()
            ->distinct()
            ->from(['product' => $this->getTable('catalog_product_entity')], ['entity_id'])
            ->join(['cpsa' => $this->getMainTable()], sprintf('cpsa.product_id = product.%s', $linkField), [])
            ->where('cpsa.attribute_id = ?', $attributeId);
    }

    /**
     * The number of products the select finds, counted no further than the limit.
     */
    public function count(Select $products, int $limit): int
    {
        $connection = $this->getConnection();

        return (int)$connection->fetchOne(
            $connection->select()->from(['ids' => (clone $products)->limit($limit)], [new Expression('COUNT(1)')])
        );
    }

    /**
     * Inserts the products the select finds into a changelog, in one statement.
     */
    public function queue(Select $products, string $changelog, string $column): void
    {
        $connection = $this->getConnection();
        $connection->query($connection->insertFromSelect($products, $this->getTable($changelog), [$column]));
    }
}
