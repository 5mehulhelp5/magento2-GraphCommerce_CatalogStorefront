<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\Read\AttributeDocuments;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefrontGraphQl\Model\AttributeMetadata;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Read\FacetDocuments;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Resolver\AttributeValueMetadata;
use GraphCommerce\CatalogStorefrontGraphQl\Plugin\Resolver\AttributeValueMetadataFromDocuments;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\EnumLookup;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextExtensionInterface;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AttributeValueMetadataFromDocumentsTest extends TestCase
{
    private const SHAPE = [
        'attributeCode' => 'shape', 'attributeId' => 140, 'label' => 'Shape', 'frontendInput' => 'select', 'isVisible' => true,
        'options' => [['id' => '92', 'label' => 'Round']],
    ];

    private function resolve(bool $documents, array $value, array $byCodes = [], ?array $primed = null): mixed
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $extension = $this->createStub(ContextExtensionInterface::class);
        $extension->method('getStore')->willReturn($store);
        $context = $this->createStub(ContextInterface::class);
        $context->method('getExtensionAttributes')->willReturn($extension);
        $mode = $this->createStub(Mode::class);
        $mode->method('documents')->willReturn($documents);
        $attributeDocuments = $this->createStub(AttributeDocuments::class);
        $attributeDocuments->method('byCodes')->willReturnCallback(
            static fn(string $store, array $codes): array => array_intersect_key($byCodes, array_flip($codes))
        );
        $facets = $this->createStub(FacetDocuments::class);
        $facets->method('attributes')->willReturn($primed);
        $enums = $this->createStub(EnumLookup::class);
        $enums->method('getEnumValueFromField')->willReturnCallback(static fn(string $enum, string $input) => strtoupper($input));
        $plugin = new AttributeValueMetadataFromDocuments(
            $attributeDocuments,
            $facets,
            new AttributeMetadata($enums),
            $mode,
            new Strict($this->createStub(StorefrontKey::class), $this->createStub(LoggerInterface::class))
        );

        return $plugin->aroundResolve(
            $this->createStub(AttributeValueMetadata::class),
            static fn() => 'core',
            $this->createStub(Field::class),
            $context,
            $this->createStub(ResolveInfo::class),
            $value
        );
    }

    public function testAProductValueTakesTheMetadataOfItsAttributeDocument(): void
    {
        $metadata = $this->resolve(true, ['entity_type' => 'catalog_product', 'code' => 'shape'], ['shape' => self::SHAPE]);

        self::assertSame('shape', $metadata['code']);
        self::assertSame('Shape', $metadata['label']);
        self::assertSame('SELECT', $metadata['frontend_input']);
        self::assertSame('CATALOG_PRODUCT', $metadata['entity_type']);
        self::assertSame(['92'], array_column($metadata['options'], 'value'));
    }

    public function testCoreAnswersOtherEntitiesAndTheCoreMode(): void
    {
        self::assertSame('core', $this->resolve(true, ['entity_type' => 'customer', 'code' => 'gender']));
        self::assertSame('core', $this->resolve(false, ['entity_type' => 'catalog_product', 'code' => 'shape'], ['shape' => self::SHAPE]));
    }

    public function testACodeWithoutADocumentFails(): void
    {
        $this->expectException(DocumentReadException::class);
        $this->resolve(true, ['entity_type' => 'catalog_product', 'code' => 'shape']);
    }

    public function testAnAggregationTakesThePrimedFacetDocumentAndACategoryAggregationAnswersNull(): void
    {
        $metadata = $this->resolve(true, ['attribute_code' => 'shape', 'label' => 'Shape', 'options' => []], [], ['shape' => self::SHAPE]);
        self::assertSame('shape', $metadata['code']);

        self::assertNull($this->resolve(true, ['attribute_code' => 'category_uid', 'options' => []], [], ['shape' => self::SHAPE]));
    }
}
