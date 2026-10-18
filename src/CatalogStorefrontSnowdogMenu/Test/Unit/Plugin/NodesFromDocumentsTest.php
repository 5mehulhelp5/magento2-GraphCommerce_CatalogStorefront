<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\MenuDocument;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\Read\MenuDocuments;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Plugin\NodesFromDocuments;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Snowdog\Menu\Api\NodeRepositoryInterface;
use Snowdog\Menu\Block\Menu as MenuBlock;
use Snowdog\Menu\Model\Menu\Node;
use Snowdog\Menu\Model\Menu\NodeFactory;

/**
 * The nodes of a menu as NodeRepository::getByMenu() answers them: the rows by node id in the
 * repository's order, with the customer groups only where the menu limits nodes to them.
 */
class NodesFromDocumentsTest extends TestCase
{
    private bool $proceeded = false;

    private MenuDocuments&MockObject $documents;

    private const NODES = [
        ['node_id' => '7', 'menu_id' => '3', 'level' => '0', 'title' => 'Women', 'customer_groups' => null],
        ['node_id' => '8', 'menu_id' => '3', 'level' => '1', 'title' => 'Sale', 'customer_groups' => '0,2'],
    ];

    private function plugin(bool $groups = false, bool $served = true): NodesFromDocuments
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $this->documents = $this->createMock(MenuDocuments::class);
        $this->documents->method('store')->willReturn($served ? $store : null);
        $this->documents->method('menus')->with('default')->willReturn([
            3 => [MenuDocument::MENU => ['identifier' => 'main'], MenuDocument::STORE_IDS => [0], MenuDocument::NODES => self::NODES],
        ]);
        $factory = $this->createStub(NodeFactory::class);
        $factory->method('create')->willReturnCallback(static fn() => new class extends Node {
            /** @phpstan-ignore-next-line the model is built by hand in the test */
            public function __construct()
            {
            }
        });
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('isSetFlag')->with(MenuBlock::XML_SNOWMENU_GENERAL_CUSTOMER_GROUPS)->willReturn($groups);

        return new NodesFromDocuments(
            $this->documents,
            $factory,
            $config,
            new Strict($this->createStub(StorefrontKey::class), $this->createStub(LoggerInterface::class))
        );
    }

    /**
     * @return Node[]
     */
    private function getByMenu(NodesFromDocuments $plugin, mixed $menuId): array
    {
        return $plugin->aroundGetByMenu($this->createStub(NodeRepositoryInterface::class), function () {
            $this->proceeded = true;

            return [];
        }, $menuId);
    }

    public function testTheNodesAreTheRowsByNodeIdInDocumentOrder(): void
    {
        $nodes = $this->getByMenu($this->plugin(), '3');

        $this->assertFalse($this->proceeded);
        $this->assertSame([7, 8], array_keys($nodes));
        $this->assertSame(['node_id' => '8', 'menu_id' => '3', 'level' => '1', 'title' => 'Sale'], $nodes[8]->getData());
        $this->assertSame($nodes[8]->getData(), $nodes[8]->getOrigData(), 'as a loaded collection item');
    }

    public function testTheGroupsComeWithTheNodesWhereTheMenuLimitsNodesToThem(): void
    {
        $nodes = $this->getByMenu($this->plugin(groups: true), 3);

        $this->assertNull($nodes[7]->getData('customer_groups'));
        $this->assertTrue($nodes[7]->hasData('customer_groups'), 'core joins the column for every node');
        $this->assertSame(['0', '2'], $nodes[8]->getCustomerGroups());
    }

    public function testNoMenuHasNoNodes(): void
    {
        // A menu block of a store view without its menu asks with the id of the empty menu.
        $plugin = $this->plugin();
        $this->documents->expects($this->never())->method('menus');

        $this->assertSame([], $this->getByMenu($plugin, null));
        $this->assertFalse($this->proceeded);
    }

    public function testAMenuWithoutADocumentStopsTheRead(): void
    {
        $plugin = $this->plugin();

        $this->expectException(DocumentReadException::class);
        $this->expectExceptionMessage('Menu 4 has no document in store view "default"');
        $this->getByMenu($plugin, 4);
    }

    public function testAStoreViewServedFromTheDatabaseReadsTheTable(): void
    {
        $this->getByMenu($this->plugin(served: false), 3);

        $this->assertTrue($this->proceeded);
    }
}
