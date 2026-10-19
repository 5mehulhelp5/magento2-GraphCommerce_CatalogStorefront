<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Model\Mview;

use GraphCommerce\CatalogStorefront\Model\Mview\LookupSubscription;

/**
 * Marks the menus of a row that names no menu: the menu of a node whose customer groups change, and
 * the menus whose CMS page or CMS block nodes name a page or block that changes.
 *
 * A row of snowmenu_customer names a node, and a node save that changes only its groups leaves its
 * row in snowmenu_node as it was. A CMS node names its page or block by identifier, and a page or
 * block save writes the page or block and its store rows only. The trigger looks the menus up in
 * snowmenu_node, compares an identifier through the collation of the columns as the node types do,
 * and adds nothing for a row whose node is gone. An update looks up the old and the new value, so a
 * changed identifier or store marks the menus of both. A view linked to the same table keeps the
 * statement of the base class.
 */
class MenuLookupSubscription extends LookupSubscription
{
    /**
     * Per table, the select of the menu ids up to the expression the row's value is compared with,
     * and the column of the row that holds the value. Tables are named in braces.
     */
    private const LOOKUPS = [
        'snowmenu_customer' => [
            'SELECT node.menu_id FROM {snowmenu_node} node WHERE node.node_id',
            'node_id',
        ],
        'cms_page' => [
            "SELECT node.menu_id FROM {snowmenu_node} node WHERE node.type = 'cms_page' AND node.content",
            'identifier',
        ],
        'cms_page_store' => [
            "SELECT node.menu_id FROM {snowmenu_node} node JOIN {cms_page} page ON page.identifier = node.content"
            . " WHERE node.type = 'cms_page' AND page.page_id",
            'page_id',
        ],
        'cms_block' => [
            "SELECT node.menu_id FROM {snowmenu_node} node WHERE node.type = 'cms_block' AND node.content",
            'identifier',
        ],
        'cms_block_store' => [
            "SELECT node.menu_id FROM {snowmenu_node} node JOIN {cms_block} block ON block.identifier = node.content"
            . " WHERE node.type = 'cms_block' AND block.block_id",
            'block_id',
        ],
    ];

    /**
     * @param string $tableName
     * @return string[]
     * @throws \LogicException when the table has no menu lookup
     */
    protected function lookup(string $tableName): array
    {
        return self::LOOKUPS[$tableName]
            ?? throw new \LogicException(sprintf('The table %s has no menu lookup', $tableName));
    }
}
