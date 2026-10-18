# GraphCommerce_CatalogStorefrontSnowdogMenu

The menus of Snowdog_Menu as documents, and the menu reads of a rendered page from them: the menu,
its nodes and the data of every node type, so a menu renders without SQL. It acts in a store view
that serves its listings or its product pages from documents (`serve_plp`, `serve_pdp`, or the
`X-Catalog-Storefront` header of GraphCommerce_CatalogStorefrontProductFrontend). Frontend area
only; the admin and GraphQL read the menu tables.

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
  reads them (level, parent, position, then id), the customer groups of each node
  (`etc/et_schema.xml`, `etc/query.xml`) and, for a CMS page or CMS block node, the active pages or
  blocks whose identifier it names, once per store each is assigned to, with a block's content
  ([`Model/ResourceModel/CmsNodeRows`](Model/ResourceModel/CmsNodeRows.php): the database matches the
  identifier through the collation of its columns, as the node types match it). Triggers on
  `snowmenu_menu`, `snowmenu_store` and `snowmenu_node` mark the menu. The rows of
  `snowmenu_customer`, `cms_page`, `cms_page_store`, `cms_block` and `cms_block_store` name no menu:
  a node save that changes only its groups writes `snowmenu_customer` alone, and a page or block
  save writes the page or block and its store rows.
  [`Model/Mview/MenuLookupSubscription`](Model/Mview/MenuLookupSubscription.php) gives those tables
  a trigger that marks the menus it looks up in `snowmenu_node`, the old and the new value on an
  update.
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
- [`Plugin/ProductNodesFromDocuments`](Plugin/ProductNodesFromDocuments.php) answers
  `NodeType\Product::fetchData()`: the request paths from the url rewrite documents (product, no
  redirect, no category), the thumbnail and the name from the product documents, and the price.
- [`Plugin/CmsPageNodesFromDocuments`](Plugin/CmsPageNodesFromDocuments.php) and
  [`Plugin/CmsBlockNodesFromDocuments`](Plugin/CmsBlockNodesFromDocuments.php) answer
  `NodeType\CmsPage::fetchData()` and `NodeType\CmsBlock::fetchData()` from the CMS rows of the menu
  document of the store view's own store and of all store views, the store view's own page or block
  last, as core reads them ([`Model/Read/MenuDocuments::cmsRows()`](Model/Read/MenuDocuments.php)).
  A page's url is the request path of its url rewrite (`cms-page`), and core's identifier for a page
  whose identifier is not among those request paths.
- The custom url and wrapper nodes read nothing. A category child node reads its category and its
  children through the category plugins of GraphCommerce_CatalogStorefrontCategoryFrontend, whose
  children collection gives its categories back when the node block clears and loads it again.

The price of a product node is the price its listing card advertises, not core's: Snowdog reads the
price index's `min_price` for the session customer's group, a guest's being the default group, and
shows it without tax. The plugin prices the model built from the product document through core's
final price for the customer group of the request, as the listing card does: the final amount, the
minimal amount of a bundle, the final amount of a grouped product's cheapest product, with the
amount the tax and fixed product tax renderers display in a listing (`item_list`), in base currency,
which the node block converts to the display currency. It differs from core's menu where tax is
displayed, for a product with required custom options (the index adds the cheapest option) and for
the composite products whose index minimum follows other rules. No template of Snowdog prints it.

A CMS block node's content runs through the block filter, as before: a directive in it, such as a
widget or another block, reads what it reads.

A missing marker, a menu the store view's documents do not hold, a category or a product node whose
category or product has no document in the store view (a deleted one, or a category outside the
store's root category, which the categories feed does not export) and a category document without
`menuProductCount` stop the page.

## Operations

```sh
bin/magento catalog-storefront:rebuild snowdog_menu category
```

The rebuild of `snowdog_menu` adds the CMS rows to the menu documents, and a menu document without
them stops the page. The rebuild of `category` adds `menuProductCount` to the category documents.
The count is the count at export time: a product that leaves the category product index without a change of its category
assignments (disabled, removed from the website) changes the count at the next export of the
category. In one cron run the category product indexer updates before the categories feed because
the indexer configuration lists it first (`Mview\View\Collection` processes the views in that
order); no dependency between the two indexers is declared, so with another order a category would
export with the count from before the change.
