<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSwatchesGraphQl\Model;

use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Swatches\Helper\Data as SwatchHelper;
use Magento\Swatches\Helper\Media as SwatchMedia;
use Magento\Swatches\Model\Swatch;

/**
 * The swatches of the options of a swatch attribute on the core path, in the shape core's
 * configurable option swatch data provider gives them.
 */
class Swatches
{
    public function __construct(
        private readonly SwatchHelper $swatchHelper,
        private readonly SwatchMedia $swatchMedia,
    ) {
    }

    /**
     * @param string[] $optionIds
     * @return array<string, ?array> the swatch per option id; null for an option of another attribute or without a swatch
     */
    public function byOptionIds(AttributeInterface $attribute, array $optionIds): array
    {
        $result = array_fill_keys(array_map('strval', $optionIds), null);
        $optionIds = array_values(array_filter($optionIds, static fn($id) => (string)$id !== ''));
        if (!$optionIds || !$this->swatchHelper->isSwatchAttribute($attribute)) {
            return $result;
        }
        foreach ($this->swatchHelper->getSwatchesByOptionsId($optionIds) as $optionId => $swatch) {
            if (!isset($swatch['type'], $swatch['value'])) {
                continue;
            }
            $data = ['value' => $swatch['value'], 'type' => (int)$swatch['type']];
            if ($data['type'] === Swatch::SWATCH_TYPE_VISUAL_IMAGE) {
                $data['thumbnail'] = $this->swatchMedia->getSwatchAttributeImage(Swatch::SWATCH_THUMBNAIL_NAME, $swatch['value']);
            }
            $result[(string)$optionId] = $data;
        }

        return $result;
    }
}
