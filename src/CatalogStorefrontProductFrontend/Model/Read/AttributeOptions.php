<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Model\Read;

use GraphCommerce\CatalogStorefront\Model\Read\AttributeDocuments;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\Entity\Attribute\Source\Table;
use Magento\Framework\App\State;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The options of a product attribute's table source on a rendered page, from the attribute documents.
 *
 * The attribute document of a store view holds the options the table source reads for it: the
 * option ids with their store view labels, in the position order of the options. They serve the
 * store view of the request where it serves its listings or its product pages from documents, for an
 * attribute whose options are its table source's own (no source model, or the table source itself).
 * An attribute with a source model of its own, an attribute of another entity, a read for another
 * store and a read under an emulated area go to the tables: the attribute export reads the options
 * through the table source under an emulated frontend area.
 */
class AttributeOptions
{
    /** The frontend inputs whose value the frontend reads through the attribute's source. */
    private const SOURCE_INPUTS = ['select', 'multiselect', 'boolean'];

    public function __construct(
        private readonly AttributeDocuments $documents,
        private readonly Mode $mode,
        private readonly StoreManagerInterface $storeManager,
        private readonly State $appState,
        private readonly Strict $strict,
    ) {
    }

    /**
     * The store view of the request when the attribute's options come from its document.
     *
     * @param AbstractAttribute $attribute the attribute of the table source; its store, null for the request's
     */
    public function store(AbstractAttribute $attribute): ?StoreInterface
    {
        $sourceModel = $attribute->getSourceModel();
        if ($this->appState->isAreaCodeEmulated()
            || !in_array($sourceModel, [null, '', Table::class], true)
            || $attribute->getEntityType()->getEntityTypeCode() !== Product::ENTITY
        ) {
            return null;
        }
        $store = $this->storeManager->getStore();
        $storeId = (int)$store->getId();
        if ($attribute->getStoreId() !== null && (int)$attribute->getStoreId() !== $storeId) {
            return null;
        }

        return $this->mode->listing($storeId) || $this->mode->detail($storeId) ? $store : null;
    }

    /**
     * Reads the documents of the product's attributes in one request, before its page asks for their options.
     *
     * A product page asks the source of an attribute for the label of its value attribute after
     * attribute: the secondary info, the attributes table, the swatches. The read covers every
     * attribute of the product's attribute set whose frontend asks the source: a select, a
     * multiselect and a boolean, which without a source model of its own has the table source too.
     *
     * @throws \GraphCommerce\CatalogStorefront\Model\DocumentReadException when the store cannot be read
     */
    public function prime(Product $product): void
    {
        $store = null;
        $codes = [];
        foreach ($product->getAttributes() as $attribute) {
            if (!in_array($attribute->getFrontendInput(), self::SOURCE_INPUTS, true)) {
                continue;
            }
            $attributeStore = $this->store($attribute);
            if ($attributeStore !== null) {
                $store = $attributeStore;
                $codes[] = (string)$attribute->getAttributeCode();
            }
        }
        if ($store === null) {
            return;
        }
        try {
            $this->documents->byCodes((string)$store->getCode(), $codes);
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);
        }
    }

    /**
     * @return array<int, array{value: string, label: string}> the options, in the position order of the options
     * @throws \GraphCommerce\CatalogStorefront\Model\DocumentReadException when the attribute has no document in the
     *         store view or the store cannot be read
     */
    public function options(StoreInterface $store, string $code): array
    {
        $storeViewCode = (string)$store->getCode();
        try {
            $document = $this->documents->byCodes($storeViewCode, [$code])[$code] ?? null;
            if ($document === null) {
                throw new \UnexpectedValueException(sprintf(
                    'Attribute "%s" has no document in store view "%s": run bin/magento catalog-storefront:rebuild attribute',
                    $code,
                    $storeViewCode
                ));
            }
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);
        }

        // The export writes no options for an attribute without any.
        return array_map(
            static fn(array $option): array => ['value' => (string)$option['id'], 'label' => (string)$option['label']],
            array_values((array)($document['options'] ?? []))
        );
    }
}
