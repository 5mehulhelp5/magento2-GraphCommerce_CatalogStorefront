<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Plugin;

use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\MenuDocument;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\Read\MenuDocuments;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Snowdog\Menu\Api\NodeRepositoryInterface;
use Snowdog\Menu\Block\Menu as MenuBlock;
use Snowdog\Menu\Model\Menu\Node;
use Snowdog\Menu\Model\Menu\NodeFactory;

/**
 * Answers the nodes of a menu from its menu document.
 *
 * Core reads the nodes of the menu by level, parent and position, keyed by node id, and joins the
 * customer groups of each node when the menu limits nodes to customer groups
 * (`snowmenu/general/customer_groups`). The document holds the node rows in that order with their
 * groups. A menu block of a store view without its menu asks for the nodes of no menu, which no row
 * has. A menu the documents of the store view do not hold stops the read: every menu a page renders
 * comes from them.
 */
class NodesFromDocuments
{
    public function __construct(
        private readonly MenuDocuments $documents,
        private readonly NodeFactory $nodeFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Strict $strict,
    ) {
    }

    /**
     * @param NodeRepositoryInterface $subject
     * @param \Closure $proceed
     * @param int|string|null $menuId
     * @return Node[] by node id
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundGetByMenu(NodeRepositoryInterface $subject, \Closure $proceed, $menuId)
    {
        $store = $this->documents->store();
        if ($store === null) {
            return $proceed($menuId);
        }
        if ((int)$menuId <= 0) {
            return [];
        }
        $storeViewCode = (string)$store->getCode();
        $document = $this->documents->menus($storeViewCode)[(int)$menuId] ?? null;
        if ($document === null) {
            $this->strict->fallback(self::class, sprintf(
                'Menu %d has no document in store view "%s"',
                (int)$menuId,
                $storeViewCode
            ));
        }

        $groups = $this->scopeConfig->isSetFlag(MenuBlock::XML_SNOWMENU_GENERAL_CUSTOMER_GROUPS);
        $nodes = [];
        foreach ($document[MenuDocument::NODES] as $row) {
            if (!$groups) {
                unset($row[MenuDocument::CUSTOMER_GROUPS]);
            }
            $node = $this->nodeFactory->create();
            $node->addData($row);
            $node->setOrigData();
            $nodes[(int)$row['node_id']] = $node;
        }

        return $nodes;
    }
}
