<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Test\Unit\Model\Document\Writer;

use GraphCommerce\CatalogStorefront\Model\Document\Scopes;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\Document\Writer\Menus;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\MenuDocument;
use PHPUnit\Framework\TestCase;

/**
 * The document contract of the menu feed: a menu document in every store view the active menu is
 * assigned to, directly or through store 0, with the rows core's collections load; no document
 * anywhere else.
 */
class MenusTest extends TestCase
{
    private const MARKER = [0 => MenuDocument::MARKER_DOCUMENT];

    public function testAMenuOfAllStoreViewsLandsInEveryStoreViewWithItsRows(): void
    {
        [$storage, $upserts, $deletes] = $this->storage();

        $this->writer($storage)->write([[
            'menuId' => '3',
            'title' => 'Main',
            'identifier' => 'main',
            'creationTime' => '2026-10-01 10:00:00',
            'updateTime' => '2026-10-02 10:00:00',
            'isActive' => '1',
            'stores' => [['menuId' => '3', 'storeId' => '0', 'storeViewCode' => 'admin']],
            'nodes' => [
                ['nodeId' => '7', 'menuId' => '3', 'type' => 'category', 'content' => '20', 'level' => '0', 'position' => '0', 'title' => 'Women', 'isActive' => '1', 'hideIfEmpty' => '0', 'imageHeight' => '40'],
                [
                    'nodeId' => '8',
                    'menuId' => '3',
                    'type' => 'custom_url',
                    'parentId' => '7',
                    'level' => '1',
                    'position' => '0',
                    'title' => 'Sale',
                    'isActive' => '1',
                    'hideIfEmpty' => '0',
                    'customerGroups' => [['nodeId' => '8', 'groupId' => '0'], ['nodeId' => '8', 'groupId' => '2']],
                ],
            ],
        ]]);

        $document = $upserts->calls[0][2][3];
        $this->assertSame(['snowdog_menu', 'default'], array_slice($upserts->calls[0], 0, 2));
        $this->assertSame(['snowdog_menu', 'second'], array_slice($upserts->calls[1], 0, 2));
        $this->assertSame($document, $upserts->calls[1][2][3]);
        $this->assertSame([], $deletes->calls);
        $this->assertSame([
            'menu_id' => '3',
            'title' => 'Main',
            'identifier' => 'main',
            'creation_time' => '2026-10-01 10:00:00',
            'update_time' => '2026-10-02 10:00:00',
            'is_active' => '1',
            'css_class' => null,
        ], $document['menu'], 'a column the row leaves out is null');
        $this->assertSame([0], $document['storeIds']);
        $this->assertSame(['7', '8'], array_column($document['nodes'], 'node_id'), 'the nodes in feed order');
        $this->assertSame('40', $document['nodes'][0]['image_heigth'], 'the column keeps its name');
        $this->assertNull($document['nodes'][0]['customer_groups'], 'a node without groups');
        $this->assertSame('0,2', $document['nodes'][1]['customer_groups'], 'as GROUP_CONCAT joins them');
        $this->assertSame(array_values(MenuDocument::NODE_COLUMNS), array_slice(array_keys($document['nodes'][1]), 0, -1));
    }

    public function testTheNotLoggedInGroupAloneLimitsANode(): void
    {
        // Group 0 is a group like any other: the node stays limited to it.
        [$storage, $upserts] = $this->storage();

        $this->writer($storage)->write([$this->row(3, '1', ['default'], [['groupId' => '0']])]);

        $this->assertSame('0', $upserts->calls[0][2][3]['nodes'][0]['customer_groups']);
    }

    public function testAMenuOfOneStoreViewLeavesTheOthers(): void
    {
        [$storage, $upserts, $deletes] = $this->storage();

        $this->writer($storage)->write([$this->row(3, '1', ['default'])]);

        $this->assertSame([0, 3], array_keys($upserts->calls[0][2]));
        $this->assertSame([1], $upserts->calls[0][2][3]['storeIds']);
        $this->assertSame(self::MARKER, $upserts->calls[1][2]);
        $this->assertSame([['snowdog_menu', 'second', [3]]], $deletes->calls);
    }

    public function testAnInactiveOrDeletedMenuLosesItsDocuments(): void
    {
        [$storage, $upserts, $deletes] = $this->storage();

        $this->writer($storage)->write([
            $this->row(3, '0', ['default', 'second']),
            ['menuId' => '4', 'deleted' => true],
        ]);

        $this->assertSame(self::MARKER, $upserts->calls[0][2]);
        $this->assertSame(self::MARKER, $upserts->calls[1][2]);
        $this->assertSame([['snowdog_menu', 'default', [3, 4]], ['snowdog_menu', 'second', [3, 4]]], $deletes->calls);
    }

    public function testEveryBatchWritesTheMarkerOfEveryStoreView(): void
    {
        [$storage, $upserts] = $this->storage();

        $this->writer($storage)->write([]);

        $this->assertSame([['snowdog_menu', 'default', self::MARKER], ['snowdog_menu', 'second', self::MARKER]], $upserts->calls);
    }

    public function testTheSeedWritesTheMarkerOfAStoreView(): void
    {
        // A store without menus exports no row, so only the rebuild's seed writes its marker.
        [$storage, $upserts] = $this->storage();

        $this->writer($storage)->seed('default');

        $this->assertSame([['snowdog_menu', 'default', self::MARKER]], $upserts->calls);
    }

    public function testARowWithoutAMenuIdStopsTheBatch(): void
    {
        [$storage, $upserts] = $this->storage();

        try {
            $this->writer($storage)->write([['title' => 'Main']]);
            $this->fail('A row without a menu id is written');
        } catch (\RuntimeException $e) {
            $this->assertSame('A menu feed row has no menuId', $e->getMessage());
        }
        $this->assertSame([], $upserts->calls);
    }

    /**
     * @param string[] $storeViews the store views the menu is assigned to
     * @param array[] $groups the customer groups of its node
     */
    private function row(int $menuId, string $active, array $storeViews, array $groups = []): array
    {
        $ids = ['default' => '1', 'second' => '2'];

        return [
            'menuId' => (string)$menuId,
            'identifier' => 'main',
            'isActive' => $active,
            'stores' => array_map(
                static fn(string $code): array => ['menuId' => (string)$menuId, 'storeId' => $ids[$code], 'storeViewCode' => $code],
                $storeViews
            ),
            'nodes' => [['nodeId' => '7', 'menuId' => (string)$menuId, 'customerGroups' => $groups]],
        ];
    }

    private function writer(MetadataDocumentStorageInterface $storage): Menus
    {
        $scopes = $this->createMock(Scopes::class);
        $scopes->method('storeViews')->willReturn(['default', 'second']);

        return new Menus($storage, $scopes);
    }

    private function storage(): array
    {
        $upserts = (object)['calls' => []];
        $deletes = (object)['calls' => []];
        $storage = $this->createMock(MetadataDocumentStorageInterface::class);
        $storage->method('upsert')->willReturnCallback(
            static function (string $entity, string $store, array $documents) use ($upserts): void {
                $upserts->calls[] = [$entity, $store, $documents];
            }
        );
        $storage->method('delete')->willReturnCallback(
            static function (string $entity, string $store, array $ids) use ($deletes): void {
                $deletes->calls[] = [$entity, $store, $ids];
            }
        );

        return [$storage, $upserts, $deletes];
    }
}
