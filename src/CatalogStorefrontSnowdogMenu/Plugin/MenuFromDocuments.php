<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Plugin;

use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\MenuDocument;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\Read\MenuDocuments;
use Magento\Store\Model\Store;
use Snowdog\Menu\Api\MenuRepositoryInterface;
use Snowdog\Menu\Model\Menu;
use Snowdog\Menu\Model\MenuFactory;

/**
 * Answers the menu of an identifier for the store view of the request, or for all store views
 * (store 0), from the menu documents.
 *
 * Core reads the active menu whose identifier matches byte for byte and that is assigned to the store
 * asked for, with the store id of the assignment, and a menu block asks again for store 0 where that
 * read finds none. The documents hold every active menu of both, so a menu they do not name is the
 * empty menu core's read gives. Of two menus that match, the one with the lowest id answers, as the
 * table's primary key yields them. A read for another store view goes to the table.
 */
class MenuFromDocuments
{
    public function __construct(
        private readonly MenuDocuments $documents,
        private readonly MenuFactory $menuFactory,
    ) {
    }

    /**
     * @param MenuRepositoryInterface $subject
     * @param \Closure $proceed
     * @param string $identifier
     * @param int|string $storeId
     * @return Menu
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundGet(MenuRepositoryInterface $subject, \Closure $proceed, $identifier, $storeId)
    {
        $store = $this->documents->store();
        if ($store === null || !in_array((int)$storeId, [Store::DEFAULT_STORE_ID, (int)$store->getId()], true)) {
            return $proceed($identifier, $storeId);
        }

        $menu = $this->menuFactory->create();
        foreach ($this->documents->menus((string)$store->getCode()) as $document) {
            if ($document[MenuDocument::MENU]['identifier'] === (string)$identifier
                && in_array((int)$storeId, $document[MenuDocument::STORE_IDS], true)
            ) {
                $menu->addData($document[MenuDocument::MENU] + ['store_id' => (string)(int)$storeId]);
                $menu->setOrigData();
                break;
            }
        }

        return $menu;
    }
}
