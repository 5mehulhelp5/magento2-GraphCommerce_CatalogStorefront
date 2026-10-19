<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Plugin;

use GraphCommerce\CatalogStorefront\Model\Read\AttributeDocuments;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Read\AttributeOptions;
use Magento\Swatches\Helper\Data as SwatchHelper;

/**
 * Answers the swatches of options on a rendered page from the attribute documents.
 *
 * Every swatch a page shows asks core's swatch helper by option id: the swatches of a product
 * page, of the cards of a listing and of its layered navigation. Core queries the swatch table
 * and keeps the answer by option id alone for the life of the process, so under an application
 * server a changed swatch shows only after a restart, and a text swatch read for one store view
 * answers the others. The document holds each option's swatch as core's helper picks it (a visual
 * swatch from any row, a text swatch from the store view's row, else the admin row), written by
 * GraphCommerce_CatalogStorefrontSwatches. The answer has the keys the pages read, as the table
 * returns them: option id, type and value. An option without a swatch is absent, as in core's; an
 * option no document of the store view holds stops the page.
 */
class SwatchesFromDocuments
{
    public function __construct(
        private readonly AttributeOptions $attributes,
        private readonly AttributeDocuments $documents,
        private readonly Strict $strict,
    ) {
    }

    /**
     * @param SwatchHelper $subject
     * @param \Closure $proceed
     * @param array $optionIds
     * @return array
     */
    public function aroundGetSwatchesByOptionsId(SwatchHelper $subject, \Closure $proceed, array $optionIds)
    {
        $store = $this->attributes->requestStore();
        if ($store === null) {
            return $proceed($optionIds);
        }
        $storeViewCode = (string)$store->getCode();
        try {
            $options = $this->documents->optionsById($storeViewCode, $optionIds);
            $missing = array_diff(array_map('strval', $optionIds), array_map('strval', array_keys($options)));
            if ($missing !== []) {
                throw new \UnexpectedValueException(sprintf(
                    'Options %s are in no attribute document of store view "%s": run bin/magento catalog-storefront:rebuild attribute',
                    implode(', ', $missing),
                    $storeViewCode
                ));
            }
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);
        }

        $swatches = [];
        foreach ($optionIds as $optionId) {
            $swatch = $options[(string)$optionId]['swatch'] ?? null;
            if ($swatch === null) {
                continue;
            }
            $swatches[$optionId] = [
                'option_id' => (string)$optionId,
                'type' => (string)$swatch['type'],
                'value' => (string)$swatch['value'],
            ];
        }

        return $swatches;
    }
}
