<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\Read\AttributeDocuments;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontGraphQl\Model\AttributeMetadata;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Resolver\AttributeValueMetadata;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Serves the metadata of a product attribute value from the attribute document of its code,
 * which the custom attributes of the page fetched already. Core serves other entity types.
 */
class AttributeValueMetadataFromDocuments
{
    public function __construct(
        private readonly AttributeDocuments $attributeDocuments,
        private readonly AttributeMetadata $attributeMetadata,
        private readonly Mode $mode,
        private readonly Strict $strict,
    ) {
    }

    public function aroundResolve(
        AttributeValueMetadata $subject,
        \Closure $proceed,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        if (!$this->mode->documents() || strtolower((string)($value['entity_type'] ?? '')) !== ProductAttributeInterface::ENTITY_TYPE_CODE) {
            return $proceed($field, $context, $info, $value, $args);
        }
        $code = (string)($value['code'] ?? '');
        try {
            $document = $this->attributeDocuments->byCodes($context->getExtensionAttributes()->getStore()->getCode(), [$code])[$code] ?? null;
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);
        }
        if ($document === null) {
            $this->strict->fallback(self::class, 'no attribute document for ' . $code);
        }

        return $this->attributeMetadata->fromDocument($document);
    }
}
