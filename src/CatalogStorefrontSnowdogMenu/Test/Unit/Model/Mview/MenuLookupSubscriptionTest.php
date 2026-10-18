<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Test\Unit\Model\Mview;

use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\Mview\MenuLookupSubscription;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Ddl\Trigger;
use Magento\Framework\DB\Ddl\TriggerFactory;
use Magento\Framework\Mview\Config;
use Magento\Framework\Mview\View\ChangelogInterface;
use Magento\Framework\Mview\View\CollectionInterface;
use Magento\Framework\Mview\View\SubscriptionStatementPostprocessorInterface;
use Magento\Framework\Mview\ViewInterface;
use PHPUnit\Framework\TestCase;

/**
 * The triggers on the tables whose rows name no menu: each marks the menus it looks up.
 */
class MenuLookupSubscriptionTest extends TestCase
{
    private const CHANGELOG = 'INSERT INTO `pre_catalog_storefront_snowdog_menus_cl` (`entity_id`) ';

    private function subscription(string $table, string $column, ?SubscriptionStatementPostprocessorInterface $postprocessor = null): MenuLookupSubscription
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $name): string => '`' . $name . '`');
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn(string $name): string => 'pre_' . $name);
        $resource->method('getTriggerName')->willReturnCallback(
            static fn(string $table, string $time, string $event): string => $table . '_' . $time . '_' . $event
        );
        $triggers = $this->createStub(TriggerFactory::class);
        $triggers->method('create')->willReturnCallback(static fn(): Trigger => new Trigger());
        $views = $this->createStub(CollectionInterface::class);
        $views->method('getViewsByStateMode')->willReturn([]);
        $changelog = $this->createStub(ChangelogInterface::class);
        $changelog->method('getName')->willReturn('catalog_storefront_snowdog_menus_cl');
        $changelog->method('getColumnName')->willReturn('entity_id');
        $view = $this->createStub(ViewInterface::class);
        $view->method('getId')->willReturn('catalog_storefront_snowdog_menus');
        $view->method('getChangelog')->willReturn($changelog);
        if ($postprocessor === null) {
            $postprocessor = $this->createStub(SubscriptionStatementPostprocessorInterface::class);
            $postprocessor->method('process')->willReturnArgument(2);
        }

        return new MenuLookupSubscription(
            $resource,
            $triggers,
            $views,
            $view,
            $table,
            $column,
            $postprocessor,
            [],
            [],
            $this->createStub(Config::class)
        );
    }

    /**
     * @return array<string, string[]> the statements by event
     */
    private function statements(MenuLookupSubscription $subscription): array
    {
        $statements = [];
        foreach ($subscription->create(false)->getTriggers() as $trigger) {
            $statements[$trigger->getEvent()] = $trigger->getStatements();
        }

        return $statements;
    }

    public function testAGroupRowMarksTheMenuOfItsNode(): void
    {
        // A node that is gone adds nothing: its delete marked the menu.
        $select = self::CHANGELOG . 'SELECT node.menu_id FROM `pre_snowmenu_node` node WHERE node.node_id ';

        $this->assertSame([
            'INSERT' => [$select . '= NEW.`node_id`;'],
            'UPDATE' => [$select . 'IN (NEW.`node_id`, OLD.`node_id`);'],
            'DELETE' => [$select . '= OLD.`node_id`;'],
        ], $this->statements($this->subscription('snowmenu_customer', 'node_id')));
    }

    public function testAPageOrBlockMarksTheMenusWhoseNodesNameItsIdentifier(): void
    {
        // An update looks up both identifiers: a renamed page leaves one menu and may join another.
        foreach (['cms_page' => 'page_id', 'cms_block' => 'block_id'] as $table => $column) {
            $select = self::CHANGELOG . "SELECT node.menu_id FROM `pre_snowmenu_node` node WHERE node.type = '$table' AND node.content ";

            $this->assertSame([
                'INSERT' => [$select . '= NEW.`identifier`;'],
                'UPDATE' => [$select . 'IN (NEW.`identifier`, OLD.`identifier`);'],
                'DELETE' => [$select . '= OLD.`identifier`;'],
            ], $this->statements($this->subscription($table, $column)), $table);
        }
    }

    public function testAStoreRowMarksTheMenusWhoseNodesNameItsPageOrBlock(): void
    {
        $selects = [
            'cms_page_store' => ['page_id', "SELECT node.menu_id FROM `pre_snowmenu_node` node JOIN `pre_cms_page` page ON page.identifier = node.content WHERE node.type = 'cms_page' AND page.page_id "],
            'cms_block_store' => ['block_id', "SELECT node.menu_id FROM `pre_snowmenu_node` node JOIN `pre_cms_block` block ON block.identifier = node.content WHERE node.type = 'cms_block' AND block.block_id "],
        ];
        foreach ($selects as $table => [$column, $select]) {
            $this->assertSame([
                'INSERT' => [self::CHANGELOG . $select . "= NEW.`$column`;"],
                'UPDATE' => [self::CHANGELOG . $select . "IN (NEW.`$column`, OLD.`$column`);"],
                'DELETE' => [self::CHANGELOG . $select . "= OLD.`$column`;"],
            ], $this->statements($this->subscription($table, $column)), $table);
        }
    }

    public function testATableWithoutALookupIsAConfigurationError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The table snowmenu_menu has no menu lookup');
        $this->statements($this->subscription('snowmenu_menu', 'menu_id'));
    }

    public function testTheStatementPassesThePostprocessor(): void
    {
        $postprocessor = $this->createMock(SubscriptionStatementPostprocessorInterface::class);
        $postprocessor->expects($this->exactly(3))
            ->method('process')
            ->with('cms_page', $this->anything(), $this->stringStartsWith('INSERT INTO'))
            ->willReturn('SET @processed = 1');

        foreach ($this->subscription('cms_page', 'page_id', $postprocessor)->create(false)->getTriggers() as $trigger) {
            $this->assertSame(['SET @processed = 1;'], $trigger->getStatements());
        }
    }
}
