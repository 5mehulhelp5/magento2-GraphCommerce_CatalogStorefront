<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Model\Document\Writer;

use GraphCommerce\CatalogStorefront\Model\Document\Scopes;
use GraphCommerce\CatalogStorefrontApi\Document\FeedWriterInterface;
use GraphCommerce\CatalogStorefrontApi\Document\RebuildSeederInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\MenuDocument;
use Magento\Store\Model\Store;

/**
 * Writes the menu feed rows as menu documents of the store views a menu serves: an active menu
 * assigned to the store view or to all store views. A menu that is inactive, not assigned to the
 * store view or deleted loses its document there. Every batch writes the marker of every store view,
 * and a rebuild seeds it, so a store view without a single menu still has its menu documents.
 */
class Menus implements FeedWriterInterface, RebuildSeederInterface
{
    public function __construct(
        private readonly MetadataDocumentStorageInterface $storage,
        private readonly Scopes $scopes,
    ) {
    }

    /**
     * @param array[] $rows the feed batch as the exporter hands it over
     * @throws \RuntimeException when a row has no menuId
     */
    public function write(array $rows): void
    {
        $stores = $this->scopes->storeViews();
        $upserts = [];
        $deletes = [];
        foreach ($rows as $row) {
            $id = (int)($row['menuId'] ?? 0);
            if ($id <= 0) {
                throw new \RuntimeException('A menu feed row has no menuId');
            }
            $assigned = array_values((array)($row['stores'] ?? []));
            $storeIds = array_map(static fn(array $store): int => (int)$store['storeId'], $assigned);
            $codes = array_column($assigned, 'storeViewCode');
            $nodes = array_values((array)($row['nodes'] ?? []));
            $document = empty($row['deleted']) && (int)($row['isActive'] ?? 0) === 1 ? [
                MenuDocument::MENU => $this->columns($row, MenuDocument::MENU_COLUMNS),
                MenuDocument::STORE_IDS => $storeIds,
                MenuDocument::NODES => array_map(
                    function (array $node): array {
                        $groups = array_column((array)($node['customerGroups'] ?? []), 'groupId');

                        return $this->columns($node, MenuDocument::NODE_COLUMNS)
                            + [MenuDocument::CUSTOMER_GROUPS => $groups === [] ? null : implode(',', $groups)];
                    },
                    $nodes
                ),
                // Lists, not maps keyed by node: an upsert replaces a list, where it merges a map.
                MenuDocument::CMS_PAGES => array_merge([], ...array_map(
                    static fn(array $node): array => array_map(
                        static fn(array $page): array => [
                            'nodeId' => (int)$page['nodeId'],
                            'pageId' => (string)$page['pageId'],
                            'identifier' => (string)$page['identifier'],
                            'storeId' => (int)$page['storeId'],
                        ],
                        array_values((array)($node['cmsPages'] ?? []))
                    ),
                    $nodes
                )),
                MenuDocument::CMS_BLOCKS => array_merge([], ...array_map(
                    static fn(array $node): array => array_map(
                        static fn(array $block): array => [
                            'nodeId' => (int)$block['nodeId'],
                            'identifier' => (string)$block['identifier'],
                            'content' => $block['content'] ?? null,
                            'storeId' => (int)$block['storeId'],
                        ],
                        array_values((array)($node['cmsBlocks'] ?? []))
                    ),
                    $nodes
                )),
            ] : null;
            foreach ($stores as $store) {
                if ($document !== null
                    && (in_array(Store::DEFAULT_STORE_ID, $storeIds, true) || in_array($store, $codes, true))
                ) {
                    $upserts[$store][$id] = $document;
                } else {
                    $deletes[$store][] = $id;
                }
            }
        }

        foreach ($stores as $store) {
            $this->storage->upsert(
                MenuDocument::ENTITY,
                $store,
                [MenuDocument::MARKER => MenuDocument::MARKER_DOCUMENT] + ($upserts[$store] ?? [])
            );
            if (isset($deletes[$store])) {
                $this->storage->delete(MenuDocument::ENTITY, $store, $deletes[$store]);
            }
        }
    }

    public function seed(string $storeViewCode): void
    {
        $this->storage->upsert(MenuDocument::ENTITY, $storeViewCode, [MenuDocument::MARKER => MenuDocument::MARKER_DOCUMENT]);
    }

    /**
     * The row keyed by column; the exporter leaves a null column out of the row.
     *
     * @param array<string, mixed> $row
     * @param array<string, string> $columns the column of each field
     * @return array<string, mixed>
     */
    private function columns(array $row, array $columns): array
    {
        $values = [];
        foreach ($columns as $field => $column) {
            $values[$column] = $row[$field] ?? null;
        }

        return $values;
    }
}
