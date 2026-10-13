<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSwatches\Plugin\DataExporter;

use GraphCommerce\CatalogStorefront\Model\DataExporter\Provider\AttributeOptions;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Swatches\Helper\Data as SwatchHelper;
use Magento\Swatches\Helper\Media as SwatchMedia;
use Magento\Swatches\Model\ResourceModel\Swatch\CollectionFactory;
use Magento\Swatches\Model\Swatch;

/**
 * Adds to every option of a swatch attribute its swatch for the store view, as core's swatch
 * helper picks it: a visual swatch from any row, a text swatch from the store view's row when it
 * holds a value, else from the admin row. An image swatch keeps its file; the read side builds
 * the thumbnail URL, so the export generates the thumbnail file.
 */
class AttributeOptionSwatches
{
    public function __construct(
        private readonly EavConfig $eavConfig,
        private readonly SwatchHelper $swatchHelper,
        private readonly SwatchMedia $swatchMedia,
        private readonly CollectionFactory $collectionFactory,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function afterGet(AttributeOptions $subject, array $rows): array
    {
        $swatchAttributes = [];
        $optionIds = [];
        foreach ($rows as $row) {
            $attributeId = (int)$row['id'];
            $swatchAttributes[$attributeId] ??= $this->swatchHelper->isSwatchAttribute(
                $this->eavConfig->getAttribute(Product::ENTITY, $attributeId)
            );
            if ($swatchAttributes[$attributeId]) {
                $optionIds[(string)$row['options']['id']] = true;
            }
        }
        if (!$optionIds) {
            return $rows;
        }

        // Per option id: the visual row, and the text rows by store id.
        $visual = [];
        $text = [];
        foreach ($this->collectionFactory->create()->addFilterByOptionsIds(array_keys($optionIds))->getData() as $swatch) {
            if ((int)$swatch['type'] !== Swatch::SWATCH_TYPE_TEXTUAL) {
                $visual[(string)$swatch['option_id']] = $swatch;
            } else {
                $text[(string)$swatch['option_id']][(int)$swatch['store_id']] = $swatch;
            }
        }

        $storeIds = [];
        foreach ($rows as $key => $row) {
            $optionId = (string)$row['options']['id'];
            if (!isset($optionIds[$optionId]) || !$swatchAttributes[(int)$row['id']]) {
                continue;
            }
            $storeId = $storeIds[$row['storeViewCode']] ??= (int)$this->storeManager->getStore($row['storeViewCode'])->getId();
            $storeText = $text[$optionId][$storeId] ?? null;
            $swatch = $visual[$optionId]
                ?? (($storeText['value'] ?? '') !== '' ? $storeText : null)
                ?? $text[$optionId][Store::DEFAULT_STORE_ID]
                ?? null;
            if ($swatch === null || $swatch['value'] === null) {
                continue;
            }
            if ((int)$swatch['type'] === Swatch::SWATCH_TYPE_VISUAL_IMAGE) {
                $this->swatchMedia->getSwatchAttributeImage(Swatch::SWATCH_THUMBNAIL_NAME, $swatch['value']);
            }
            $rows[$key]['options']['swatch'] = ['type' => (int)$swatch['type'], 'value' => (string)$swatch['value']];
        }

        return $rows;
    }
}
