<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\MenuDocument;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\Read\MenuDocuments;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Plugin\CmsBlockNodesFromDocuments;
use Magento\Framework\DataObject;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Snowdog\Menu\Model\NodeType\CmsBlock;

/**
 * The content of the CMS block nodes of a menu as NodeType\CmsBlock::fetchData() answers it: the
 * nodes by id and the content by block identifier.
 */
class CmsBlockNodesFromDocumentsTest extends TestCase
{
    private bool $proceeded = false;

    private function plugin(array $rows, bool $served = true, ?\Throwable $failure = null): CmsBlockNodesFromDocuments
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn('1');
        $store->method('getCode')->willReturn('default');
        $documents = $this->createMock(MenuDocuments::class);
        $documents->method('store')->willReturn($served ? $store : null);
        if ($failure !== null) {
            $documents->method('cmsRows')->willThrowException($failure);
        } else {
            $documents->method('cmsRows')->with($store, $this->anything(), MenuDocument::CMS_BLOCKS)->willReturn($rows);
        }

        return new CmsBlockNodesFromDocuments(
            $documents,
            new Strict($this->createStub(StorefrontKey::class), $this->createStub(LoggerInterface::class))
        );
    }

    private function fetch(CmsBlockNodesFromDocuments $plugin, array $nodes, int|string $storeId = '1'): array
    {
        return $plugin->aroundFetchData($this->createStub(CmsBlock::class), function () {
            $this->proceeded = true;

            return [];
        }, $nodes, $storeId);
    }

    private static function node(int $id): DataObject
    {
        return new DataObject(['id' => (string)$id, 'menu_id' => '3']);
    }

    public function testTheContentIsThatOfTheStoreViewsOwnBlock(): void
    {
        $nodes = [self::node(7), self::node(8)];
        $plugin = $this->plugin([
            ['nodeId' => 7, 'identifier' => 'promo', 'content' => '<p>All</p>', 'storeId' => 0],
            ['nodeId' => 8, 'identifier' => 'empty', 'content' => null, 'storeId' => 0],
            ['nodeId' => 7, 'identifier' => 'promo', 'content' => '<p>Ours</p>', 'storeId' => 1],
        ]);

        [$localNodes, $content] = $this->fetch($plugin, $nodes);

        $this->assertFalse($this->proceeded, 'a query per menu is what this avoids');
        $this->assertSame([7 => $nodes[0], 8 => $nodes[1]], $localNodes);
        $this->assertSame(['promo' => '<p>Ours</p>', 'empty' => null], $content, 'a block without content renders nothing, as in core');
    }

    public function testAFailedReadStopsTheRead(): void
    {
        $plugin = $this->plugin([], failure: new DocumentReadException('Menu 3 of node 7 has no document'));

        $this->expectException(DocumentReadException::class);
        $this->fetch($plugin, [self::node(7)]);
    }

    public function testAnotherStoreViewOrOneServedFromTheDatabaseReadsTheTable(): void
    {
        $this->fetch($this->plugin([]), [self::node(7)], 2);
        $this->assertTrue($this->proceeded);

        $this->proceeded = false;
        $this->fetch($this->plugin([], served: false), [self::node(7)]);
        $this->assertTrue($this->proceeded);
    }
}
