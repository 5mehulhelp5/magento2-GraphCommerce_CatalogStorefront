# GraphCommerce_CatalogStorefrontUrlRewrite

The url rewrite table as documents per store view, so a read side can find a rewrite without the
table. `GraphCommerce_CatalogStorefrontUrlRewriteFrontend` answers the url finder of rendered
pages from them.

## The feed

The feed `urlRewriteRows` (indexer `catalog_storefront_url_rewrites`) exports the table like the
exporter's own feeds. Its triggers on `url_rewrite` and `catalog_url_rewrite_product_category`
see every write, through a service or plain SQL. The indexer runs by schedule: a change reaches
the documents with the next cron run. A batch the document store does not take stays in the feed
table and the exporter retries it; `bin/magento catalog-storefront:status` shows it as waiting.
The admin store view is left out, since no storefront request reads it.

## The documents

The entity `url_rewrite`: one document per row, in the index of the store view of the row, keyed
by the row id.

- The columns as the table gives them, strings or null, with `category_id` and `product_id` of
  the product category relation of the row (null without one).
- The folded key of every string column (`request_path_key`, `target_path_key`,
  `entity_type_key`, `description_key`, `metadata_key`), which a lookup filters on.
- The document `collation` of each store view, which every batch writes for every store view. A
  store view without it has no url rewrite documents, and a read there stops.

## The collation

The table compares its string columns through their collation: `request_path` is unique per
store view without case or accents, and a lookup of `Women/Tops.html` finds `women/tops.html`.
[`Model/Collation`](Model/Collation.php) folds a string to the weights of the general_ci
collations (`utf8mb4_general_ci`, `utf8mb3_general_ci`, `utf8_general_ci`): one weight per
character, trailing spaces ignored. The weights are generated from the database:

```sh
php app/code/GraphCommerce/CatalogStorefront/dev/url-rewrite/collation-map.php
```

writes `data/general_ci.php`. The script stops without writing where the database does not give
one weight per character, U+FFFD for every character above the basic multilingual plane, and
equality across trailing spaces. A table whose string columns use another collation stops the
export with an error that names the column; the reads never meet a key they cannot fold.

## Operations

The first build, a mapping change, and the repair after a store view was deleted:

```sh
bin/magento catalog-storefront:rebuild url_rewrite
```
