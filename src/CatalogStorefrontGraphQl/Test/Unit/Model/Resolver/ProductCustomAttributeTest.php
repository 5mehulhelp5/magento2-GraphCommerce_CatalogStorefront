<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Model\Resolver;

use GraphCommerce\CatalogStorefrontGraphQl\Model\Resolver\ProductCustomAttribute;
use Magento\CatalogGraphQl\Model\Resolver\Product\ProductCustomAttributes;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use PHPUnit\Framework\TestCase;

class ProductCustomAttributeTest extends TestCase
{
    public function testTheEntryOfTheCodeComesFromTheUnfilteredList(): void
    {
        $value = ['model' => 'product'];
        $list = $this->createMock(ProductCustomAttributes::class);
        $list->expects(self::exactly(2))->method('resolve')
            ->with(self::anything(), self::anything(), self::anything(), $value, [])
            ->willReturn(['items' => [['code' => 'name', 'value' => 'Wand'], ['code' => 'shape', 'selected_options' => []]], 'errors' => []]);
        $resolver = new ProductCustomAttribute($list);
        $field = $this->createStub(Field::class);
        $info = $this->createStub(ResolveInfo::class);

        self::assertSame(['code' => 'shape', 'selected_options' => []], $resolver->resolve($field, null, $info, $value, ['attribute_code' => 'shape']));
        self::assertNull($resolver->resolve($field, null, $info, $value, ['attribute_code' => 'color']));
    }
}
