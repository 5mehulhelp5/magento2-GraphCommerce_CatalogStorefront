<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSwatchesGraphQl\Plugin\Layer;

use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\Read\AttributeDocuments;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Read\FacetDocuments;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use GraphCommerce\CatalogStorefrontSwatchesGraphQl\Model\Swatches;
use Magento\Catalog\Model\Product;
use Magento\CatalogGraphQl\DataProvider\Product\LayeredNavigation\LayerBuilder;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Api\Search\AggregationInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Puts the swatch of every option of a select attribute's aggregation on the option: on the
 * document path from the attribute documents the facet read primed, on the core path from core's
 * swatch helper. Category, price and boolean values are no option ids and carry no swatch.
 */
class AggregationSwatches
{
    private const SELECT_INPUTS = ['select', 'multiselect'];

    public function __construct(
        private readonly Mode $mode,
        private readonly FacetDocuments $facets,
        private readonly AttributeDocuments $attributeDocuments,
        private readonly StoreManagerInterface $storeManager,
        private readonly EavConfig $eavConfig,
        private readonly Swatches $swatches,
        private readonly Strict $strict,
    ) {
    }

    public function afterBuild(LayerBuilder $subject, array $layers, AggregationInterface $aggregation, ?int $storeId): array
    {
        if (!$layers) {
            return $layers;
        }
        $swatchesByCode = [];
        if ($this->mode->documents()) {
            try {
                $storeCode = $this->storeManager->getStore($storeId)->getCode();
                $documents = $this->facets->attributes($storeCode, [], []) ?? [];
                $missing = array_values(array_diff(array_column($layers, 'attribute_code'), array_keys($documents)));
                if ($missing) {
                    $documents += $this->attributeDocuments->byCodes($storeCode, $missing);
                }
            } catch (\Throwable $e) {
                $this->strict->exception(self::class, $e);
            }
            foreach ($documents as $code => $document) {
                if (in_array($document['frontendInput'] ?? '', self::SELECT_INPUTS, true)) {
                    foreach ((array)($document['options'] ?? []) as $option) {
                        $swatchesByCode[$code][(string)$option['id']] = $option['swatch'] ?? null;
                    }
                }
            }
        } else {
            foreach ($layers as $layer) {
                $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $layer['attribute_code']);
                if ($attribute->getAttributeId() && in_array($attribute->getFrontendInput(), self::SELECT_INPUTS, true)) {
                    $swatchesByCode[$layer['attribute_code']] = $this->swatches->byOptionIds(
                        $attribute,
                        array_column($layer['options'] ?? [], 'value')
                    );
                }
            }
        }

        foreach ($layers as $key => $layer) {
            foreach ($layer['options'] ?? [] as $index => $option) {
                $layers[$key]['options'][$index][PrefillerInterface::KEY]['swatch']
                    = $swatchesByCode[$layer['attribute_code']][(string)$option['value']] ?? null;
            }
        }

        return $layers;
    }
}
