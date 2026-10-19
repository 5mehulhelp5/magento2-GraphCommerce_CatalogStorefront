<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProduct\Test\Unit\Plugin\Eav;

use GraphCommerce\CatalogStorefrontConfigurableProduct\Model\ResourceModel\ConfigurableAttributeState;
use GraphCommerce\CatalogStorefrontConfigurableProduct\Plugin\Eav\ResyncConfigurablesOnAttributeChange;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Type;
use Magento\Eav\Model\ResourceModel\Entity\Attribute as AttributeResource;
use Magento\Framework\DB\Select;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Mview\View\ChangelogInterface;
use Magento\Framework\Mview\ViewInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * An attribute save schedules the configurables whose documents copy its labels or its swatches.
 */
class ResyncConfigurablesOnAttributeChangeTest extends TestCase
{
    private const COLOR = 93;

    private ConfigurableAttributeState&MockObject $state;

    private IndexerInterface&MockObject $indexer;

    private CommerceDataExportLoggerInterface&MockObject $logger;

    private Select $configurables;

    private Select $products;

    /** @var array<int, array> the snapshots the state answers, before the save and after it */
    private array $snapshots = [];

    private bool $saved = false;

    /**
     * @param array $before the labels and swatches before the save
     * @param array $after the labels and swatches after the save
     */
    private function plugin(array $before, array $after, bool $used = true, bool $scheduled = true, int $count = 3): ResyncConfigurablesOnAttributeChange
    {
        $this->snapshots = [$before, $after];
        $this->state = $this->createMock(ConfigurableAttributeState::class);
        $this->state->method('isUsed')->with(self::COLOR)->willReturn($used);
        $this->state->method('labels')->willReturnCallback(fn(): array => $this->snapshot()['labels']);
        $this->state->method('swatches')->willReturnCallback(fn(): array => $this->snapshot()['swatches']);
        $this->configurables = $this->createStub(Select::class);
        $this->products = $this->createStub(Select::class);
        $this->state->method('configurables')->with(self::COLOR)->willReturn($this->configurables);
        $this->state->method('productsWithOptions')->willReturn($this->products);
        $this->state->method('count')->willReturn($count);

        $type = $this->createStub(Type::class);
        $type->method('getId')->willReturn('4');
        $eavConfig = $this->createStub(EavConfig::class);
        $eavConfig->method('getEntityType')->willReturn($type);

        $changelog = $this->createStub(ChangelogInterface::class);
        $changelog->method('getName')->willReturn('cde_products_feed_cl');
        $changelog->method('getColumnName')->willReturn('entity_id');
        $view = $this->createStub(ViewInterface::class);
        $view->method('isEnabled')->willReturn($scheduled);
        $view->method('getChangelog')->willReturn($changelog);
        $this->indexer = $this->createMock(IndexerInterface::class);
        $this->indexer->method('getView')->willReturn($view);
        $registry = $this->createStub(IndexerRegistry::class);
        $registry->method('get')->willReturn($this->indexer);
        $this->logger = $this->createMock(CommerceDataExportLoggerInterface::class);

        return new ResyncConfigurablesOnAttributeChange($this->state, $eavConfig, $registry, $this->logger, 10);
    }

    /**
     * The snapshot before the save until it runs, the one after it from then on.
     */
    private function snapshot(): array
    {
        return $this->snapshots[$this->saved ? 1 : 0];
    }

    private function attribute(bool $new = false, int $entityTypeId = 4): Attribute
    {
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getId')->willReturn((string)self::COLOR);
        $attribute->method('isObjectNew')->willReturn($new);
        $attribute->method('getEntityTypeId')->willReturn((string)$entityTypeId);
        $attribute->method('getBackendTable')->willReturn('catalog_product_entity_int');

        return $attribute;
    }

    private function save(ResyncConfigurablesOnAttributeChange $plugin, ?Attribute $attribute = null): mixed
    {
        $subject = $this->createStub(AttributeResource::class);

        return $plugin->aroundSave($subject, function () use ($subject) {
            $this->saved = true;

            return $subject;
        }, $attribute ?? $this->attribute());
    }

    private static function state(array $labels = [0 => 'Color'], array $swatches = [49 => [0 => '1:#000000']]): array
    {
        return ['labels' => $labels, 'swatches' => $swatches];
    }

    public function testAChangedLabelSchedulesEveryConfigurableThatUsesTheAttribute(): void
    {
        // The document's option label is the store label, else the default label.
        $plugin = $this->plugin(self::state(), self::state([0 => 'Color', 1 => 'Kleur']));
        $this->state->expects($this->once())->method('queue')->with($this->configurables, 'cde_products_feed_cl', 'entity_id');
        $this->indexer->expects($this->never())->method('invalidate');

        $this->save($plugin);
        $this->assertTrue($this->saved);
    }

    public function testAChangedSwatchSchedulesTheProductsThatStoreTheOption(): void
    {
        // The products feed adds their configurables to the sync itself.
        $plugin = $this->plugin(self::state(), self::state(swatches: [49 => [0 => '1:#0000ff']]));
        $this->state->expects($this->once())->method('productsWithOptions')
            ->with(self::COLOR, 'catalog_product_entity_int', [49])->willReturn($this->products);
        $this->state->expects($this->once())->method('queue')->with($this->products, 'cde_products_feed_cl', 'entity_id');

        $this->save($plugin);
    }

    public function testAnAddedAndARemovedSwatchScheduleTheirOptions(): void
    {
        $plugin = $this->plugin(
            self::state(swatches: [49 => [0 => '1:#000000'], 50 => [0 => '1:#ffffff']]),
            self::state(swatches: [49 => [0 => '1:#000000'], 51 => [1 => '0:Groen']])
        );
        $this->state->expects($this->once())->method('productsWithOptions')
            ->with(self::COLOR, 'catalog_product_entity_int', [50, 51])->willReturn($this->products);

        $this->save($plugin);
    }

    public function testASaveThatChangesNeitherSchedulesNothing(): void
    {
        // Core deletes and inserts every option label on each save; this compares what it copies.
        $plugin = $this->plugin(self::state(), self::state());
        $this->state->expects($this->never())->method('count');
        $this->state->expects($this->never())->method('queue');
        $this->indexer->expects($this->never())->method('invalidate');

        $this->save($plugin);
        $this->assertTrue($this->saved);
    }

    public function testAttributesNoConfigurableCopiesAreNotCompared(): void
    {
        foreach ([
            'a new attribute' => [$this->attribute(true), true],
            'a category attribute' => [$this->attribute(false, 3), true],
            'an attribute no configurable uses' => [$this->attribute(), false],
        ] as $case => [$attribute, $used]) {
            $this->saved = false;
            $plugin = $this->plugin(self::state(), self::state([0 => 'Changed']), $used);
            $this->state->expects($this->never())->method('labels');
            $this->state->expects($this->never())->method('queue');

            $this->save($plugin, $attribute);
            $this->assertTrue($this->saved, $case);
        }
    }

    public function testMoreProductsThanTheThresholdInvalidateTheFeed(): void
    {
        $plugin = $this->plugin(self::state(), self::state([0 => 'Kleur']), count: 11);
        $this->state->expects($this->never())->method('queue');
        $this->indexer->expects($this->once())->method('invalidate');

        $this->save($plugin);
    }

    public function testAFeedNotOnScheduleIsInvalidated(): void
    {
        $plugin = $this->plugin(self::state(), self::state([0 => 'Kleur']), scheduled: false);
        $this->state->expects($this->never())->method('queue');
        $this->indexer->expects($this->once())->method('invalidate');

        $this->save($plugin);
    }

    public function testAFailureIsLoggedAndInvalidatesTheFeedAfterTheSave(): void
    {
        // The save has committed; a missed resync would leave the documents stale without a sign.
        $plugin = $this->plugin(self::state(), self::state([0 => 'Kleur']));
        $this->state->method('queue')->willThrowException(new \RuntimeException('lock wait timeout'));
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('attribute 93 failed'));
        $this->indexer->expects($this->once())->method('invalidate');

        $result = $this->save($plugin);
        $this->assertTrue($this->saved);
        $this->assertInstanceOf(AttributeResource::class, $result);
    }
}
