<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\Mview;

use GraphCommerce\CatalogStorefront\Model\Mview\AttributeOptionSubscription;
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
 * The triggers on the rows that name an option: each marks the option's attribute.
 */
class AttributeOptionSubscriptionTest extends TestCase
{
    private const SELECT = 'INSERT INTO `pre_cde_product_attributes_feed_cl` (`entity_id`) '
        . 'SELECT eao.attribute_id FROM `pre_eav_attribute_option` eao WHERE eao.option_id ';

    private function subscription(string $table, ?SubscriptionStatementPostprocessorInterface $postprocessor = null): AttributeOptionSubscription
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
        $changelog->method('getName')->willReturn('cde_product_attributes_feed_cl');
        $changelog->method('getColumnName')->willReturn('entity_id');
        $view = $this->createStub(ViewInterface::class);
        $view->method('getId')->willReturn('cde_product_attributes_feed');
        $view->method('getChangelog')->willReturn($changelog);
        if ($postprocessor === null) {
            $postprocessor = $this->createStub(SubscriptionStatementPostprocessorInterface::class);
            $postprocessor->method('process')->willReturnArgument(2);
        }

        return new AttributeOptionSubscription(
            $resource,
            $triggers,
            $views,
            $view,
            $table,
            'option_id',
            $postprocessor,
            [],
            [],
            $this->createStub(Config::class)
        );
    }

    /**
     * @return array<string, string[]> the statements by event
     */
    private function statements(AttributeOptionSubscription $subscription): array
    {
        $statements = [];
        foreach ($subscription->create(false)->getTriggers() as $trigger) {
            $statements[$trigger->getEvent()] = $trigger->getStatements();
        }

        return $statements;
    }

    public function testALabelOrASwatchMarksTheAttributeOfItsOption(): void
    {
        // An update looks up both options; an option that is gone adds nothing.
        foreach (['eav_attribute_option_value', 'eav_attribute_option_swatch'] as $table) {
            $this->assertSame([
                'INSERT' => [self::SELECT . '= NEW.`option_id`;'],
                'UPDATE' => [self::SELECT . 'IN (NEW.`option_id`, OLD.`option_id`);'],
                'DELETE' => [self::SELECT . '= OLD.`option_id`;'],
            ], $this->statements($this->subscription($table)), $table);
        }
    }

    public function testTheStatementPassesThePostprocessor(): void
    {
        $postprocessor = $this->createMock(SubscriptionStatementPostprocessorInterface::class);
        $postprocessor->expects($this->exactly(3))
            ->method('process')
            ->with('eav_attribute_option_value', $this->anything(), $this->stringStartsWith('INSERT INTO'))
            ->willReturn('SET @processed = 1');

        foreach ($this->subscription('eav_attribute_option_value', $postprocessor)->create(false)->getTriggers() as $trigger) {
            $this->assertSame(['SET @processed = 1;'], $trigger->getStatements());
        }
    }
}
