<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\MenuDocument;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\Read\MenuDocuments;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Plugin\CmsPageNodesFromDocuments;
use GraphCommerce\CatalogStorefrontUrlRewrite\Model\Collation;
use GraphCommerce\CatalogStorefrontUrlRewrite\Model\Read\UrlRewriteRows;
use Magento\Framework\DataObject;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Snowdog\Menu\Model\NodeType\CmsPage;

/**
 * The data of the CMS page nodes of a menu as NodeType\CmsPage::fetchData() answers it: the nodes
 * by id, the page ids by identifier and the urls by page id.
 */
class CmsPageNodesFromDocumentsTest extends TestCase
{
    private bool $proceeded = false;

    private MenuDocuments&MockObject $documents;

    private UrlRewriteRows&MockObject $urlRewriteRows;

    /**
     * @param array[] $rows the CMS page rows of the nodes, as the menu documents give them
     * @param array[] $urlRows the url rewrite rows of the pages
     */
    private function plugin(array $rows, array $urlRows = [], bool $served = true, ?\Throwable $failure = null): CmsPageNodesFromDocuments
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn('1');
        $store->method('getCode')->willReturn('default');
        $this->documents = $this->createMock(MenuDocuments::class);
        $this->documents->method('store')->willReturn($served ? $store : null);
        if ($failure !== null) {
            $this->documents->method('cmsRows')->willThrowException($failure);
        } else {
            $this->documents->method('cmsRows')->with($store, $this->anything(), MenuDocument::CMS_PAGES)->willReturn($rows);
        }
        $this->urlRewriteRows = $this->createMock(UrlRewriteRows::class);
        $this->urlRewriteRows->method('rows')->willReturn($urlRows);

        return new CmsPageNodesFromDocuments(
            $this->documents,
            $this->urlRewriteRows,
            new Collation(),
            new Strict($this->createStub(StorefrontKey::class), $this->createStub(LoggerInterface::class))
        );
    }

    private function fetch(CmsPageNodesFromDocuments $plugin, array $nodes, int|string $storeId = '1'): array
    {
        return $plugin->aroundFetchData($this->createStub(CmsPage::class), function () {
            $this->proceeded = true;

            return [];
        }, $nodes, $storeId);
    }

    private static function node(int $id): DataObject
    {
        return new DataObject(['id' => (string)$id, 'menu_id' => '3']);
    }

    private static function page(int $nodeId, string $pageId, string $identifier, int $storeId): array
    {
        return ['nodeId' => $nodeId, 'pageId' => $pageId, 'identifier' => $identifier, 'storeId' => $storeId];
    }

    public function testTheAnswerHasTheShapeOfCores(): void
    {
        $nodes = [self::node(7), self::node(8), self::node(9)];
        $plugin = $this->plugin(
            [
                self::page(7, '5', 'about-us', 0),
                self::page(7, '9', 'about-us', 1),
                self::page(8, '6', 'faq', 0),
                self::page(9, '4', 'contact', 0),
            ],
            [
                ['entity_id' => '9', 'request_path' => 'about-us'],
                ['entity_id' => '4', 'request_path' => 'contact-us.html'],
            ]
        );

        [$localNodes, $pageIds, $urls] = $this->fetch($plugin, $nodes);

        $this->assertFalse($this->proceeded, 'three queries per menu are what this avoids');
        $this->assertSame([7 => $nodes[0], 8 => $nodes[1], 9 => $nodes[2]], $localNodes);
        $this->assertSame(['about-us' => '9', 'faq' => '6', 'contact' => '4'], $pageIds, "the store view's own page wins");
        // A page whose identifier is no request path gets its identifier, and its rewrite where it has one.
        $this->assertSame([9 => 'about-us', 4 => 'contact-us.html', 6 => 'faq'], $urls);
    }

    public function testTheRewritesAreThoseOfThePages(): void
    {
        $plugin = $this->plugin([self::page(7, '9', 'about-us', 1), self::page(8, '6', 'faq', 0)]);
        $this->urlRewriteRows->expects($this->once())->method('rows')->with('default', [[
            'entity_type_key' => (new Collation())->fold('cms-page'),
            'entity_id' => ['9', '6'],
        ]]);

        $this->fetch($plugin, [self::node(7), self::node(8)]);
    }

    public function testNoPageReadsNoRewrite(): void
    {
        // A node that names no active page of the store view links nowhere, as in core.
        $plugin = $this->plugin([]);
        $this->urlRewriteRows->expects($this->never())->method('rows');
        $node = self::node(7);

        $this->assertSame([[7 => $node], [], []], $this->fetch($plugin, [$node]));
    }

    public function testAFailedReadStopsTheRead(): void
    {
        $plugin = $this->plugin([], failure: new DocumentReadException('Menu 3 of node 7 has no document'));

        $this->expectException(DocumentReadException::class);
        $this->expectExceptionMessage('Menu 3 of node 7 has no document');
        $this->fetch($plugin, [self::node(7)]);
    }

    public function testAnotherStoreViewOrOneServedFromTheDatabaseReadsTheTables(): void
    {
        $this->fetch($this->plugin([]), [self::node(7)], 2);
        $this->assertTrue($this->proceeded);

        $this->proceeded = false;
        $this->fetch($this->plugin([], served: false), [self::node(7)]);
        $this->assertTrue($this->proceeded);
    }
}
