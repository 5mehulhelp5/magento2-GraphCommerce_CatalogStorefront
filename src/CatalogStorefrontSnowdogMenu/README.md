# GraphCommerce_CatalogStorefrontSnowdogMenu

The menus of Snowdog_Menu as documents, and the menu reads of a rendered page from them: the menu,
its nodes and the data of its category nodes. It acts in a store view that serves its listings or
its product pages from documents (`serve_plp`, `serve_pdp`, or the `X-Catalog-Storefront` header of
GraphCommerce_CatalogStorefrontProductFrontend). Frontend area only; the admin and GraphQL read the
menu tables.

## Installation

The module registers itself only where Snowdog_Menu's code is installed (`registration.php` checks
for `Snowdog\Menu\Api\MenuRepositoryInterface`): its plugins, its feed and its triggers name
Snowdog's classes and tables, so on a shop without Snowdog it does not exist for Magento, and
`module:status` does not list it. After Snowdog is installed, `setup:upgrade` finds and enables the
module; then run `bin/magento catalog-storefront:rebuild snowdog_menu category`. To keep Snowdog on
the tables, `bin/magento module:disable GraphCommerce_CatalogStorefrontSnowdogMenu`.

Snowdog's code without its tables (installed but never set up) still registers the module, and the
menu feed then fails on the missing tables.

## Write

- The feed `snowdogMenus` (indexer `catalog_storefront_snowdog_menus`, feed table
  `catalog_storefront_snowdog_menu_feed`, entity `snowdog_menu` of `catalog-storefront:rebuild` and
  `status`): one row per menu with its store assignments, its nodes in the order the node repository
  reads them (level, parent, position, then id) and the customer groups of each node
  (`etc/et_schema.xml`, `etc/query.xml`). Triggers on `snowmenu_menu`, `snowmenu_store` and
  `snowmenu_node` mark the menu. A node save that changes only the groups of the node writes
  `snowmenu_customer` alone, whose rows name the node:
  [`Model/Mview/NodeGroupsSubscription`](Model/Mview/NodeGroupsSubscription.php) gives that table a
  trigger that marks the menu of the node.
- [`Model/Document/Writer/Menus`](Model/Document/Writer/Menus.php) writes a document for every active
  menu in each store view it is assigned to, directly or through store 0, and removes it from the
  others; [`Model/MenuDocument`](Model/MenuDocument.php) holds the shape. Every batch writes the
  marker document (id 0) of every store view, and the rebuild seeds it, so a store view without menus
  has its menu documents. After a write the delivery purges the menu's cache tag
  (`snowdog_menu_menu_<id>`): the menu block caches its output for a year under it, and the admin save
  purges it before the feed exports the menu.
- The categories feed gets `menuProductCount`
  ([`Model/DataExporter/Provider/CategoryIndexProductCount`](Model/DataExporter/Provider/CategoryIndexProductCount.php),
  counted by [`Model/ResourceModel/CategoryIndexProductCounts`](Model/ResourceModel/CategoryIndexProductCounts.php)):
  the rows of the category in the category product index of the store view, the count a category
  node reads to hide a category without products. A trigger on `catalog_category_product` exports
  the category again, and the delivery of the categories feed also purges `cat_c_p_<id>`, the tag of
  a category node.

## Read

- [`Model/Read/MenuDocuments`](Model/Read/MenuDocuments.php) reads the menu documents of the store
  view once per request, with the marker.
- [`Plugin/MenuFromDocuments`](Plugin/MenuFromDocuments.php) answers `MenuRepositoryInterface::get()`
  for the store view of the request and for store 0: the active menu whose identifier matches byte
  for byte and that is assigned to the store asked for, with the store id of that assignment, or the
  empty menu. A read for another store view goes to the table.
- [`Plugin/NodesFromDocuments`](Plugin/NodesFromDocuments.php) answers
  `NodeRepositoryInterface::getByMenu()`: the node rows by node id, with `customer_groups` where
  `snowmenu/general/customer_groups` is on.
- [`Plugin/CategoryNodesFromDocuments`](Plugin/CategoryNodesFromDocuments.php) answers
  `NodeType\Category::fetchData()`: the request paths from the url rewrite documents of
  GraphCommerce_CatalogStorefrontUrlRewrite (category, no redirect), the categories from the category
  documents of GraphCommerce_CatalogStorefrontCategoryFrontend, the counts from `menuProductCount`.

A missing marker, a menu the store view's documents do not hold, a category node whose category has
no document in the store view (a deleted category, or one outside the store's root category, which
the categories feed does not export) and a category document without `menuProductCount` stop the
page.

## Operations

```sh
bin/magento catalog-storefront:rebuild snowdog_menu category
```

The rebuild of `category` adds `menuProductCount` to the category documents. The count is the count
at export time: a product that leaves the category product index without a change of its category
assignments (disabled, removed from the website) changes the count at the next export of the
category. In one cron run the category product indexer updates before the categories feed because
the indexer configuration lists it first (`Mview\View\Collection` processes the views in that
order); no dependency between the two indexers is declared, so with another order a category would
export with the count from before the change.
