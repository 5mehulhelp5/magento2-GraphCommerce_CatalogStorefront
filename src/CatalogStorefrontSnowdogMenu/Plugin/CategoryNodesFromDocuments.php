<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Plugin;

use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontCategoryFrontend\Model\Read\CategoryDocuments;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\Read\MenuDocuments;
use GraphCommerce\CatalogStorefrontUrlRewrite\Model\Collation;
use GraphCommerce\CatalogStorefrontUrlRewrite\Model\Read\UrlRewriteRows;
use GraphCommerce\CatalogStorefrontUrlRewrite\Model\UrlRewriteDocument;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Snowdog\Menu\Model\NodeType\Category;

/**
 * Answers the data of the category nodes of a menu from the documents.
 *
 * For the categories of its nodes, core reads the request paths of their url rewrites (category, no
 * redirect, the store view), loads the categories with every attribute and counts their rows in the
 * category product index of the store view. The url rewrite documents hold the rewrites, the category
 * documents the categories and that count (`menuProductCount`). The answer has the shape core's has:
 * the urls and the counts only of the categories that have one, the categories by id, a cache tag per
 * node.
 *
 * A category without a document in the store view, or a document without the count, stops the read.
 */
class CategoryNodesFromDocuments
{
    private const ENTITY_TYPE = 'category';

    private const COUNT = 'menuProductCount';

    public function __construct(
        private readonly MenuDocuments $documents,
        private readonly CategoryDocuments $categories,
        private readonly UrlRewriteRows $urlRewriteRows,
        private readonly Collation $collation,
        private readonly Strict $strict,
    ) {
    }

    /**
     * @param Category $subject
     * @param \Closure $proceed
     * @param \Snowdog\Menu\Model\Menu\Node[] $nodes
     * @param int|string $storeId
     * @return array{0: array, 1: array<int, string>, 2: array<int, \Magento\Catalog\Model\Category>, 3: array<int, string>, 4: string[]}
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundFetchData(Category $subject, \Closure $proceed, array $nodes, $storeId)
    {
        $store = $this->documents->store();
        if ($store === null || (int)$storeId !== (int)$store->getId()) {
            return $proceed($nodes, $storeId);
        }
        $storeViewCode = (string)$store->getCode();

        $localNodes = [];
        $categoryIds = [];
        foreach ($nodes as $node) {
            $localNodes[$node->getId()] = $node;
            $categoryIds[] = (int)$node->getContent();
        }
        $ids = array_values(array_unique($categoryIds));
        sort($ids);

        $urls = [];
        $counts = [];
        try {
            $documents = $this->categories->documents($storeViewCode, $ids);
            $missing = array_diff($ids, array_keys($documents));
            if ($missing !== []) {
                throw new \UnexpectedValueException(sprintf(
                    'Categories %s of a menu have no document in store view "%s"',
                    implode(', ', $missing),
                    $storeViewCode
                ));
            }
            foreach ($documents as $id => $document) {
                if (!array_key_exists(self::COUNT, $document)) {
                    throw new \UnexpectedValueException(sprintf(
                        'The document of category %d in store view "%s" has no %s: run bin/magento catalog-storefront:rebuild category',
                        $id,
                        $storeViewCode,
                        self::COUNT
                    ));
                }
                if ((int)$document[self::COUNT] > 0) {
                    $counts[$id] = (string)(int)$document[self::COUNT];
                }
            }
            // The rows come in id order, as the table yields them, so the last row of a category wins as in core's pairs.
            foreach ($this->urlRewriteRows->rows($storeViewCode, [[
                UrlRewrite::ENTITY_TYPE . UrlRewriteDocument::KEY_SUFFIX => $this->collation->fold(self::ENTITY_TYPE),
                UrlRewrite::REDIRECT_TYPE => '0',
                UrlRewrite::ENTITY_ID => array_map('strval', $ids),
            ]]) as $row) {
                $urls[(int)$row[UrlRewrite::ENTITY_ID]] = $row[UrlRewrite::REQUEST_PATH];
            }
            $categories = $this->categories->models($storeViewCode, (int)$storeId, $ids);
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);
        }

        return [$localNodes, $urls, $categories, $counts, preg_filter('/^/', 'cat_c_p_', $categoryIds)];
    }
}
