<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\Read\AttributeDocuments;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontGraphQl\Model\AttributeMetadata;
use Magento\EavGraphQl\Model\Resolver\AttributesList;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * The product attributes list from the attribute documents of the store view: one read of the
 * index instead of the attribute repository with its label join and an option load per select
 * attribute. Core serves other entity types.
 */
class AttributesListFromDocuments
{
    private const ENTITY = 'catalog_product';

    public function __construct(
        private readonly AttributeDocuments $attributeDocuments,
        private readonly AttributeMetadata $attributeMetadata,
        private readonly Mode $mode,
        private readonly Strict $strict,
    ) {
    }

    public function aroundResolve(
        AttributesList $subject,
        \Closure $proceed,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        if (!$this->mode->documents() || strtolower((string)($args['entityType'] ?? '')) !== self::ENTITY) {
            return $proceed($field, $context, $info, $value, $args);
        }
        $filters = [];
        foreach ((array)($args['filters'] ?? []) as $name => $wanted) {
            if ($name !== 'is_filterable' && !isset(AttributeMetadata::FLAGS[$name])) {
                $this->strict->fallback(self::class, 'unsupported attribute filter');
            }
            $filters[$name === 'is_filterable' ? 'filterableMode' : AttributeMetadata::FLAGS[$name]] = (int)$wanted;
        }
        $documents = $this->attributeDocuments->all($context->getExtensionAttributes()->getStore()->getCode());
        if (!$documents) {
            $this->strict->fallback(self::class, 'no attribute documents for the store view');
        }

        $items = [];
        foreach ($documents as $document) {
            if (empty($document['isVisible']) || ($document['backendType'] ?? '') === 'static' || ($document['attributeType'] ?? self::ENTITY) !== self::ENTITY) {
                continue;
            }
            foreach ($filters as $key => $wanted) {
                if ((int)($document[$key] ?? 0) !== $wanted) {
                    continue 2;
                }
            }
            $items[] = $this->attributeMetadata->fromDocument($document);
        }
        usort($items, static fn(array $a, array $b) => (int)$a['id'] <=> (int)$b['id']);

        return ['items' => $items, 'entity_type' => 'CATALOG_PRODUCT', 'errors' => []];
    }
}
