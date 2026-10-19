<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Listing;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefront\Model\Read\AttributeDocuments;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\LoadedAttributeCollectionFactory;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use Magento\Catalog\Model\Layer\FilterableAttributeListInterface;
use Magento\Catalog\Model\Layer\Search\FilterableAttributeList as SearchList;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The filterable attributes of the layered navigation from the attribute documents: the
 * category layer lists the attributes with a filterable mode, the search layer the ones
 * filterable in search and visible, both by position. The attribute models come from the
 * EAV config; `Plugin\StoreLabelFromDocuments` answers their store label from the document.
 * A layer that lists no attribute is an empty list. The store view requires attribute
 * documents and resolved EAV attributes.
 *
 * Every attribute document of the store view is read once per request, so the labels of the
 * page (the sort order, the swatches of the cards) read nothing further.
 */
class FilterableAttributesFromDocuments
{
    /** The filterable modes the category layer lists: filterable with results, and without. */
    private const FILTERABLE_MODES = [1, 2];

    public function __construct(
        private readonly AttributeDocuments $documents,
        private readonly EavConfig $eavConfig,
        private readonly Mode $mode,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoadedAttributeCollectionFactory $collectionFactory,
    ) {
    }

    /**
     * @return mixed
     */
    public function aroundGetList(FilterableAttributeListInterface $subject, \Closure $proceed)
    {
        $store = $this->storeManager->getStore();
        if (!$this->mode->listing((int)$store->getId())) {
            return $proceed();
        }
        $storeViewCode = (string)$store->getCode();
        $search = $subject instanceof SearchList;
        $documents = $this->documents->all($storeViewCode);
        if ($documents === []) {
            throw new DocumentReadException('Catalog listing requires attribute documents for store ' . $storeViewCode . '.');
        }
        $listed = array_filter(
            $documents,
            static fn (array $document) => ($document['attributeType'] ?? Product::ENTITY) === Product::ENTITY
                && ($search
                    ? !empty($document['filterableInSearch']) && !empty($document['isVisible'])
                    : in_array((int)($document['filterableMode'] ?? 0), self::FILTERABLE_MODES, true))
        );
        $order = $search ? 'searchFilterOrder' : 'categoryFilterOrder';
        foreach ($listed as $document) {
            if (!isset($document[$order])) {
                throw new DocumentReadException('Catalog listing requires ' . $order . ': ' . ($document['attributeCode'] ?? ''));
            }
        }
        usort($listed, static fn (array $a, array $b) => $a[$order] <=> $b[$order]);

        $attributes = [];
        foreach ($listed as $document) {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, (string)($document['attributeCode'] ?? ''));
            if (!$attribute->getId()) {
                throw new DocumentReadException('Catalog listing requires a resolved attribute: ' . ($document['attributeCode'] ?? ''));
            }
            $attributes[] = $attribute;
        }

        return $this->collectionFactory->create()->withItems($attributes);
    }
}
