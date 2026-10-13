<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSwatchesGraphQl\Test\Unit\Plugin\Layer;

use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\Read\AttributeDocuments;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Read\FacetDocuments;
use GraphCommerce\CatalogStorefrontSwatchesGraphQl\Plugin\Layer\AggregationSwatchesFromDocuments;
use GraphCommerce\GraphQlCatalog\Model\OptionSwatch;
use Magento\CatalogGraphQl\DataProvider\Product\LayeredNavigation\LayerBuilder;
use Magento\Framework\Api\Search\AggregationInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class AggregationSwatchesFromDocumentsTest extends TestCase
{
    private const LAYERS = [
        'color_bucket' => ['attribute_code' => 'color', 'options' => [['label' => 'Red', 'value' => '5', 'count' => 2], ['label' => 'Plain', 'value' => '6', 'count' => 1]]],
        'category_bucket' => ['attribute_code' => 'category_uid', 'options' => [['label' => 'Wands', 'value' => '5', 'count' => 3]]],
        'new_bucket' => ['attribute_code' => 'new', 'options' => [['label' => 'Yes', 'value' => '1', 'count' => 3]]],
    ];

    private function plugin(bool $documents, FacetDocuments $facets, AttributeDocuments $attributeDocuments): AggregationSwatchesFromDocuments
    {
        $mode = $this->createStub(Mode::class);
        $mode->method('documents')->willReturn($documents);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);

        return new AggregationSwatchesFromDocuments($mode, $facets, $attributeDocuments, $stores, $this->createStub(Strict::class));
    }

    public function testTheSelectAttributesTakeTheirSwatchesFromThePrimedDocuments(): void
    {
        $facets = $this->createStub(FacetDocuments::class);
        $facets->method('attributes')->willReturn([
            'color' => ['frontendInput' => 'select', 'options' => [['id' => '5', 'swatch' => ['type' => 1, 'value' => '#ff0000']], ['id' => '6']]],
        ]);
        $attributeDocuments = $this->createMock(AttributeDocuments::class);
        $attributeDocuments->expects(self::once())->method('byCodes')->with('default', ['category_uid', 'new'])
            ->willReturn(['new' => ['frontendInput' => 'boolean', 'options' => [['id' => '1', 'swatch' => ['type' => 1, 'value' => '#000000']]]]]);

        $layers = $this->plugin(true, $facets, $attributeDocuments)
            ->afterBuild($this->createStub(LayerBuilder::class), self::LAYERS, $this->createStub(AggregationInterface::class), 1);

        self::assertSame(['type' => 1, 'value' => '#ff0000'], $layers['color_bucket']['options'][0][OptionSwatch::KEY]);
        self::assertArrayHasKey(OptionSwatch::KEY, $layers['color_bucket']['options'][1]);
        self::assertNull($layers['color_bucket']['options'][1][OptionSwatch::KEY]);
        self::assertNull($layers['category_bucket']['options'][0][OptionSwatch::KEY], 'a category id is no option id');
        self::assertNull($layers['new_bucket']['options'][0][OptionSwatch::KEY], 'a boolean value is no option id');
    }

    public function testTheCorePathKeepsTheLayersAsTheyAre(): void
    {
        $attributeDocuments = $this->createMock(AttributeDocuments::class);
        $attributeDocuments->expects(self::never())->method('byCodes');

        self::assertSame(self::LAYERS, $this->plugin(false, $this->createStub(FacetDocuments::class), $attributeDocuments)
            ->afterBuild($this->createStub(LayerBuilder::class), self::LAYERS, $this->createStub(AggregationInterface::class), 1));
    }
}
