<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontCategoryFrontend\Test\Unit\Model;

use GraphCommerce\CatalogStorefrontCategoryFrontend\Model\LoadedCollection;
use Magento\Catalog\Model\Category;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A collection of built categories is its categories, however often it is loaded.
 */
class LoadedCollectionTest extends TestCase
{
    private function collection(): LoadedCollection&MockObject
    {
        $collection = $this->getMockBuilder(LoadedCollection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['_loadEntities', '_loadAttributes', 'getIdFieldName'])
            ->getMock();
        $collection->expects($this->never())->method('_loadEntities');
        $collection->expects($this->never())->method('_loadAttributes');
        // Without the entity the collection keys its items by getId().
        $collection->method('getIdFieldName')->willReturn(null);
        $collection->setItemObjectClass(Category::class);

        return $collection;
    }

    private function category(int $id): Category
    {
        $category = $this->createStub(Category::class);
        $category->method('getId')->willReturn((string)$id);

        return $category;
    }

    public function testTheCategoriesAreTheItems(): void
    {
        $children = [$this->category(20), $this->category(21)];

        $collection = $this->collection()->withItems($children);

        $this->assertTrue($collection->isLoaded());
        $this->assertSame([20 => $children[0], 21 => $children[1]], $collection->getItems());
    }

    public function testAReloadAfterAClearGivesTheCategoriesBack(): void
    {
        // A select without a filter would give every category of the catalog.
        $children = [$this->category(20), $this->category(21)];
        $collection = $this->collection()->withItems($children);

        $collection->clear();
        $collection->load();

        $this->assertSame([20 => $children[0], 21 => $children[1]], $collection->getItems());
    }
}
