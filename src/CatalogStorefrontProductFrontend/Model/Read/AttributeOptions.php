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
 * The options and the store label of a product attribute on a rendered page, from the attribute documents.
 *
 * The attribute document of a store view holds the store label (the store view's label, else the
 * default label) and the options the table source reads for it: the option ids with their store view
 * labels, in the position order of the options. They serve the store view of the request where it
 * serves its listings or its product pages from documents; the options only for an attribute whose
 * options are its table source's own (no source model, or the table source itself). An attribute with
 * a source model of its own, an attribute of another entity, a read for another store and a read under
 * an emulated area go to the tables: the attribute export reads the options through the table source
 * under an emulated frontend area.
 */
class AttributeOptions
{
    public function __construct(
        private readonly AttributeDocuments $documents,
        private readonly Mode $mode,
        private readonly StoreManagerInterface $storeManager,
        private readonly State $appState,
        private readonly Strict $strict,
    ) {
    }

    /**
     * The store view of the request when the attribute's document serves it.
     *
     * @param AbstractAttribute $attribute its store, null for the request's
     */
    public function served(AbstractAttribute $attribute): ?StoreInterface
    {
        if ($this->appState->isAreaCodeEmulated()
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
     * The store view of the request when the attribute's options come from its document.
     *
     * @param AbstractAttribute $attribute the attribute of the table source; its store, null for the request's
     */
    public function store(AbstractAttribute $attribute): ?StoreInterface
    {
        return in_array($attribute->getSourceModel(), [null, '', Table::class], true) ? $this->served($attribute) : null;
    }

    /**
     * Reads the documents of the product's attributes in one request, before its page asks for their
     * labels and options.
     *
     * A product page asks attribute after attribute: the label of each attribute and the label of its
     * value through the attribute's source, in the secondary info, the attributes table and the
     * swatches. The read covers every attribute of the product's attribute set.
     *
     * @throws \GraphCommerce\CatalogStorefront\Model\DocumentReadException when the store cannot be read
     */
    public function prime(Product $product): void
    {
        $store = null;
        $codes = [];
        foreach ($product->getAttributes() as $attribute) {
            $attributeStore = $this->served($attribute);
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
        // The export writes no options for an attribute without any.
        return array_map(
            static fn(array $option): array => ['value' => (string)$option['id'], 'label' => (string)$option['label']],
            array_values((array)($this->document($store, $code)['options'] ?? []))
        );
    }

    /**
     * The store label: the store view's label of the attribute, else its default label.
     *
     * @throws \GraphCommerce\CatalogStorefront\Model\DocumentReadException when the attribute has no document in the
     *         store view, the document has no label, or the store cannot be read
     */
    public function label(StoreInterface $store, string $code): string
    {
        $label = $this->document($store, $code)['label'] ?? null;
        if ($label === null) {
            // The export drops a null label; an attribute without a default label never has one
            // here, and a document without it says nothing about the attribute.
            $this->strict->fallback(self::class, sprintf(
                'The document of attribute "%s" in store view "%s" has no label',
                $code,
                (string)$store->getCode()
            ));
        }

        return (string)$label;
    }

    /**
     * @throws \GraphCommerce\CatalogStorefront\Model\DocumentReadException when the attribute has no document in the
     *         store view or the store cannot be read
     */
    private function document(StoreInterface $store, string $code): array
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

        return $document;
    }
}
