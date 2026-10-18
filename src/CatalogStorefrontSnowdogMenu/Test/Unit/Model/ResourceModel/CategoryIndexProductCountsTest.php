<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Test\Unit\Model\ResourceModel;

use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\ResourceModel\CategoryIndexProductCounts;
use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Model\ResourceModel\Db\Context;
use PHPUnit\Framework\TestCase;

/**
 * The rows of categories in the category product index of a store view.
 */
class CategoryIndexProductCountsTest extends TestCase
{
    public function testTheRowsAreCountedInTheIndexOfTheStoreView(): void
    {
        $select = $this->createMock(Select::class);
        $select->expects($this->once())->method('from')->with('catalog_category_product_index_store1')->willReturnSelf();
        $select->expects($this->once())->method('where')->with('category_id IN (?)', [20, 21])->willReturnSelf();
        $select->expects($this->once())->method('group')->with('category_id')->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchPairs')->with($select)->willReturn(['20' => '5']);
        $tables = $this->createMock(TableMaintainer::class);
        $tables->method('getMainTable')->with(1)->willReturn('catalog_category_product_index_store1');
        $resource = $this->getMockBuilder(CategoryIndexProductCounts::class)
            ->setConstructorArgs([$this->createMock(Context::class), $tables])
            ->onlyMethods(['getConnection'])
            ->getMock();
        $resource->method('getConnection')->willReturn($connection);

        // A category without rows is absent, as fetchPairs answers for core's menu.
        $this->assertSame([20 => 5], $resource->counts(1, [20, 21]));
    }
}
