<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Model\Read;

use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\MenuDocument;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The menu documents a rendered page reads, held for the request.
 *
 * A store view that serves its listings or its product pages from documents reads its menus here;
 * a store view served from the database, and the core path of the parity gate, read the menu tables.
 * Every menu block of a page reads its menu, its nodes and its category nodes, so the menu documents
 * of the store view are read once, all of them.
 *
 * The marker document of the store view comes with the read: with it, a menu without a document is
 * not active in the store view, as the tables would say. A store view without the marker, a document
 * of another shape and a storage error stop the read and are logged.
 */
class MenuDocuments implements ResetAfterRequestInterface
{
    /** @var array<string, array<int, array>> per store view, the menu documents by menu id in id order */
    private array $menus = [];

    public function __construct(
        private readonly MetadataDocumentStorageInterface $storage,
        private readonly Mode $mode,
        private readonly StoreManagerInterface $storeManager,
        private readonly Strict $strict,
    ) {
    }

    /**
     * The store view of the request when it reads its menus from the documents.
     */
    public function store(): ?StoreInterface
    {
        $store = $this->storeManager->getStore();
        $storeId = (int)$store->getId();

        return $this->mode->listing($storeId) || $this->mode->detail($storeId) ? $store : null;
    }

    /**
     * @return array<int, array{menu: array<string, string|null>, storeIds: int[], nodes: array<int, array<string, string|null>>}>
     *         the active menus of the store view and of all store views, by menu id in id order
     * @throws \GraphCommerce\CatalogStorefront\Model\DocumentReadException when the store view has no menu
     *         documents, a document has another shape or the store cannot be read
     */
    public function menus(string $storeViewCode): array
    {
        if (!isset($this->menus[$storeViewCode])) {
            try {
                $documents = $this->storage->all(MenuDocument::ENTITY, $storeViewCode);
                if (!isset($documents[MenuDocument::MARKER])) {
                    throw new \UnexpectedValueException(sprintf(
                        'Store view "%s" has no menu documents: run bin/magento catalog-storefront:rebuild %s',
                        $storeViewCode,
                        MenuDocument::ENTITY
                    ));
                }
                unset($documents[MenuDocument::MARKER]);
                foreach ($documents as $menuId => $document) {
                    if (!is_string($document[MenuDocument::MENU]['identifier'] ?? null)
                        || !is_array($document[MenuDocument::STORE_IDS] ?? null)
                        || !is_array($document[MenuDocument::NODES] ?? null)
                    ) {
                        throw new \UnexpectedValueException(sprintf(
                            'The document of menu %s in store view "%s" has no %s, %s or %s: run bin/magento catalog-storefront:rebuild %s',
                            $menuId,
                            $storeViewCode,
                            MenuDocument::MENU,
                            MenuDocument::STORE_IDS,
                            MenuDocument::NODES,
                            MenuDocument::ENTITY
                        ));
                    }
                }
                ksort($documents);
                $this->menus[$storeViewCode] = $documents;
            } catch (\Throwable $e) {
                $this->strict->exception(self::class, $e);
            }
        }

        return $this->menus[$storeViewCode];
    }

    public function _resetState(): void
    {
        $this->menus = [];
    }
}
