<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Plugin;

use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\MenuDocument;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\Read\MenuDocuments;
use Snowdog\Menu\Model\NodeType\CmsBlock;

/**
 * Answers the content of the CMS block nodes of a menu from the menu documents.
 *
 * Core reads the content of the active block each node names in the store view or in all store
 * views, the store view's own block last. The menu document holds the blocks of every store. The
 * node block runs the content through the block filter as before, so a directive in it, such as a
 * widget, runs what it runs.
 */
class CmsBlockNodesFromDocuments
{
    public function __construct(
        private readonly MenuDocuments $documents,
        private readonly Strict $strict,
    ) {
    }

    /**
     * @param CmsBlock $subject
     * @param \Closure $proceed
     * @param \Snowdog\Menu\Model\Menu\Node[] $nodes
     * @param int|string $storeId
     * @return array{0: array, 1: array<string, string|null>}
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundFetchData(CmsBlock $subject, \Closure $proceed, array $nodes, $storeId)
    {
        $store = $this->documents->store();
        if ($store === null || (int)$storeId !== (int)$store->getId()) {
            return $proceed($nodes, $storeId);
        }

        $localNodes = [];
        foreach ($nodes as $node) {
            $localNodes[$node->getId()] = $node;
        }
        $content = [];
        try {
            foreach ($this->documents->cmsRows($store, $nodes, MenuDocument::CMS_BLOCKS) as $row) {
                $content[$row['identifier']] = $row['content'];
            }
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);
        }

        return [$localNodes, $content];
    }
}
