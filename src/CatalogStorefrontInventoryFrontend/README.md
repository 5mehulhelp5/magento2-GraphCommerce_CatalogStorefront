# GraphCommerce_CatalogStorefrontInventoryFrontend

The stock of rendered catalog pages from the documents. It depends on
GraphCommerce_CatalogStorefrontInventory, which writes the stock slice, and on
GraphCommerce_CatalogStorefrontProductFrontend, which builds the product page's product from its
document. A separate module, so neither the frontend module requires MSI nor the inventory module
requires rendered pages.

- [`Plugin/StockItemFromDocument`](Plugin/StockItemFromDocument.php) answers the stock registry's
  `getStockItem()` for the product page's product from its stock slice: core's stock item model
  with the legacy settings of `cataloginventory_stock_item`, the use-config flags set where the
  slice has null, so the getters read the configuration as for an item from the table. The
  quantity box reads it. A bundle or a grouped product, for which the stock feed exports no row,
  another product and a product loaded from the database go to core; a slice or a setting missing
  from the document of another type stops the page.

The settings stay current through the inventory module: every run of the products feed, which
watches `cataloginventory_stock_item`, schedules the stock rows of its products.
