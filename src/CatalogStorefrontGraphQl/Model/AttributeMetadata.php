<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Model;

use GraphCommerce\GraphQlCatalog\Model\OptionSwatch;
use Magento\Framework\GraphQl\Query\EnumLookup;

/**
 * A product attribute document in the shape core's attribute data providers give a
 * CustomAttributeMetadataInterface value. An option carries its document swatch under
 * OptionSwatch::KEY, so the swatch field reads no table.
 */
class AttributeMetadata
{
    // GraphQL flag to document field; is_filterable reads filterableMode, since core matches mode 1 only
    public const FLAGS = [
        'is_comparable' => 'visibleInCompareList',
        'is_filterable_in_search' => 'filterableInSearch',
        'is_html_allowed_on_front' => 'htmlAllowedOnFront',
        'is_searchable' => 'searchable',
        'is_used_for_price_rules' => 'usedForRules',
        'is_used_for_promo_rules' => 'usedForPromoRules',
        'is_visible_in_advanced_search' => 'visibleInAdvancedSearch',
        'is_visible_on_front' => 'visibleInSearch',
        'is_wysiwyg_enabled' => 'wysiwygEnabled',
        'used_in_product_listing' => 'visibleInListing',
    ];

    public function __construct(
        private readonly EnumLookup $enumLookup,
    ) {
    }

    public function fromDocument(array $document): array
    {
        $default = (string)($document['defaultValue'] ?? '');
        $options = [];
        foreach ((array)($document['options'] ?? []) as $option) {
            $optionValue = (string)($option['id'] ?? '');
            $label = (string)($option['label'] ?? '');
            if (trim($optionValue) === '' && trim($label) === '') {
                continue;
            }
            $options[] = [
                'label' => $label,
                'value' => $optionValue,
                'is_default' => $default && in_array($optionValue, explode(',', $default), true),
                OptionSwatch::KEY => $option['swatch'] ?? null,
            ];
        }
        $input = $document['frontendInput'] ?? null;
        $item = [
            'id' => $document['attributeId'] ?? null,
            'code' => $document['attributeCode'] ?? $document['id'],
            'label' => $document['label'] ?? null,
            'sort_order' => $document['position'] ?? null,
            'entity_type' => 'CATALOG_PRODUCT',
            'frontend_input' => $input === null ? 'UNDEFINED' : $this->enumLookup->getEnumValueFromField('AttributeFrontendInputEnum', (string)$input),
            'frontend_class' => $document['frontendClass'] ?? null,
            'is_required' => !empty($document['required']),
            'default_value' => $document['defaultValue'] ?? null,
            'is_unique' => !empty($document['unique']),
            'options' => $options,
            'is_filterable' => (int)($document['filterableMode'] ?? 0) === 1,
            'apply_to' => empty($document['applyTo']) ? null : array_map('strtoupper', explode(',', (string)$document['applyTo'])),
        ];
        foreach (self::FLAGS as $flag => $key) {
            $item[$flag] = !empty($document[$key]);
        }

        return $item + array_map('strtoupper', (array)json_decode((string)($document['additionalData'] ?? ''), true));
    }
}
