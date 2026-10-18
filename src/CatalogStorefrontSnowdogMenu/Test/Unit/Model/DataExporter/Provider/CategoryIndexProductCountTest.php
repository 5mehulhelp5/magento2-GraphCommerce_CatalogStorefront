<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Test\Unit\Model\DataExporter\Provider;

use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\DataExporter\Provider\CategoryIndexProductCount;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\ResourceModel\CategoryIndexProductCounts;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The count a category node of a Snowdog menu reads, on every categories feed row.
 */
class CategoryIndexProductCountTest extends TestCase
{
    public function testEveryCategoryGetsItsIndexRowsInTheStoreView(): void
    {
        $counts = $this->createMock(CategoryIndexProductCounts::class);
        $counts->expects($this->once())->method('counts')->with(1, [20, 21])->willReturn([20 => 5]);
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn('1');
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->with('default')->willReturn($store);

        $output = (new CategoryIndexProductCount($counts, $stores))->get([
            ['categoryId' => '20', 'storeViewCode' => 'default'],
            ['categoryId' => '21', 'storeViewCode' => 'default'],
            ['categoryId' => '20', 'storeViewCode' => 'default'],
        ]);

        // A category without index rows counts none, as the menu reads it.
        $this->assertSame([
            'default_20' => ['categoryId' => '20', 'storeViewCode' => 'default', 'menuProductCount' => 5],
            'default_21' => ['categoryId' => '21', 'storeViewCode' => 'default', 'menuProductCount' => 0],
        ], $output);
    }
}
