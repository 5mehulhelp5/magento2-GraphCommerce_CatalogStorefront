<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSwatches\Test\Unit\Plugin\DataExporter;

use GraphCommerce\CatalogStorefront\Model\DataExporter\Provider\AttributeOptions;
use GraphCommerce\CatalogStorefrontSwatches\Plugin\DataExporter\AttributeOptionSwatches;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Swatches\Helper\Data as SwatchHelper;
use Magento\Swatches\Helper\Media as SwatchMedia;
use Magento\Swatches\Model\ResourceModel\Swatch\Collection;
use Magento\Swatches\Model\ResourceModel\Swatch\CollectionFactory;
use PHPUnit\Framework\TestCase;

class AttributeOptionSwatchesTest extends TestCase
{
    private const SWATCH_ROWS = [
        ['option_id' => '5', 'store_id' => '0', 'type' => '1', 'value' => '#ff0000'],
        ['option_id' => '6', 'store_id' => '0', 'type' => '2', 'value' => '/p/a/pattern.png'],
        ['option_id' => '7', 'store_id' => '0', 'type' => '0', 'value' => 'S'],
        ['option_id' => '7', 'store_id' => '2', 'type' => '0', 'value' => 'Sm'],
        ['option_id' => '8', 'store_id' => '0', 'type' => '0', 'value' => 'L'],
        ['option_id' => '8', 'store_id' => '2', 'type' => '0', 'value' => ''],
    ];

    private function row(int $attributeId, string $optionId): array
    {
        return ['id' => (string)$attributeId, 'storeViewCode' => 'default', 'options' => ['id' => $optionId, 'label' => 'L' . $optionId]];
    }

    public function testEveryOptionOfASwatchAttributeTakesTheSwatchCoreWouldPick(): void
    {
        $attributes = [93 => true, 94 => true, 95 => false];
        $eavConfig = $this->createStub(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturnCallback(function (string $entity, int $id) {
            $attribute = $this->createStub(Attribute::class);
            $attribute->method('getAttributeId')->willReturn($id);
            return $attribute;
        });
        $helper = $this->createStub(SwatchHelper::class);
        $helper->method('isSwatchAttribute')->willReturnCallback(static fn($attribute) => $attributes[$attribute->getAttributeId()]);
        $collection = $this->createMock(Collection::class);
        $collection->expects(self::once())->method('addFilterByOptionsIds')->with([5, 6, 7, 8, 9])->willReturnSelf();
        $collection->method('getData')->willReturn(self::SWATCH_ROWS);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        $media = $this->createMock(SwatchMedia::class);
        $media->expects(self::once())->method('getSwatchAttributeImage')->with('swatch_thumb', '/p/a/pattern.png');
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(2);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);

        $rows = (new AttributeOptionSwatches($eavConfig, $helper, $media, $factory, $stores))->afterGet(
            $this->createStub(AttributeOptions::class),
            ['a' => $this->row(93, '5'), 'b' => $this->row(93, '6'), 'c' => $this->row(94, '7'), 'd' => $this->row(94, '8'), 'e' => $this->row(94, '9'), 'f' => $this->row(95, '5')]
        );

        self::assertSame(['type' => 1, 'value' => '#ff0000'], $rows['a']['options']['swatch']);
        self::assertSame(['type' => 2, 'value' => '/p/a/pattern.png'], $rows['b']['options']['swatch']);
        self::assertSame(['type' => 0, 'value' => 'Sm'], $rows['c']['options']['swatch'], 'the store view text');
        self::assertSame(['type' => 0, 'value' => 'L'], $rows['d']['options']['swatch'], 'an empty store view text falls back to the admin text');
        self::assertArrayNotHasKey('swatch', $rows['e']['options'], 'an option without a swatch row');
        self::assertArrayNotHasKey('swatch', $rows['f']['options'], 'an option of another attribute');
    }
}
