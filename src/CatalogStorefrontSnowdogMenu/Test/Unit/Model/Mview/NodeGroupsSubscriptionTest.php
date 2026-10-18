<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Test\Unit\Model\Mview;

use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\Mview\NodeGroupsSubscription;
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
 * The triggers on snowmenu_customer: a group row added, changed or removed marks the menu of its node.
 */
class NodeGroupsSubscriptionTest extends TestCase
{
    private function subscription(?SubscriptionStatementPostprocessorInterface $postprocessor = null): NodeGroupsSubscription
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

        return new NodeGroupsSubscription(
            $resource,
            $triggers,
            $views,
            $view,
            'snowmenu_customer',
            'node_id',
            $postprocessor,
            [],
            [],
            $this->createStub(Config::class)
        );
    }

    public function testAGroupRowMarksTheMenuOfItsNode(): void
    {
        $statements = [];
        foreach ($this->subscription()->create(false)->getTriggers() as $trigger) {
            $statements[$trigger->getEvent()] = $trigger->getStatements();
        }

        // The removed row's node is the old one; a node that is gone adds nothing, its delete marked the menu.
        $select = 'INSERT INTO `pre_catalog_storefront_snowdog_menus_cl` (`entity_id`) SELECT `menu_id` FROM `pre_snowmenu_node` WHERE `node_id` = %s.`node_id`;';
        $this->assertSame([
            'INSERT' => [sprintf($select, 'NEW')],
            'UPDATE' => [sprintf($select, 'NEW')],
            'DELETE' => [sprintf($select, 'OLD')],
        ], $statements);
    }

    public function testTheStatementPassesThePostprocessor(): void
    {
        $postprocessor = $this->createMock(SubscriptionStatementPostprocessorInterface::class);
        $postprocessor->expects($this->exactly(3))
            ->method('process')
            ->with('snowmenu_customer', $this->anything(), $this->stringStartsWith('INSERT INTO'))
            ->willReturn('SET @processed = 1');

        foreach ($this->subscription($postprocessor)->create(false)->getTriggers() as $trigger) {
            $this->assertSame(['SET @processed = 1;'], $trigger->getStatements());
        }
    }
}
