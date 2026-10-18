<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Plugin;

use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\MenuDocument;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\Read\MenuDocuments;
use GraphCommerce\CatalogStorefrontUrlRewrite\Model\Collation;
use GraphCommerce\CatalogStorefrontUrlRewrite\Model\Read\UrlRewriteRows;
use GraphCommerce\CatalogStorefrontUrlRewrite\Model\UrlRewriteDocument;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Snowdog\Menu\Model\NodeType\CmsPage;

/**
 * Answers the data of the CMS page nodes of a menu from the menu documents and the url rewrite
 * documents.
 *
 * Core reads the id of the active page each node names in the store view or in all store views,
 * the store view's own page last, then the request paths of the pages' url rewrites, and for a page
 * whose identifier is not among those request paths, its identifier. The menu document holds the
 * pages of every store, the url rewrite documents the request paths. The answer has the shape
 * core's has: the page ids by identifier and the urls by page id.
 */
class CmsPageNodesFromDocuments
{
    private const ENTITY_TYPE = 'cms-page';

    public function __construct(
        private readonly MenuDocuments $documents,
        private readonly UrlRewriteRows $urlRewriteRows,
        private readonly Collation $collation,
        private readonly Strict $strict,
    ) {
    }

    /**
     * @param CmsPage $subject
     * @param \Closure $proceed
     * @param \Snowdog\Menu\Model\Menu\Node[] $nodes
     * @param int|string $storeId
     * @return array{0: array, 1: array<string, string>, 2: array<int, string>}
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundFetchData(CmsPage $subject, \Closure $proceed, array $nodes, $storeId)
    {
        $store = $this->documents->store();
        if ($store === null || (int)$storeId !== (int)$store->getId()) {
            return $proceed($nodes, $storeId);
        }
        $storeViewCode = (string)$store->getCode();

        $localNodes = [];
        foreach ($nodes as $node) {
            $localNodes[$node->getId()] = $node;
        }
        $rows = [];
        $pageIds = [];
        $urls = [];
        try {
            $rows = $this->documents->cmsRows($store, $nodes, MenuDocument::CMS_PAGES);
            foreach ($rows as $row) {
                $pageIds[$row['identifier']] = $row['pageId'];
            }
            if ($pageIds !== []) {
                // The rows come in id order, as the table yields them, so the last row of a page wins as in core's pairs.
                foreach ($this->urlRewriteRows->rows($storeViewCode, [[
                    UrlRewrite::ENTITY_TYPE . UrlRewriteDocument::KEY_SUFFIX => $this->collation->fold(self::ENTITY_TYPE),
                    UrlRewrite::ENTITY_ID => array_values(array_unique($pageIds)),
                ]]) as $row) {
                    $urls[(int)$row[UrlRewrite::ENTITY_ID]] = $row[UrlRewrite::REQUEST_PATH];
                }
            }
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);
        }

        $missing = array_diff_key($pageIds, array_flip($urls));
        $identifiers = [];
        foreach ($rows as $row) {
            if (in_array($row['pageId'], $missing, true)) {
                $identifiers[(int)$row['pageId']] = $row['identifier'];
            }
        }

        return [$localNodes, $pageIds, $urls + $identifiers];
    }
}
