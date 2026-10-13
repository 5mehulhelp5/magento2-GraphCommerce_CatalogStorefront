<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSwatchesGraphQl\Test\Unit\Plugin\Layer;

use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\Read\AttributeDocuments;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Read\FacetDocuments;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use GraphCommerce\CatalogStorefrontSwatchesGraphQl\Model\Swatches;
use GraphCommerce\CatalogStorefrontSwatchesGraphQl\Plugin\Layer\AggregationSwatches;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\CatalogGraphQl\DataProvider\Product\LayeredNavigation\LayerBuilder;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Api\Search\AggregationInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class AggregationSwatchesTest extends TestCase
{
    private const LAYERS = [
        'color_bucket' => ['attribute_code' => 'color', 'options' => [['label' => 'Red', 'value' => '5', 'count' => 2], ['label' => 'Plain', 'value' => '6', 'count' => 1]]],
        'category_bucket' => ['attribute_code' => 'category_uid', 'options' => [['label' => 'Wands', 'value' => '5', 'count' => 3]]],
        'new_bucket' => ['attribute_code' => 'new', 'options' => [['label' => 'Yes', 'value' => '1', 'count' => 3]]],
    ];

    private function swatchOf(array $layers, string $bucket, int $index): mixed
    {
        return $layers[$bucket]['options'][$index][PrefillerInterface::KEY]['swatch'];
    }

    private function plugin(bool $documents, FacetDocuments $facets, AttributeDocuments $attributeDocuments, EavConfig $eavConfig, Swatches $swatches): AggregationSwatches
    {
        $mode = $this->createStub(Mode::class);
        $mode->method('documents')->willReturn($documents);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);

        return new AggregationSwatches($mode, $facets, $attributeDocuments, $stores, $eavConfig, $swatches, $this->createStub(Strict::class));
    }

    public function testTheDocumentPathTakesTheSwatchesOfTheSelectAttributesFromThePrimedDocuments(): void
    {
        $facets = $this->createStub(FacetDocuments::class);
        $facets->method('attributes')->willReturn([
            'color' => ['frontendInput' => 'select', 'options' => [['id' => '5', 'swatch' => ['type' => 1, 'value' => '#ff0000']], ['id' => '6']]],
        ]);
        $attributeDocuments = $this->createMock(AttributeDocuments::class);
        $attributeDocuments->expects(self::once())->method('byCodes')->with('default', ['category_uid', 'new'])
            ->willReturn(['new' => ['frontendInput' => 'boolean', 'options' => [['id' => '1', 'swatch' => ['type' => 1, 'value' => '#000000']]]]]);
        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->expects(self::never())->method('getAttribute');

        $layers = $this->plugin(true, $facets, $attributeDocuments, $eavConfig, $this->createStub(Swatches::class))
            ->afterBuild($this->createStub(LayerBuilder::class), self::LAYERS, $this->createStub(AggregationInterface::class), 1);

        self::assertSame(['type' => 1, 'value' => '#ff0000'], $this->swatchOf($layers, 'color_bucket', 0));
        self::assertNull($this->swatchOf($layers, 'color_bucket', 1));
        self::assertNull($this->swatchOf($layers, 'category_bucket', 0), 'a category id is no option id');
        self::assertNull($this->swatchOf($layers, 'new_bucket', 0), 'a boolean value is no option id');
    }

    public function testTheCorePathAsksCoresSwatchHelperForTheSelectAttributes(): void
    {
        $eavConfig = $this->createStub(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturnCallback(function (string $entity, string $code) {
            $attribute = $this->createStub(Attribute::class);
            $attribute->method('getAttributeId')->willReturn($code === 'category_uid' ? null : 1);
            $attribute->method('getFrontendInput')->willReturn($code === 'color' ? 'select' : 'boolean');
            return $attribute;
        });
        $swatches = $this->createMock(Swatches::class);
        $swatches->expects(self::once())->method('byOptionIds')->with(self::anything(), ['5', '6'])
            ->willReturn(['5' => ['value' => '#ff0000', 'type' => 1], '6' => null]);

        $layers = $this->plugin(false, $this->createStub(FacetDocuments::class), $this->createStub(AttributeDocuments::class), $eavConfig, $swatches)
            ->afterBuild($this->createStub(LayerBuilder::class), self::LAYERS, $this->createStub(AggregationInterface::class), 1);

        self::assertSame(['value' => '#ff0000', 'type' => 1], $this->swatchOf($layers, 'color_bucket', 0));
        self::assertNull($this->swatchOf($layers, 'color_bucket', 1));
        self::assertNull($this->swatchOf($layers, 'category_bucket', 0));
        self::assertNull($this->swatchOf($layers, 'new_bucket', 0));
    }
}
