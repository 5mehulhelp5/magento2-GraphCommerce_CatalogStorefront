<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Model\Resolver;

use Magento\CatalogGraphQl\Model\Resolver\Product\ProductCustomAttributes;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * One entry of custom_attributesV2 by attribute code. The list resolver answers on both
 * paths: core's own, or the document plugin on it.
 */
class ProductCustomAttribute implements ResolverInterface
{
    public function __construct(
        private readonly ProductCustomAttributes $customAttributes,
    ) {
    }

    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        $code = (string)($args['attribute_code'] ?? '');
        foreach ($this->customAttributes->resolve($field, $context, $info, $value, [])['items'] as $item) {
            if (($item['code'] ?? null) === $code) {
                return $item;
            }
        }

        return null;
    }
}
