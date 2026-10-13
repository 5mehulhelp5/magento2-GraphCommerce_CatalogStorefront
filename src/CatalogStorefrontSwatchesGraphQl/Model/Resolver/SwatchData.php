<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSwatchesGraphQl\Model\Resolver;

use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Swatches\Helper\Media as SwatchMedia;
use Magento\Swatches\Model\Swatch;

/**
 * The swatch an option carries under the prefiller key. A document swatch names the image file
 * only, so its thumbnail URL is built here as core's swatch media helper builds it.
 */
class SwatchData implements ResolverInterface
{
    private const TYPES = [Swatch::SWATCH_TYPE_TEXTUAL, Swatch::SWATCH_TYPE_VISUAL_COLOR, Swatch::SWATCH_TYPE_VISUAL_IMAGE];

    public function __construct(
        private readonly SwatchMedia $swatchMedia,
    ) {
    }

    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        $swatch = $value[PrefillerInterface::KEY]['swatch'] ?? null;
        if (!is_array($swatch) || !in_array($swatch['type'] ?? null, self::TYPES, true)) {
            return null;
        }
        if ($swatch['type'] === Swatch::SWATCH_TYPE_VISUAL_IMAGE && !isset($swatch['thumbnail'])) {
            $swatch['thumbnail'] = $this->swatchMedia->getSwatchMediaUrl() . '/' . Swatch::SWATCH_THUMBNAIL_NAME . '/'
                . $this->swatchMedia->getFolderNameSize(Swatch::SWATCH_THUMBNAIL_NAME) . $swatch['value'];
        }

        return $swatch;
    }
}
