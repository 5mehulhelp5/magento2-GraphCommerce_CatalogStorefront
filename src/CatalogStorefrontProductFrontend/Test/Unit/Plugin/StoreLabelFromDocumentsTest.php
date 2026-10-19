<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefront\Model\Read\AttributeDocuments;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Read\AttributeOptions;
use GraphCommerce\CatalogStorefrontProductFrontend\Plugin\StoreLabelFromDocuments;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Model\Entity\Type;
use Magento\Framework\App\State;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The store label of a product attribute as getStoreLabel() answers it, from the attribute document
 * of the store view.
 */
class StoreLabelFromDocumentsTest extends TestCase
{
    private bool $proceeded = false;

    private bool $served = true;

    private bool $emulated = false;

    private AttributeDocuments&MockObject $documents;

    private StoreInterface $store;

    /**
     * @param array<string, array> $documents the attribute documents of the store view, by code
     */
    private function plugin(array $documents = ['color' => ['attributeCode' => 'color', 'label' => 'Kleur']], ?LoggerInterface $logger = null): StoreLabelFromDocuments
    {
        $this->documents = $this->createMock(AttributeDocuments::class);
        $this->documents->method('byCodes')->willReturnCallback(
            static fn(string $store, array $codes): array => array_intersect_key($documents, array_flip($codes))
        );
        $mode = $this->createMock(Mode::class);
        $mode->method('listing')->willReturn(false);
        $mode->method('detail')->willReturnCallback(fn(int $storeId): bool => $storeId === 1 && $this->served);
        $this->store = $this->createStub(StoreInterface::class);
        $this->store->method('getId')->willReturn('1');
        $this->store->method('getCode')->willReturn('default');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($this->store);
        $state = $this->createStub(State::class);
        $state->method('isAreaCodeEmulated')->willReturnCallback(fn(): bool => $this->emulated);
        $strict = new Strict($this->createStub(StorefrontKey::class), $logger ?? $this->createStub(LoggerInterface::class));

        return new StoreLabelFromDocuments(new AttributeOptions($this->documents, $mode, $stores, $state, $strict));
    }

    private function attribute(string $code = 'color', string $entity = 'catalog_product', int|string|null $storeId = null, ?string $sourceModel = null): Attribute
    {
        $type = $this->createStub(Type::class);
        $type->method('getEntityTypeCode')->willReturn($entity);
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getEntityType')->willReturn($type);
        $attribute->method('getStoreId')->willReturn($storeId);
        $attribute->method('getSourceModel')->willReturn($sourceModel);

        return $attribute;
    }

    private function proceed(): \Closure
    {
        return function () {
            $this->proceeded = true;

            return 'core';
        };
    }

    public function testTheLabelIsTheDocuments(): void
    {
        // Core would answer the labels it read when the worker first asked.
        $plugin = $this->plugin();

        $this->assertSame('Kleur', $plugin->aroundGetStoreLabel($this->attribute(), $this->proceed()));
        $this->assertFalse($this->proceeded);
    }

    public function testTheRequestsStoreAskedForByIdOrModelIsTheDocuments(): void
    {
        $plugin = $this->plugin();

        $this->assertSame('Kleur', $plugin->aroundGetStoreLabel($this->attribute(), $this->proceed(), 1));
        $this->assertSame('Kleur', $plugin->aroundGetStoreLabel($this->attribute(), $this->proceed(), '1'));
        $this->assertSame('Kleur', $plugin->aroundGetStoreLabel($this->attribute(), $this->proceed(), $this->store));
        $this->assertFalse($this->proceeded);
    }

    public function testAnAttributeWithASourceModelOfItsOwnHasItsLabelInTheDocument(): void
    {
        $plugin = $this->plugin();

        $this->assertSame('Kleur', $plugin->aroundGetStoreLabel($this->attribute('color', 'catalog_product', null, 'Vendor\Module\Model\Source\Colors'), $this->proceed()));
    }

    public function testLabelsTheDocumentsDoNotServeGoToCore(): void
    {
        $plugin = $this->plugin();
        $this->documents->expects($this->never())->method('byCodes');

        foreach ([
            'another store asked for' => [$this->attribute(), 2],
            'an attribute of another store' => [$this->attribute('color', 'catalog_product', 2), null],
            'a category attribute' => [$this->attribute('color', 'catalog_category'), null],
        ] as $case => [$attribute, $storeId]) {
            $this->proceeded = false;
            $this->assertSame('core', $plugin->aroundGetStoreLabel($attribute, $this->proceed(), $storeId), $case);
        }

        $this->emulated = true;
        $this->assertSame('core', $plugin->aroundGetStoreLabel($this->attribute(), $this->proceed()), 'the export');

        $this->emulated = false;
        $this->served = false;
        $this->assertSame('core', $plugin->aroundGetStoreLabel($this->attribute(), $this->proceed()), 'a store view served from the database');
    }

    public function testAnAttributeWithoutADocumentStopsTheReadAndIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains(
            'Attribute "material" has no document in store view "default"'
        ));
        $plugin = $this->plugin(logger: $logger);

        $this->expectException(DocumentReadException::class);
        $plugin->aroundGetStoreLabel($this->attribute('material'), $this->proceed());
    }

    public function testADocumentWithoutALabelStopsTheRead(): void
    {
        // The export drops a null label: the document cannot tell a missing label from a lost one.
        $plugin = $this->plugin(['color' => ['attributeCode' => 'color']]);

        $this->expectException(DocumentReadException::class);
        $this->expectExceptionMessage('The document of attribute "color" in store view "default" has no label');
        try {
            $plugin->aroundGetStoreLabel($this->attribute(), $this->proceed());
        } finally {
            $this->assertFalse($this->proceeded);
        }
    }
}
