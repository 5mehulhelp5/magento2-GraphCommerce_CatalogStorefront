<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Test\Unit\Model\Read;

use GraphCommerce\CatalogStorefront\Model\Read\AttributeDocuments;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Read\AttributeOptions;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Model\Entity\Type;
use Magento\Framework\App\State;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The documents of a product's attributes, read in one request before its page asks for their options.
 */
class AttributeOptionsTest extends TestCase
{
    private AttributeDocuments&MockObject $documents;

    private function options(bool $served = true): AttributeOptions
    {
        $this->documents = $this->createMock(AttributeDocuments::class);
        $mode = $this->createMock(Mode::class);
        $mode->method('listing')->willReturn(false);
        $mode->method('detail')->willReturnCallback(static fn(int $storeId): bool => $served && $storeId === 1);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn('1');
        $store->method('getCode')->willReturn('default');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);

        return new AttributeOptions(
            $this->documents,
            $mode,
            $stores,
            $this->createStub(State::class),
            new Strict($this->createStub(StorefrontKey::class), $this->createStub(LoggerInterface::class))
        );
    }

    private function attribute(string $code, string $input, ?string $sourceModel = null): Attribute
    {
        $type = $this->createStub(Type::class);
        $type->method('getEntityTypeCode')->willReturn('catalog_product');
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getFrontendInput')->willReturn($input);
        $attribute->method('getSourceModel')->willReturn($sourceModel);
        $attribute->method('getEntityType')->willReturn($type);

        return $attribute;
    }

    private function product(array $attributes): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getAttributes')->willReturn($attributes);

        return $product;
    }

    public function testTheAttributesWhoseFrontendAsksTheSourceAreReadInOneRequest(): void
    {
        $options = $this->options();
        // A boolean without a source model of its own reads the table source too.
        $this->documents->expects($this->once())->method('byCodes')->with('default', ['color', 'material', 'eco_collection']);

        $options->prime($this->product([
            $this->attribute('color', 'select'),
            $this->attribute('description', 'textarea'),
            $this->attribute('brand', 'select', 'Vendor\Module\Model\Source\Brands'),
            $this->attribute('material', 'multiselect', 'Magento\Eav\Model\Entity\Attribute\Source\Table'),
            $this->attribute('eco_collection', 'boolean'),
        ]));
    }

    public function testAStoreServedFromTheDatabaseReadsNoDocument(): void
    {
        $options = $this->options(false);
        $this->documents->expects($this->never())->method('byCodes');

        $options->prime($this->product([$this->attribute('color', 'select')]));
    }
}
