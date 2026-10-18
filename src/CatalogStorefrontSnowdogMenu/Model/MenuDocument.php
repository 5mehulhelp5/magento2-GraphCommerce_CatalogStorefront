<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Model;

/**
 * The menu documents of a store view.
 *
 * A store view holds a document for every active menu of the store view or of all store views (store
 * 0), keyed by menu id: `menu`, the menu row as core's collection loads it; `storeIds`, the store ids
 * the menu is assigned to; `nodes`, the rows of its nodes in the order the node repository reads them,
 * each with `customer_groups`, the comma separated group ids the node is limited to, as core's
 * collection joins them, or null. Every row is keyed by column, a null column as null.
 * `cmsPages` and `cmsBlocks` list, per CMS page or CMS block node, the active pages and blocks
 * whose identifier the node names, as the database compares it, once per store the page or block
 * is assigned to: the rows the node types read, which the reader narrows to the store view.
 *
 * Document id 0 is the marker of the store view: the menu feed writes it with every batch and the
 * rebuild seeds it, so a store view that holds it has its menu documents.
 */
class MenuDocument
{
    public const ENTITY = 'snowdog_menu';

    public const MARKER = 0;

    public const MARKER_DOCUMENT = [
        self::MENU => [],
        self::STORE_IDS => [],
        self::NODES => [],
        self::CMS_PAGES => [],
        self::CMS_BLOCKS => [],
    ];

    public const MENU = 'menu';

    public const STORE_IDS = 'storeIds';

    public const NODES = 'nodes';

    public const CMS_PAGES = 'cmsPages';

    public const CMS_BLOCKS = 'cmsBlocks';

    public const CUSTOMER_GROUPS = 'customer_groups';

    /** The table column of each field of a menu feed row. */
    public const MENU_COLUMNS = [
        'menuId' => 'menu_id',
        'title' => 'title',
        'identifier' => 'identifier',
        'creationTime' => 'creation_time',
        'updateTime' => 'update_time',
        'isActive' => 'is_active',
        'cssClass' => 'css_class',
    ];

    /** The table column of each field of a node of a menu feed row. */
    public const NODE_COLUMNS = [
        'nodeId' => 'node_id',
        'menuId' => 'menu_id',
        'type' => 'type',
        'content' => 'content',
        'classes' => 'classes',
        'parentId' => 'parent_id',
        'position' => 'position',
        'level' => 'level',
        'title' => 'title',
        'creationTime' => 'creation_time',
        'updateTime' => 'update_time',
        'isActive' => 'is_active',
        'target' => 'target',
        'submenuTemplate' => 'submenu_template',
        'nodeTemplate' => 'node_template',
        'image' => 'image',
        'imageAltText' => 'image_alt_text',
        'selectedItemId' => 'selected_item_id',
        'imageWidth' => 'image_width',
        'imageHeight' => 'image_heigth',
        'hideIfEmpty' => 'hide_if_empty',
    ];
}
