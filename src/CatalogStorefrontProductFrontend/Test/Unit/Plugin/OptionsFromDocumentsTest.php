<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefront\Model\Read\AttributeDocuments;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Read\AttributeOptions;
use GraphCommerce\CatalogStorefrontProductFrontend\Plugin\OptionsFromDocuments;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Model\Entity\Attribute\Source\Table;
use Magento\Eav\Model\Entity\Type;
use Magento\Framework\App\State;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The options of a product attribute's table source as getAllOptions() and getSpecificOptions()
 * answer them, from the attribute document of the store view.
 */
class OptionsFromDocumentsTest extends TestCase
{
    private const COLOR = [
        'attributeCode' => 'color',
        'options' => [
            ['id' => '52', 'label' => 'Red', 'facetSortOrder' => 0],
            ['id' => '50', 'label' => 'Blue', 'facetSortOrder' => 1],
            ['id' => '51', 'label' => 'Green', 'facetSortOrder' => 2],
        ],
    ];

    private bool $proceeded = false;

    private bool $served = true;

    private bool $emulated = false;

    private AttributeDocuments&MockObject $documents;

    /**
     * @param array<string, array> $documents the attribute documents of the store view, by code
     */
    private function plugin(array $documents = ['color' => self::COLOR], ?LoggerInterface $logger = null): OptionsFromDocuments
    {
        $this->documents = $this->createMock(AttributeDocuments::class);
        $this->documents->method('byCodes')->willReturnCallback(
            static fn(string $store, array $codes): array => array_intersect_key($documents, array_flip($codes))
        );
        $mode = $this->createMock(Mode::class);
        $mode->method('listing')->willReturnCallback(fn(int $storeId): bool => $storeId === 1 && $this->served);
        $mode->method('detail')->willReturn(false);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn('1');
        $store->method('getCode')->willReturn('default');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $state = $this->createStub(State::class);
        $state->method('isAreaCodeEmulated')->willReturnCallback(fn(): bool => $this->emulated);
        $strict = new Strict($this->createStub(StorefrontKey::class), $logger ?? $this->createStub(LoggerInterface::class));

        return new OptionsFromDocuments(new AttributeOptions($this->documents, $mode, $stores, $state, $strict), $strict);
    }

    private function source(string $code = 'color', ?string $sourceModel = null, string $entity = 'catalog_product', int|string|null $storeId = null): Table
    {
        $type = $this->createStub(Type::class);
        $type->method('getEntityTypeCode')->willReturn($entity);
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getSourceModel')->willReturn($sourceModel);
        $attribute->method('getEntityType')->willReturn($type);
        $attribute->method('getStoreId')->willReturn($storeId);
        $source = $this->createStub(Table::class);
        $source->method('getAttribute')->willReturn($attribute);

        return $source;
    }

    private function proceed(): \Closure
    {
        return function () {
            $this->proceeded = true;

            return [];
        };
    }

    public function testAllOptionsAreTheDocumentsInPositionOrder(): void
    {
        $plugin = $this->plugin();

        $this->assertSame([
            ['label' => ' ', 'value' => ''],
            ['value' => '52', 'label' => 'Red'],
            ['value' => '50', 'label' => 'Blue'],
            ['value' => '51', 'label' => 'Green'],
        ], $plugin->aroundGetAllOptions($this->source(), $this->proceed()));
        $this->assertSame(
            [['value' => '52', 'label' => 'Red'], ['value' => '50', 'label' => 'Blue'], ['value' => '51', 'label' => 'Green']],
            $plugin->aroundGetAllOptions($this->source(), $this->proceed(), false)
        );
        $this->assertFalse($this->proceeded, 'a query per request is what this avoids');
    }

    public function testSpecificOptionsAreTheIdsAskedForInPositionOrder(): void
    {
        // getOptionText() asks for one value, or for every value of a multiselect.
        $plugin = $this->plugin();

        $this->assertSame(
            [['value' => '52', 'label' => 'Red'], ['value' => '51', 'label' => 'Green']],
            $plugin->aroundGetSpecificOptions($this->source(), $this->proceed(), ['51', 52], false)
        );
        $this->assertSame(
            [['label' => ' ', 'value' => ''], ['value' => '50', 'label' => 'Blue']],
            $plugin->aroundGetSpecificOptions($this->source(), $this->proceed(), '50')
        );
        $this->assertSame([], $plugin->aroundGetSpecificOptions($this->source(), $this->proceed(), ['99'], false), 'an option that is gone');
        $this->assertFalse($this->proceeded, 'a query per value is what this avoids');
    }

    public function testAnAttributeWithoutOptionsHasNone(): void
    {
        // The export writes no options for an attribute without any.
        $plugin = $this->plugin(['material' => ['attributeCode' => 'material', 'options' => null]]);

        $this->assertSame([], $plugin->aroundGetAllOptions($this->source('material'), $this->proceed(), false));
    }

    public function testAnAttributeWithoutADocumentStopsTheReadAndIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains(
            'Attribute "material" has no document in store view "default": run bin/magento catalog-storefront:rebuild attribute'
        ));
        $plugin = $this->plugin(logger: $logger);

        $this->expectException(DocumentReadException::class);
        $plugin->aroundGetSpecificOptions($this->source('material'), $this->proceed(), ['7'], false);
    }

    public function testTheAdminLabelsStopTheRead(): void
    {
        $plugin = $this->plugin();

        $this->expectException(DocumentReadException::class);
        $this->expectExceptionMessage('The admin labels of attribute "color" are not in its document');
        $plugin->aroundGetAllOptions($this->source(), $this->proceed(), true, true);
    }

    public function testOptionsThatAreNotTheTableSourcesOwnReadTheTables(): void
    {
        $plugin = $this->plugin();
        $this->documents->expects($this->never())->method('byCodes');

        foreach ([
            'a source model of its own' => $this->source('color', 'Vendor\Module\Model\Source\Options'),
            'a category attribute' => $this->source('color', null, 'catalog_category'),
            'another store' => $this->source('color', null, 'catalog_product', 2),
        ] as $case => $source) {
            $this->proceeded = false;
            $plugin->aroundGetAllOptions($source, $this->proceed());
            $this->assertTrue($this->proceeded, $case);
        }
    }

    public function testAStoreServedFromTheDatabaseAndTheExportReadTheTables(): void
    {
        // The attribute export reads the options through the table source under an emulated frontend area.
        $plugin = $this->plugin();

        $this->emulated = true;
        $plugin->aroundGetSpecificOptions($this->source(), $this->proceed(), ['50']);
        $this->assertTrue($this->proceeded, 'the export');

        $this->emulated = false;
        $this->served = false;
        $this->proceeded = false;
        $plugin->aroundGetAllOptions($this->source('color', 'Magento\Eav\Model\Entity\Attribute\Source\Table', 'catalog_product', '1'), $this->proceed());
        $this->assertTrue($this->proceeded, 'a store view served from the database');
    }
}
