<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSwatchesGraphQl\Plugin\Output;

use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use GraphCommerce\CatalogStorefrontSwatchesGraphQl\Model\Swatches;
use Magento\Eav\Model\Config as EavConfig;
use Magento\EavGraphQl\Model\Output\Value\GetAttributeValueComposite;

/**
 * Puts the swatch of every selected option on a custom attribute value of the core path.
 */
class SelectedOptionSwatches
{
    public function __construct(
        private readonly EavConfig $eavConfig,
        private readonly Swatches $swatches,
    ) {
    }

    public function afterExecute(GetAttributeValueComposite $subject, ?array $result, string $entity, string $code): ?array
    {
        if (empty($result['selected_options'])) {
            return $result;
        }
        $swatches = $this->swatches->byOptionIds(
            $this->eavConfig->getAttribute($entity, $code),
            array_column($result['selected_options'], 'value')
        );
        foreach ($result['selected_options'] as $index => $option) {
            $result['selected_options'][$index][PrefillerInterface::KEY]['swatch'] = $swatches[(string)$option['value']] ?? null;
        }

        return $result;
    }
}
