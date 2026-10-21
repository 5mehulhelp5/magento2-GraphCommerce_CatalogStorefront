<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontInventoryFrontend\Plugin;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use Magento\CatalogInventory\Api\Data\StockItemInterfaceFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Registry;

/**
 * Answers the stock item of the product page's product from its document.
 *
 * The product page asks the stock registry for its product's stock item: the quantity box reads
 * the sale limits and the quantity increments. A product built from its document carries the
 * stock slice of its store view, which holds the legacy stock item settings of
 * cataloginventory_stock_item; a setting that follows the configuration is null there. The answer
 * is core's stock item model with that data, the use-config flags set for the null settings, so
 * its getters read the configuration (the minimum sale quantity of the customer group among them)
 * as for an item loaded from the table. The values have the table's form: decimals with four
 * places, flags as integer strings. The columns the slice does not carry (item id, website, low
 * stock date and the like) are absent.
 *
 * The stock feed exports no row for a bundle or a grouped product, so their stock item is core's.
 * Another product, and a product loaded from the database, go to core too. A slice or a setting
 * missing from the document of another type stops the page.
 */
class StockItemFromDocument
{
    /** The product types the stock feed exports no row for. */
    private const WITHOUT_STOCK_ROW = ['bundle', 'grouped'];

    /** The settings of the slice, by the column of cataloginventory_stock_item that holds them. */
    private const SETTINGS = [
        'min_qty' => 'minQty',
        'min_sale_qty' => 'minSaleQty',
        'max_sale_qty' => 'maxSaleQty',
        'qty_increments' => 'qtyIncrements',
        'enable_qty_increments' => 'enableQtyIncrements',
        'manage_stock' => 'manageStock',
        'backorders' => 'backorders',
    ];

    /** The decimal(12,4) columns; the others are smallint. */
    private const DECIMALS = ['qty', 'min_qty', 'min_sale_qty', 'max_sale_qty', 'qty_increments'];

    public function __construct(
        private readonly Registry $registry,
        private readonly StockItemInterfaceFactory $stockItemFactory,
    ) {
    }

    /**
     * @param StockRegistryInterface $subject
     * @param \Closure $proceed
     * @param int|string $productId
     * @param int|null $scopeId
     * @return \Magento\CatalogInventory\Api\Data\StockItemInterface
     */
    public function aroundGetStockItem(StockRegistryInterface $subject, \Closure $proceed, $productId, $scopeId = null)
    {
        $product = $this->registry->registry('current_product');
        $document = $product ? $product->getData(ProductDocumentsInterface::DOCUMENT_KEY) : null;
        if (!is_array($document)
            || (int)$product->getId() !== (int)$productId
            || in_array($product->getTypeId(), self::WITHOUT_STOCK_ROW, true)
        ) {
            return $proceed($productId, $scopeId);
        }
        $stock = $document['stock'] ?? null;
        foreach (['itemQty', 'itemInStock', 'isQtyDecimal', ...array_values(self::SETTINGS)] as $field) {
            if (!is_array($stock) || !array_key_exists($field, $stock)) {
                throw new DocumentReadException('Catalog product stock item requires stock field: ' . $field);
            }
        }

        $data = [
            'product_id' => (string)(int)$productId,
            'qty' => $this->column('qty', $stock['itemQty']),
            'is_in_stock' => $this->column('is_in_stock', $stock['itemInStock']),
            'is_qty_decimal' => $this->column('is_qty_decimal', $stock['isQtyDecimal']),
        ];
        foreach (self::SETTINGS as $column => $field) {
            $useConfig = 'use_config_' . ($column === 'enable_qty_increments' ? 'enable_qty_inc' : $column);
            $data[$useConfig] = $stock[$field] === null ? '1' : '0';
            if ($stock[$field] !== null) {
                $data[$column] = $this->column($column, $stock[$field]);
            }
        }

        return $this->stockItemFactory->create(['data' => $data]);
    }

    /**
     * A value in the form the table returns it.
     */
    private function column(string $column, mixed $value): string
    {
        return in_array($column, self::DECIMALS, true)
            ? number_format((float)$value, 4, '.', '')
            : (string)(int)$value;
    }
}
