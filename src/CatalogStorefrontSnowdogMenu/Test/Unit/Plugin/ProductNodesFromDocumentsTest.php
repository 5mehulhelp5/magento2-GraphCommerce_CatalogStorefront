<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\Read\MenuDocuments;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Plugin\ProductNodesFromDocuments;
use GraphCommerce\CatalogStorefrontUrlRewrite\Model\Collation;
use GraphCommerce\CatalogStorefrontUrlRewrite\Model\Read\UrlRewriteRows;
use Magento\Bundle\Pricing\Price\FinalPrice as BundleFinalPrice;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\Framework\DataObject;
use Magento\Framework\Pricing\Amount\AmountInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\GroupedProduct\Pricing\Price\FinalPrice as GroupedFinalPrice;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Tax\Helper\Data as TaxHelper;
use Magento\Weee\Helper\Data as WeeeHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Snowdog\Menu\Model\NodeType\Product as ProductNodeType;

/**
 * The data of the product nodes of a menu as NodeType\Product::fetchData() answers it, with the
 * price the products' listing cards advertise.
 */
class ProductNodesFromDocumentsTest extends TestCase
{
    private bool $proceeded = false;

    private bool $excludingTax = false;

    private bool $weeeExcluded = false;

    private float $rate = 1.0;

    private UrlRewriteRows&MockObject $urlRewriteRows;

    /**
     * @param array<int, Product> $models the products with a document, by id
     * @param array[] $urlRows the url rewrite rows of the products, by url rewrite id
     */
    private function plugin(array $models, array $urlRows = [], bool $served = true, ?LoggerInterface $logger = null): ProductNodesFromDocuments
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn('1');
        $store->method('getCode')->willReturn('default');
        $menus = $this->createStub(MenuDocuments::class);
        $menus->method('store')->willReturn($served ? $store : null);
        $products = $this->createStub(ProductDocumentsInterface::class);
        $products->method('documents')->willReturnCallback(
            static fn(string $code, array $ids): array => array_intersect_key(array_map(static fn() => ['type' => 'simple'], $models), array_flip($ids))
        );
        $products->method('build')->willReturnCallback(
            static fn(StoreInterface $store, array $documents): array => array_intersect_key($models, $documents)
        );
        $this->urlRewriteRows = $this->createMock(UrlRewriteRows::class);
        $this->urlRewriteRows->method('rows')->willReturn($urlRows);
        $tax = $this->createStub(TaxHelper::class);
        $tax->method('displayPriceExcludingTax')->willReturnCallback(fn(): bool => $this->excludingTax);
        $weee = $this->createStub(WeeeHelper::class);
        $weee->method('typeOfDisplay')->willReturnCallback(
            fn($compareTo, $zone): bool => $this->weeeExcluded && $compareTo === [2] && $zone === 'item_list'
        );
        $currency = $this->createStub(PriceCurrencyInterface::class);
        $currency->method('convert')->willReturnCallback(fn($amount): float => $amount * $this->rate);

        return new ProductNodesFromDocuments(
            $menus,
            $products,
            $this->urlRewriteRows,
            new Collation(),
            $tax,
            $weee,
            $currency,
            new Strict($this->createStub(StorefrontKey::class), $logger ?? $this->createStub(LoggerInterface::class))
        );
    }

    private function fetch(ProductNodesFromDocuments $plugin, array $nodes, int|string $storeId = '1'): array
    {
        return $plugin->aroundFetchData($this->createStub(ProductNodeType::class), function () {
            $this->proceeded = true;

            return [];
        }, $nodes, $storeId);
    }

    private static function node(int $id, int $productId): DataObject
    {
        return new DataObject(['id' => (string)$id, 'content' => (string)$productId]);
    }

    private function amount(float $value, ?float $excludingTax = null, float $weee = 0.0, float $weeeTax = 0.0): AmountInterface
    {
        $amount = $this->createStub(AmountInterface::class);
        $amount->method('getValue')->willReturnCallback(
            static fn($exclude = null): float => $exclude === 'tax' ? ($excludingTax ?? $value) : $value
        );
        $amount->method('getAdjustmentAmount')->willReturnCallback(
            static fn(string $code) => ['weee' => $weee, 'weee_tax' => $weeeTax][$code] ?? false
        );

        return $amount;
    }

    private function product(object $finalPrice, array $data = []): Product
    {
        $priceInfo = $this->createStub(PriceInfoInterface::class);
        $priceInfo->method('getPrice')->willReturnCallback(
            static fn(string $code) => $code === 'final_price' ? $finalPrice : null
        );
        $product = $this->createStub(Product::class);
        $product->method('getPriceInfo')->willReturn($priceInfo);
        $product->method('getData')->willReturnCallback(static fn($key = '') => $data[$key] ?? null);

        return $product;
    }

    private function simple(float $value, ?float $excludingTax = null, float $weee = 0.0, float $weeeTax = 0.0): Product
    {
        $price = $this->createStub(FinalPrice::class);
        $price->method('getAmount')->willReturn($this->amount($value, $excludingTax, $weee, $weeeTax));

        return $this->product($price);
    }

    public function testTheAnswerHasTheShapeOfCores(): void
    {
        $bag = $this->createStub(FinalPrice::class);
        $bag->method('getAmount')->willReturn($this->amount(34.0));
        $strap = $this->createStub(FinalPrice::class);
        $strap->method('getAmount')->willReturn($this->amount(12.5));
        $plugin = $this->plugin(
            [20 => $this->product($bag, ['name' => 'Bag', 'thumbnail' => '/b/a/bag.jpg']), 21 => $this->product($strap)],
            [
                11 => ['entity_id' => '20', 'request_path' => 'bag.html', 'metadata' => null],
                12 => ['entity_id' => '20', 'request_path' => 'gear/bag.html', 'metadata' => '{"category_id":"3"}'],
                13 => ['entity_id' => '21', 'request_path' => 'strap.html', 'metadata' => null],
                14 => ['entity_id' => '21', 'request_path' => 'strap-new.html', 'metadata' => null],
            ]
        );
        $nodes = [self::node(7, 21), self::node(8, 20), self::node(9, 21)];

        [$localNodes, $urls, $prices, $images, $titles] = $this->fetch($plugin, $nodes);

        $this->assertFalse($this->proceeded, 'four queries per menu are what this avoids');
        $this->assertSame([7 => $nodes[0], 8 => $nodes[1], 9 => $nodes[2]], $localNodes);
        $this->assertSame([20 => 'bag.html', 21 => 'strap-new.html'], $urls, 'no category path, the last row of a product wins');
        $this->assertSame([20 => 34.0, 21 => 12.5], $prices);
        $this->assertSame([20 => '/b/a/bag.jpg', 21 => ''], $images, 'a product without a thumbnail has an empty one, as in core');
        $this->assertSame([20 => 'Bag', 21 => ''], $titles);
    }

    public function testTheRewritesAreTheProductsOwnWithoutRedirect(): void
    {
        $plugin = $this->plugin([20 => $this->simple(1.0), 21 => $this->simple(1.0)]);
        $this->urlRewriteRows->expects($this->once())->method('rows')->with('default', [[
            'entity_type_key' => (new Collation())->fold('product'),
            'redirect_type' => '0',
            'entity_id' => ['20', '21'],
        ]]);

        $this->fetch($plugin, [self::node(7, 21), self::node(8, 20)]);
    }

    public function testABundleAndAGroupedProductTakeTheLowestAmountTheirBoxesShow(): void
    {
        $bundle = $this->createStub(BundleFinalPrice::class);
        $bundle->method('getMinimalPrice')->willReturn($this->amount(20.0));
        $bundle->method('getAmount')->willReturn($this->amount(99.0));
        $grouped = $this->createStub(GroupedFinalPrice::class);
        $grouped->method('getMinProduct')->willReturn($this->simple(9.0));
        $grouped->method('getAmount')->willReturn($this->amount(99.0));
        $empty = $this->createStub(GroupedFinalPrice::class);
        $empty->method('getMinProduct')->willReturn(null);
        $plugin = $this->plugin([30 => $this->product($bundle), 31 => $this->product($grouped), 32 => $this->product($empty)]);

        $prices = $this->fetch($plugin, [self::node(7, 30), self::node(8, 31), self::node(9, 32)])[2];

        // A grouped product without a product to price shows no price, as its box shows none.
        $this->assertSame([30 => 20.0, 31 => 9.0], $prices);
    }

    public function testThePriceIsTheAmountAListingDisplaysInBaseCurrency(): void
    {
        $plugin = $this->plugin([20 => $this->simple(36.0, 30.0, 2.0, 0.4)]);
        $nodes = [self::node(7, 20)];

        $this->assertSame([20 => 36.0], $this->fetch($plugin, $nodes)[2], 'including tax');
        $this->excludingTax = true;
        $this->assertSame([20 => 30.0], $this->fetch($plugin, $nodes)[2], 'excluding tax');
        $this->weeeExcluded = true;
        $this->assertEqualsWithDelta(27.6, $this->fetch($plugin, $nodes)[2][20], 1e-9, 'excluding the fixed product tax with its description');
        // The node block converts the price to the display currency, as it converts core's.
        $this->rate = 1.2;
        $this->assertEqualsWithDelta(23.0, $this->fetch($plugin, $nodes)[2][20], 1e-9);
    }

    public function testAProductWithoutADocumentStopsTheReadAndIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            $this->stringContains('Products 21 of a menu have no document in store view "default"')
        );
        $plugin = $this->plugin([20 => $this->simple(1.0)], logger: $logger);

        $this->expectException(DocumentReadException::class);
        $this->fetch($plugin, [self::node(7, 20), self::node(8, 21)]);
    }

    public function testAnotherStoreViewOrOneServedFromTheDatabaseReadsTheTables(): void
    {
        $this->fetch($this->plugin([]), [self::node(7, 20)], 2);
        $this->assertTrue($this->proceeded);

        $this->proceeded = false;
        $this->fetch($this->plugin([], served: false), [self::node(7, 20)]);
        $this->assertTrue($this->proceeded);
    }
}
