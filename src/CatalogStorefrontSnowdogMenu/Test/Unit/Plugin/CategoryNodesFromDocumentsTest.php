<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontCategoryFrontend\Model\Read\CategoryDocuments;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\Read\MenuDocuments;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Plugin\CategoryNodesFromDocuments;
use GraphCommerce\CatalogStorefrontUrlRewrite\Model\Collation;
use GraphCommerce\CatalogStorefrontUrlRewrite\Model\Read\UrlRewriteRows;
use Magento\Catalog\Model\Category as CategoryModel;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Snowdog\Menu\Model\Menu\Node;
use Snowdog\Menu\Model\NodeType\Category;

/**
 * The data of the category nodes of a menu as NodeType\Category::fetchData() answers it: the nodes by
 * id, the request path and the index count of the categories that have one, the categories by id and
 * a cache tag per node.
 */
class CategoryNodesFromDocumentsTest extends TestCase
{
    private bool $proceeded = false;

    private CategoryDocuments&MockObject $categories;

    private UrlRewriteRows&MockObject $urlRewriteRows;

    /** @var array<int, CategoryModel> */
    private array $models = [];

    private function plugin(array $documents, array|\Throwable $rows = [], bool $served = true, ?LoggerInterface $logger = null): CategoryNodesFromDocuments
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn('1');
        $store->method('getCode')->willReturn('default');
        $menus = $this->createStub(MenuDocuments::class);
        $menus->method('store')->willReturn($served ? $store : null);
        $this->categories = $this->createMock(CategoryDocuments::class);
        $this->categories->method('documents')->willReturnCallback(
            static fn(string $code, array $ids) => array_intersect_key($documents, array_flip($ids))
        );
        $this->categories->method('models')->willReturnCallback(function (string $code, int $storeId, array $ids) use ($documents) {
            foreach (array_keys(array_intersect_key($documents, array_flip($ids))) as $id) {
                $this->models[$id] ??= $this->createStub(CategoryModel::class);
            }

            return array_intersect_key($this->models, array_flip($ids));
        });
        $this->urlRewriteRows = $this->createMock(UrlRewriteRows::class);
        if ($rows instanceof \Throwable) {
            $this->urlRewriteRows->method('rows')->willThrowException($rows);
        } else {
            $this->urlRewriteRows->method('rows')->willReturn($rows);
        }

        return new CategoryNodesFromDocuments(
            $menus,
            $this->categories,
            $this->urlRewriteRows,
            new Collation(),
            new Strict($this->createStub(StorefrontKey::class), $logger ?? $this->createStub(LoggerInterface::class))
        );
    }

    private function node(int $id, string $categoryId): Node
    {
        $node = $this->createStub(Node::class);
        $node->method('getId')->willReturn((string)$id);
        $node->method('getContent')->willReturn($categoryId);

        return $node;
    }

    private function fetch(CategoryNodesFromDocuments $plugin, array $nodes, int|string $storeId = '1'): array
    {
        return $plugin->aroundFetchData($this->createStub(Category::class), function () {
            $this->proceeded = true;

            return [];
        }, $nodes, $storeId);
    }

    public function testTheAnswerHasTheShapeOfCores(): void
    {
        $plugin = $this->plugin(
            [20 => ['categoryId' => '20', 'menuProductCount' => 5], 21 => ['categoryId' => '21', 'menuProductCount' => 0]],
            [
                11 => ['entity_id' => '20', 'request_path' => 'women.html'],
                12 => ['entity_id' => '20', 'request_path' => 'ladies.html'],
            ]
        );
        $nodes = [$this->node(7, '21'), $this->node(8, '20'), $this->node(9, '21')];

        [$localNodes, $urls, $categories, $counts, $tags] = $this->fetch($plugin, $nodes);

        $this->assertFalse($this->proceeded, 'three queries per menu are what this avoids');
        $this->assertSame([7 => $nodes[0], 8 => $nodes[1], 9 => $nodes[2]], $localNodes);
        $this->assertSame([20 => 'ladies.html'], $urls, 'the last row of a category wins, as in core\'s pairs');
        $this->assertSame([20 => $this->models[20], 21 => $this->models[21]], $categories);
        $this->assertSame([20 => '5'], $counts, 'a category without index rows has no count');
        $this->assertSame(['cat_c_p_21', 'cat_c_p_20', 'cat_c_p_21'], $tags, 'a tag per node');
    }

    public function testTheRewritesAreTheCategoriesOwnWithoutRedirect(): void
    {
        $plugin = $this->plugin([20 => ['menuProductCount' => 1], 21 => ['menuProductCount' => 1]]);
        $this->urlRewriteRows->expects($this->once())->method('rows')->with('default', [[
            'entity_type_key' => (new Collation())->fold('category'),
            'redirect_type' => '0',
            'entity_id' => ['20', '21'],
        ]]);

        $this->fetch($plugin, [$this->node(7, '21'), $this->node(8, '20')]);
    }

    public function testACategoryWithoutADocumentStopsTheReadAndIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            $this->stringContains('Categories 21 of a menu have no document in store view "default"')
        );
        $plugin = $this->plugin([20 => ['menuProductCount' => 1]], logger: $logger);

        $this->expectException(DocumentReadException::class);
        $this->fetch($plugin, [$this->node(7, '20'), $this->node(8, '21')]);
    }

    public function testADocumentWithoutTheCountStopsTheRead(): void
    {
        $plugin = $this->plugin([20 => ['categoryId' => '20']]);

        $this->expectException(DocumentReadException::class);
        $this->expectExceptionMessage('run bin/magento catalog-storefront:rebuild category');
        $this->fetch($plugin, [$this->node(7, '20')]);
    }

    public function testAFailedRewriteReadStopsTheRead(): void
    {
        $plugin = $this->plugin([20 => ['menuProductCount' => 1]], new DocumentReadException('no url rewrite documents'));

        $this->expectException(DocumentReadException::class);
        $this->expectExceptionMessage('no url rewrite documents');
        $this->fetch($plugin, [$this->node(7, '20')]);
    }

    public function testAnotherStoreViewOrOneServedFromTheDatabaseReadsTheTables(): void
    {
        $this->fetch($this->plugin([]), [$this->node(7, '20')], 2);
        $this->assertTrue($this->proceeded);

        $this->proceeded = false;
        $this->fetch($this->plugin([], served: false), [$this->node(7, '20')]);
        $this->assertTrue($this->proceeded);
    }
}
