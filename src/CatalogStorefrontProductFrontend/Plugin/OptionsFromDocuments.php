<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Plugin;

use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Read\AttributeOptions;
use Magento\Eav\Model\Entity\Attribute\Source\Table;

/**
 * Answers the options of a product attribute's table source on a rendered page from its document.
 *
 * Core reads the options of the store view in position order for every getSpecificOptions() call,
 * which getOptionText() makes for each value a page shows (the attributes of the product page, the
 * secondary info), and once per request for getAllOptions() (the swatches). The answer has core's
 * shape: value and label, the empty option first where asked. The admin labels of getAllOptions()
 * with default values are not in the documents, and only the admin asks for them: that read stops.
 */
class OptionsFromDocuments
{
    private const EMPTY_OPTION = ['label' => ' ', 'value' => ''];

    public function __construct(
        private readonly AttributeOptions $options,
        private readonly Strict $strict,
    ) {
    }

    /**
     * @param Table $subject
     * @param \Closure $proceed
     * @param bool $withEmpty
     * @param bool $defaultValues
     * @return array
     */
    public function aroundGetAllOptions(Table $subject, \Closure $proceed, $withEmpty = true, $defaultValues = false)
    {
        $attribute = $subject->getAttribute();
        $store = $this->options->store($attribute);
        if ($store === null) {
            return $proceed($withEmpty, $defaultValues);
        }
        if ($defaultValues) {
            $this->strict->fallback(self::class, sprintf(
                'The admin labels of attribute "%s" are not in its document',
                $attribute->getAttributeCode()
            ));
        }
        $options = $this->options->options($store, (string)$attribute->getAttributeCode());

        return $withEmpty ? [self::EMPTY_OPTION, ...$options] : $options;
    }

    /**
     * @param Table $subject
     * @param \Closure $proceed
     * @param array|string|int $ids
     * @param bool $withEmpty
     * @return array
     */
    public function aroundGetSpecificOptions(Table $subject, \Closure $proceed, $ids, $withEmpty = true)
    {
        $attribute = $subject->getAttribute();
        $store = $this->options->store($attribute);
        if ($store === null) {
            return $proceed($ids, $withEmpty);
        }
        $wanted = array_flip(array_map('strval', (array)$ids));
        $options = array_values(array_filter(
            $this->options->options($store, (string)$attribute->getAttributeCode()),
            static fn(array $option): bool => isset($wanted[$option['value']])
        ));

        return $withEmpty ? [self::EMPTY_OPTION, ...$options] : $options;
    }
}
