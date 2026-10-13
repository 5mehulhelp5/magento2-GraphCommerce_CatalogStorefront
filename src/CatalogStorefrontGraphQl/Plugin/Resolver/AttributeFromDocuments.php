<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\Read\AttributeDocuments;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\GraphQlCatalog\Model\Resolver\Attribute;
use GraphCommerce\CatalogStorefrontGraphQl\Model\AttributeMetadata;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Read\FacetDocuments;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Serves the metadata of a product attribute value or aggregation from the attribute document of
 * its code: an aggregation's from the documents the facet read primed, a value's from the
 * documents the custom attributes of the page fetched. Core serves other entity types. An
 * aggregation without an attribute document is a category aggregation and answers null.
 */
class AttributeFromDocuments
{
    public function __construct(
        private readonly AttributeDocuments $attributeDocuments,
        private readonly FacetDocuments $facets,
        private readonly AttributeMetadata $attributeMetadata,
        private readonly Mode $mode,
        private readonly Strict $strict,
    ) {
    }

    public function aroundResolve(
        Attribute $subject,
        \Closure $proceed,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $aggregation = isset($value['attribute_code']);
        if (!$this->mode->documents()
            || strtolower((string)($value['entity_type'] ?? ProductAttributeInterface::ENTITY_TYPE_CODE)) !== ProductAttributeInterface::ENTITY_TYPE_CODE
        ) {
            return $proceed($field, $context, $info, $value, $args);
        }
        $code = (string)($aggregation ? $value['attribute_code'] : ($value['code'] ?? ''));
        try {
            $storeCode = $context->getExtensionAttributes()->getStore()->getCode();
            $document = ($aggregation ? $this->facets->attributes($storeCode, [], [])[$code] ?? null : null)
                ?? $this->attributeDocuments->byCodes($storeCode, [$code])[$code]
                ?? null;
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);
        }
        if ($document === null) {
            if ($aggregation) {
                return null;
            }
            $this->strict->fallback(self::class, 'no attribute document for ' . $code);
        }

        return $this->attributeMetadata->fromDocument($document);
    }
}
