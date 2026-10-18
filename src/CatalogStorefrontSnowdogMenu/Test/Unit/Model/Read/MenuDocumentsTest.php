<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Test\Unit\Model\Read;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\MenuDocument;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\Read\MenuDocuments;
use Magento\Framework\DataObject;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The menu documents of a store view as a rendered page reads them: once per request, in id order,
 * and only with the marker that says they are written.
 */
class MenuDocumentsTest extends TestCase
{
    private MetadataDocumentStorageInterface&MockObject $storage;

    private StoreInterface $store;

    private static function menu(string $identifier, array $cmsPages = []): array
    {
        return [
            MenuDocument::MENU => ['identifier' => $identifier],
            MenuDocument::STORE_IDS => [0],
            MenuDocument::NODES => [],
            MenuDocument::CMS_PAGES => $cmsPages,
            MenuDocument::CMS_BLOCKS => [],
        ];
    }

    private static function node(int $id, int $menuId): DataObject
    {
        return new DataObject(['id' => (string)$id, 'menu_id' => (string)$menuId]);
    }

    private function documents(
        array|\Throwable $stored,
        bool $listing = true,
        bool $detail = false,
        ?LoggerInterface $logger = null
    ): MenuDocuments {
        $this->storage = $this->createMock(MetadataDocumentStorageInterface::class);
        if ($stored instanceof \Throwable) {
            $this->storage->method('all')->willThrowException($stored);
        } else {
            $this->storage->method('all')->with('snowdog_menu', 'default')->willReturn($stored);
        }
        $mode = $this->createMock(Mode::class);
        $mode->method('listing')->with(1)->willReturn($listing);
        $mode->method('detail')->with(1)->willReturn($detail);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn('1');
        $store->method('getCode')->willReturn('default');
        $this->store = $store;
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $strict = new Strict($this->createStub(StorefrontKey::class), $logger ?? $this->createStub(LoggerInterface::class));

        return new MenuDocuments($this->storage, $mode, $stores, $strict);
    }

    public function testTheMenusComeInIdOrderWithoutTheMarker(): void
    {
        $documents = $this->documents([9 => self::menu('footer'), 0 => MenuDocument::MARKER_DOCUMENT, 3 => self::menu('main')]);

        $this->assertSame([3 => self::menu('main'), 9 => self::menu('footer')], $documents->menus('default'));
    }

    public function testTheMenusAreReadOncePerRequest(): void
    {
        // Every menu block of a page reads its menu, its nodes and its category nodes.
        $documents = $this->documents([0 => MenuDocument::MARKER_DOCUMENT, 3 => self::menu('main')]);
        $this->storage->expects($this->exactly(2))->method('all');

        $documents->menus('default');
        $documents->menus('default');
        $documents->_resetState();
        $documents->menus('default');
    }

    public function testAStoreViewWithoutTheMarkerStopsTheReadAndIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains(
            'Store view "default" has no menu documents: run bin/magento catalog-storefront:rebuild snowdog_menu'
        ));
        $documents = $this->documents([3 => self::menu('main')], logger: $logger);

        $this->expectException(DocumentReadException::class);
        $documents->menus('default');
    }

    public function testADocumentOfAnotherShapeStopsTheRead(): void
    {
        $documents = $this->documents([0 => MenuDocument::MARKER_DOCUMENT, 3 => [MenuDocument::MENU => ['identifier' => 'main']]]);

        $this->expectException(DocumentReadException::class);
        $this->expectExceptionMessage('The document of menu 3 in store view "default" has no menu, storeIds, nodes, cmsPages, cmsBlocks');
        $documents->menus('default');
    }

    public function testTheCmsRowsOfTheNodesAreThoseOfTheStoreViewAndAllStoreViewsInStoreOrder(): void
    {
        // Core reads store 0 and the store view in store order, so the store view's own page comes last.
        $documents = $this->documents([0 => MenuDocument::MARKER_DOCUMENT, 3 => self::menu('main', [
            ['nodeId' => 7, 'pageId' => '9', 'identifier' => 'about-us', 'storeId' => 1],
            ['nodeId' => 7, 'pageId' => '11', 'identifier' => 'about-us', 'storeId' => 2],
            ['nodeId' => 7, 'pageId' => '5', 'identifier' => 'about-us', 'storeId' => 0],
            ['nodeId' => 8, 'pageId' => '6', 'identifier' => 'faq', 'storeId' => 0],
        ])]);

        $rows = $documents->cmsRows($this->store, [self::node(7, 3)], MenuDocument::CMS_PAGES);

        $this->assertSame(['5', '9'], array_column($rows, 'pageId'), 'another store view and another node are left out');
    }

    public function testANodeOfAMenuWithoutADocumentStopsTheRead(): void
    {
        $documents = $this->documents([0 => MenuDocument::MARKER_DOCUMENT, 3 => self::menu('main')]);

        $this->expectException(DocumentReadException::class);
        $this->expectExceptionMessage('Menu 4 of node 7 has no document in store view "default"');
        $documents->cmsRows($this->store, [self::node(7, 4)], MenuDocument::CMS_PAGES);
    }

    public function testAFailedReadStopsTheRead(): void
    {
        $documents = $this->documents(new \RuntimeException('No alive nodes'));

        $this->expectException(DocumentReadException::class);
        $this->expectExceptionMessage('No alive nodes');
        $documents->menus('default');
    }

    public function testAStoreViewServedFromDocumentsReadsItsMenusThere(): void
    {
        $this->assertSame('1', $this->documents([], listing: true)->store()?->getId());
        $this->assertSame('1', $this->documents([], listing: false, detail: true)->store()?->getId());
        $this->assertNull($this->documents([], listing: false)->store(), 'the tables, as core reads them');
    }
}
