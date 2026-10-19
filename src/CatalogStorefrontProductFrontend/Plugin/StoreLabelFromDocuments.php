<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Plugin;

use GraphCommerce\CatalogStorefrontProductFrontend\Model\Read\AttributeOptions;
use Magento\Eav\Model\Entity\Attribute;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Answers a product attribute's store label on a rendered page from its document.
 *
 * Every label a page shows asks getStoreLabel(): the attributes table and the secondary info of a
 * product page, its swatches and options, the sort order of a listing, the swatches of its cards
 * and its layered navigation. Core keeps the labels it read on the attribute for the life of the
 * process, so under an application server a changed label shows only once the worker restarts; the
 * document follows the change with the attributes feed. A label for another store goes to core.
 */
class StoreLabelFromDocuments
{
    public function __construct(
        private readonly AttributeOptions $attributes,
    ) {
    }

    /**
     * @param Attribute $subject
     * @param \Closure $proceed
     * @param int|string|StoreInterface|null $storeId
     * @return string|null
     */
    public function aroundGetStoreLabel(Attribute $subject, \Closure $proceed, $storeId = null)
    {
        $store = $this->attributes->served($subject);
        $asked = $storeId instanceof StoreInterface ? $storeId->getId() : $storeId;
        if ($store === null || ($asked !== null && (string)$asked !== (string)$store->getId())) {
            return $proceed($storeId);
        }

        return $this->attributes->label($store, (string)$subject->getAttributeCode());
    }
}
