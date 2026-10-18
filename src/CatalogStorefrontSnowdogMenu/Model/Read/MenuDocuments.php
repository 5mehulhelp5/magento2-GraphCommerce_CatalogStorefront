<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Model\Read;

use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\MenuDocument;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
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
     * @return array<int, array{menu: array<string, string|null>, storeIds: int[], nodes: array<int, array<string, string|null>>, cmsPages: array[], cmsBlocks: array[]}>
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
                        || !is_array($document[MenuDocument::CMS_PAGES] ?? null)
                        || !is_array($document[MenuDocument::CMS_BLOCKS] ?? null)
                    ) {
                        throw new \UnexpectedValueException(sprintf(
                            'The document of menu %s in store view "%s" has no %s: run bin/magento catalog-storefront:rebuild %s',
                            $menuId,
                            $storeViewCode,
                            implode(', ', [
                                MenuDocument::MENU,
                                MenuDocument::STORE_IDS,
                                MenuDocument::NODES,
                                MenuDocument::CMS_PAGES,
                                MenuDocument::CMS_BLOCKS,
                            ]),
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

    /**
     * The CMS rows of the nodes of the store view's own store and of all store views (store 0), in
     * store order: the rows core reads for them, so of two rows of one identifier the later one is the
     * one core keeps.
     *
     * @param \Magento\Framework\DataObject[] $nodes the menu nodes, with their menu id
     * @param string $kind MenuDocument::CMS_PAGES or MenuDocument::CMS_BLOCKS
     * @return array<int, array<string, int|string|null>>
     * @throws \GraphCommerce\CatalogStorefront\Model\DocumentReadException when a node's menu has no document
     */
    public function cmsRows(StoreInterface $store, array $nodes, string $kind): array
    {
        $storeViewCode = (string)$store->getCode();
        $menus = $this->menus($storeViewCode);
        $stores = [Store::DEFAULT_STORE_ID, (int)$store->getId()];
        $rows = [];
        foreach ($nodes as $node) {
            $menu = $menus[(int)$node->getData('menu_id')] ?? null;
            if ($menu === null) {
                $this->strict->fallback(self::class, sprintf(
                    'Menu %d of node %d has no document in store view "%s"',
                    (int)$node->getData('menu_id'),
                    (int)$node->getId(),
                    $storeViewCode
                ));
            }
            foreach ($menu[$kind] as $row) {
                if ($row['nodeId'] === (int)$node->getId() && in_array($row['storeId'], $stores, true)) {
                    $rows[] = $row;
                }
            }
        }
        usort($rows, static fn(array $a, array $b): int => $a['storeId'] <=> $b['storeId']);

        return $rows;
    }

    public function _resetState(): void
    {
        $this->menus = [];
    }
}
