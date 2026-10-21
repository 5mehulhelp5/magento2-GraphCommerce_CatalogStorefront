# GraphCommerce_CatalogStorefrontInventory

The stock slice of the product documents. It depends on Magento_InventoryDataExporter and
Magento_InventorySales.

- [`Model/Document/Writer/Stock`](Model/Document/Writer/Stock.php) writes the stock status
  feed onto the documents of the store views of the websites its stock sells through, and
  only where the products feed already wrote a document. A deleted row empties the slice,
  so the products feed's `inStock` answers again.
- [`Model/DataExporter/Provider/StockItem`](Model/DataExporter/Provider/StockItem.php) adds
  stock quantity, stock status, sale limits, quantity increments, stock management and backorder settings.
  A configured setting has a null document value; the reader uses store configuration.
  `StockWebsites` adds the website codes that route the row.
- [`Plugin/DataExporter/QueueStockOfExportedProducts`](Plugin/DataExporter/QueueStockOfExportedProducts.php)
  keeps those settings current. The stock status feed's own triggers watch source items and
  reservations, so orders and source updates reach it, but not `cataloginventory_stock_item`,
  where the settings change (a product save, the stock item API, an import). The products feed
  watches that table, so every run of it schedules the stock rows of its products: their skus go
  into the stock feed's changelog, or are reindexed at once when the stock feed updates on save
  ([`Model/ResourceModel/ProductSkus`](Model/ResourceModel/ProductSkus.php)). A trigger of the stock
  feed on the table itself is not safe: the table is shared with other views, and core builds a
  shared table's trigger with the subscription model of the view that subscribes last. A full sync
  of the products feed names no products, so it invalidates the stock feed for a full resync. The
  plugin is on the feed indexer class and acts for the products feed only: the products feed's
  indexer is a virtual type, and a `<type>` declaration for that name replaces the virtual type's
  definition.
- [`Plugin/InventoryIndexer/QueueStockOfSalabilityChanges`](Plugin/InventoryIndexer/QueueStockOfSalabilityChanges.php)
  keeps the salability current after an order. The reservation trigger exports the row at once,
  before MSI's `inventory.reservations.updateSalabilityStatus` consumer recalculates salability with
  the reservations (in `cataloginventory_stock_status` for the default stock, the stock's index for
  another), which the stock feed does not watch; the consumer's handler returns the skus whose
  salability changed, and those are scheduled again. The consumer has to run (a supervisor
  program, or the `consumers` cron group): without it core's pages do not go out of stock on the
  last order either. Core's `LegacyStockStatusUpdater` and `StockStatusUpdater` also reach the
  document through the products feed, but only with that feed on schedule, and their failures are
  only warnings.
- Both schedule through [`Model/StockFeedSchedule`](Model/StockFeedSchedule.php): the stock feed's
  changelog, or a reindex at once when it updates on save. A failure is logged and invalidates the
  stock feed for a full resync.
