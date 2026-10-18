<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\MenuDocument;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\Read\MenuDocuments;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Plugin\MenuFromDocuments;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Snowdog\Menu\Api\MenuRepositoryInterface;
use Snowdog\Menu\Model\Menu;
use Snowdog\Menu\Model\MenuFactory;

/**
 * The menu of an identifier as MenuRepository::get() answers it: the active menu assigned to the
 * store asked for, with the store id of the assignment, or the empty menu.
 */
class MenuFromDocumentsTest extends TestCase
{
    private bool $proceeded = false;

    private MenuDocuments&MockObject $documents;

    private static function menu(int $id, string $identifier, array $storeIds): array
    {
        return [
            MenuDocument::MENU => ['menu_id' => (string)$id, 'identifier' => $identifier, 'is_active' => '1', 'css_class' => null],
            MenuDocument::STORE_IDS => $storeIds,
            MenuDocument::NODES => [],
        ];
    }

    private function plugin(array $menus, bool $served = true): MenuFromDocuments
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn('1');
        $store->method('getCode')->willReturn('default');
        $this->documents = $this->createMock(MenuDocuments::class);
        $this->documents->method('store')->willReturn($served ? $store : null);
        $this->documents->method('menus')->with('default')->willReturn($menus);
        $factory = $this->createStub(MenuFactory::class);
        $factory->method('create')->willReturnCallback(static fn() => new class extends Menu {
            /** @phpstan-ignore-next-line the model is built by hand in the test */
            public function __construct()
            {
            }
        });

        return new MenuFromDocuments($this->documents, $factory);
    }

    private function get(MenuFromDocuments $plugin, string $identifier, int|string $storeId): Menu
    {
        return $plugin->aroundGet($this->createStub(MenuRepositoryInterface::class), function () {
            $this->proceeded = true;

            return $this->createStub(Menu::class);
        }, $identifier, $storeId);
    }

    public function testTheMenuOfTheStoreViewCarriesTheStoreIdOfItsAssignment(): void
    {
        $menu = $this->get($this->plugin([3 => self::menu(3, 'main', [1, 2])]), 'main', '1');

        $this->assertFalse($this->proceeded);
        $this->assertSame(
            ['menu_id' => '3', 'identifier' => 'main', 'is_active' => '1', 'css_class' => null, 'store_id' => '1'],
            $menu->getData()
        );
        $this->assertSame($menu->getData(), $menu->getOrigData(), 'as a loaded collection item');
    }

    public function testAMenuOfAllStoreViewsAnswersTheReadOfStoreZeroOnly(): void
    {
        // The block reads the store view first, then store 0: core filters on the assignment as asked.
        $plugin = $this->plugin([3 => self::menu(3, 'main', [0])]);

        $this->assertSame([], $this->get($plugin, 'main', 1)->getData());
        $this->assertSame('0', $this->get($plugin, 'main', 0)->getData('store_id'));
    }

    public function testTheIdentifierMatchesByteForByte(): void
    {
        $plugin = $this->plugin([3 => self::menu(3, 'main', [1])]);

        $this->assertSame([], $this->get($plugin, 'Main', 1)->getData());
        $this->assertSame([], $this->get($plugin, 'main ', 1)->getData());
    }

    public function testTheLowestIdAnswersForTwoMenusOfOneIdentifier(): void
    {
        $plugin = $this->plugin([3 => self::menu(3, 'main', [1]), 5 => self::menu(5, 'main', [1])]);

        $this->assertSame('3', $this->get($plugin, 'main', 1)->getData('menu_id'));
    }

    public function testAReadForAnotherStoreViewGoesToTheTable(): void
    {
        $plugin = $this->plugin([3 => self::menu(3, 'main', [2])]);
        $this->documents->expects($this->never())->method('menus');

        $this->get($plugin, 'main', 2);

        $this->assertTrue($this->proceeded);
    }

    public function testAStoreViewServedFromTheDatabaseReadsTheTable(): void
    {
        $plugin = $this->plugin([], served: false);
        $this->documents->expects($this->never())->method('menus');

        $this->get($plugin, 'main', 1);

        $this->assertTrue($this->proceeded);
    }
}
