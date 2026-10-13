<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Model\Resolver;

use Magento\Eav\Model\Config as EavConfig;
use Magento\EavGraphQl\Model\Output\GetAttributeDataInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * The attribute metadata of a custom attribute value, from the providers customAttributeMetadataV2 uses.
 */
class AttributeValueMetadata implements ResolverInterface
{
    public function __construct(
        private readonly EavConfig $eavConfig,
        private readonly GetAttributeDataInterface $getAttributeData,
    ) {
    }

    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        $entityType = strtolower((string)($value['entity_type'] ?? ''));
        $attribute = $this->eavConfig->getAttribute($entityType, (string)($value['code'] ?? ''));
        if (!$attribute->getAttributeId()) {
            return null;
        }

        return $this->getAttributeData->execute(
            $attribute,
            $entityType,
            (int)$context->getExtensionAttributes()->getStore()->getId()
        );
    }
}
