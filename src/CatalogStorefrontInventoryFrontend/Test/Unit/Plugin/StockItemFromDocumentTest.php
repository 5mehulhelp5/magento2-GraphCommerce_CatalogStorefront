<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontInventoryFrontend\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontInventoryFrontend\Plugin\StockItemFromDocument;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\Data\StockItemInterfaceFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;

/**
 * The stock item of the product page's product, from the stock slice of its document.
 */
class StockItemFromDocumentTest extends TestCase
{
    private const STOCK = [
        'itemQty' => 100.0,
        'itemInStock' => true,
        'isQtyDecimal' => false,
        'minQty' => null,
        'minSaleQty' => 2.0,
        'maxSaleQty' => null,
        'qtyIncrements' => 2.0,
        'enableQtyIncrements' => 1.0,
        'manageStock' => null,
        'backorders' => 0.0,
    ];

    /** @var array<string, string>|null the data the factory was given */
    private ?array $created = null;

    private bool $proceeded = false;

    private function plugin(?Product $current): StockItemFromDocument
    {
        $registry = $this->createStub(Registry::class);
        $registry->method('registry')->with('current_product')->willReturn($current);
        $factory = $this->createStub(StockItemInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(function (array $arguments) {
            $this->created = $arguments['data'];

            return 'stock item';
        });

        return new StockItemFromDocument($registry, $factory);
    }

    private function product(?array $stock = self::STOCK, string $type = 'configurable', int $id = 1812): Product
    {
        $product = $this->getMockBuilder(Product::class)->disableOriginalConstructor()->onlyMethods(['getId', 'getTypeId'])->getMock();
        $product->method('getId')->willReturn((string)$id);
        $product->method('getTypeId')->willReturn($type);
        $product->setData(ProductDocumentsInterface::DOCUMENT_KEY, $stock === null ? ['sku' => 'WT09'] : ['sku' => 'WT09', 'stock' => $stock]);

        return $product;
    }

    private function proceed(): \Closure
    {
        return function () {
            $this->proceeded = true;

            return 'core';
        };
    }

    public function testTheStockItemIsTheSlicesInTheTablesForm(): void
    {
        // A setting that follows the configuration sets its flag, so core's getters read the configuration.
        $plugin = $this->plugin($this->product());

        $this->assertSame('stock item', $plugin->aroundGetStockItem($this->createStub(StockRegistryInterface::class), $this->proceed(), 1812));
        $this->assertSame([
            'product_id' => '1812',
            'qty' => '100.0000',
            'is_in_stock' => '1',
            'is_qty_decimal' => '0',
            'use_config_min_qty' => '1',
            'use_config_min_sale_qty' => '0',
            'min_sale_qty' => '2.0000',
            'use_config_max_sale_qty' => '1',
            'use_config_qty_increments' => '0',
            'qty_increments' => '2.0000',
            'use_config_enable_qty_inc' => '0',
            'enable_qty_increments' => '1',
            'use_config_manage_stock' => '1',
            'use_config_backorders' => '0',
            'backorders' => '0',
        ], $this->created);
        $this->assertFalse($this->proceeded, 'a query per product page is what this avoids');
    }

    public function testStockItemsTheDocumentDoesNotServeAreCores(): void
    {
        foreach ([
            'no product page' => [null, 1812],
            'a product loaded from the database' => [(function () {
                $product = $this->product();
                $product->unsetData(ProductDocumentsInterface::DOCUMENT_KEY);

                return $product;
            })(), 1812],
            'another product' => [$this->product(), 7],
            'a bundle, which has no stock row' => [$this->product(null, 'bundle'), 1812],
            'a grouped product, which has no stock row' => [$this->product(null, 'grouped'), 1812],
        ] as $case => [$current, $productId]) {
            $this->proceeded = false;
            $this->assertSame('core', $this->plugin($current)->aroundGetStockItem($this->createStub(StockRegistryInterface::class), $this->proceed(), $productId), $case);
        }
    }

    public function testAMissingSliceOrSettingStopsThePage(): void
    {
        foreach (['the slice' => null, 'a setting' => array_diff_key(self::STOCK, ['maxSaleQty' => true])] as $case => $stock) {
            try {
                $this->plugin($this->product($stock))->aroundGetStockItem($this->createStub(StockRegistryInterface::class), $this->proceed(), 1812);
                $this->fail('no stop for a missing ' . $case);
            } catch (DocumentReadException $e) {
                $this->assertStringStartsWith('Catalog product stock item requires stock field:', $e->getMessage(), $case);
                $this->assertFalse($this->proceeded, $case);
            }
        }
    }
}
