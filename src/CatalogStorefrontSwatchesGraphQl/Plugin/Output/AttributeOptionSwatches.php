<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSwatchesGraphQl\Plugin\Output;

use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use GraphCommerce\CatalogStorefrontSwatchesGraphQl\Model\Swatches;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\EavGraphQl\Model\Output\GetAttributeDataComposite;

/**
 * Puts the swatch of every option on core's attribute metadata, which attributesList,
 * customAttributeMetadataV2 and the attribute of a value return on the core path.
 */
class AttributeOptionSwatches
{
    public function __construct(
        private readonly Swatches $swatches,
    ) {
    }

    public function afterExecute(GetAttributeDataComposite $subject, array $result, AttributeInterface $attribute): array
    {
        if (empty($result['options'])) {
            return $result;
        }
        $swatches = $this->swatches->byOptionIds($attribute, array_column($result['options'], 'value'));
        foreach ($result['options'] as $index => $option) {
            $result['options'][$index][PrefillerInterface::KEY]['swatch'] = $swatches[(string)$option['value']] ?? null;
        }

        return $result;
    }
}
