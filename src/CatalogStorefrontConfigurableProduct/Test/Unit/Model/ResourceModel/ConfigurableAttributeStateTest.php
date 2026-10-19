<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProduct\Test\Unit\Model\ResourceModel;

use GraphCommerce\CatalogStorefrontConfigurableProduct\Model\ResourceModel\ConfigurableAttributeState;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Model\ResourceModel\Db\Context;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The labels and swatches a configurable's document copies from an attribute, and the products that copy them.
 */
class ConfigurableAttributeStateTest extends TestCase
{
    /** @var array<int, array{0: string, 1: array}> the select calls in order */
    private array $calls = [];

    private AdapterInterface&MockObject $connection;

    private function state(): ConfigurableAttributeState
    {
        $select = $this->createMock(Select::class);
        foreach (['from', 'join', 'where', 'distinct', 'limit'] as $method) {
            $select->method($method)->willReturnCallback(function (...$arguments) use ($select, $method) {
                // The mock passes the defaults of the optional parameters too.
                while ($arguments !== [] && end($arguments) === null) {
                    array_pop($arguments);
                }
                $this->calls[] = [$method, $arguments];

                return $select;
            });
        }
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($select);
        $metadata = $this->createStub(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('row_id');
        $pool = $this->createMock(MetadataPool::class);
        $pool->method('getMetadata')->with(ProductInterface::class)->willReturn($metadata);
        $state = $this->getMockBuilder(ConfigurableAttributeState::class)
            ->setConstructorArgs([$this->createStub(Context::class), $pool])
            ->onlyMethods(['getConnection', 'getMainTable', 'getTable'])
            ->getMock();
        $state->method('getConnection')->willReturn($this->connection);
        $state->method('getMainTable')->willReturn('catalog_product_super_attribute');
        $state->method('getTable')->willReturnArgument(0);

        return $state;
    }

    public function testTheLabelsAreTheStoreLabelsAndTheDefaultLabelAsStoreZero(): void
    {
        $state = $this->state();
        $this->connection->method('fetchPairs')->willReturn(['2' => 'Farbe', '1' => 'Kleur']);
        $this->connection->method('fetchOne')->willReturn('Color');

        $this->assertSame([0 => 'Color', 1 => 'Kleur', 2 => 'Farbe'], $state->labels(93));
    }

    public function testTheSwatchesAreKeptPerOptionAndStoreWithTheirType(): void
    {
        $state = $this->state();
        $this->connection->method('fetchAll')->willReturn([
            ['option_id' => '50', 'store_id' => '1', 'type' => '0', 'value' => 'Wit'],
            ['option_id' => '49', 'store_id' => '0', 'type' => '1', 'value' => '#000000'],
            ['option_id' => '50', 'store_id' => '0', 'type' => '0', 'value' => 'White'],
        ]);

        $this->assertSame(
            [49 => [0 => '1:#000000'], 50 => [0 => '0:White', 1 => '0:Wit']],
            $state->swatches(93)
        );
        $this->assertSame([
            ['from', [['swatch' => 'eav_attribute_option_swatch'], ['option_id', 'store_id', 'type', 'value']]],
            ['join', [['eao' => 'eav_attribute_option'], 'eao.option_id = swatch.option_id', []]],
            ['where', ['eao.attribute_id = ?', 93]],
        ], $this->calls);
    }

    public function testTheProductsWithOptionsAreLookedUpInTheValueTableByTheLinkField(): void
    {
        $this->state()->productsWithOptions(93, 'catalog_product_entity_int', [49, 50]);

        $this->assertSame([
            ['distinct', [true]],
            ['from', [['product' => 'catalog_product_entity'], ['entity_id']]],
            ['join', [['eav' => 'catalog_product_entity_int'], 'eav.row_id = product.row_id', []]],
            ['where', ['eav.attribute_id = ?', 93]],
            ['where', ['eav.value IN (?)', [49, 50]]],
        ], $this->calls);
    }

    public function testTheConfigurablesAreTheProductsWhoseSuperAttributeItIs(): void
    {
        $this->state()->configurables(93);

        $this->assertSame([
            ['distinct', [true]],
            ['from', [['product' => 'catalog_product_entity'], ['entity_id']]],
            ['join', [['cpsa' => 'catalog_product_super_attribute'], 'cpsa.product_id = product.row_id', []]],
            ['where', ['cpsa.attribute_id = ?', 93]],
        ], $this->calls);
    }
}
