# GraphCommerce_CatalogStorefrontSwatchesGraphQl

Serves the `swatch` fields of GraphCommerce_GraphQlCatalogSwatches
([GraphCommerce GraphQL Catalog](https://github.com/graphcommerce-org/magento2-GraphCommerce_GraphQlCatalog))
from the attribute documents (GraphCommerce_CatalogStorefrontSwatches).

- `CatalogStorefrontGraphQl` puts the document swatch on the attribute and selected options it
  builds, under `OptionSwatch::KEY`.
- [`Plugin/Layer/AggregationSwatchesFromDocuments`](Plugin/Layer/AggregationSwatchesFromDocuments.php)
  puts it on the aggregation options, from the attribute documents the facet read primed. Its sort
  order (10) runs it before the core plugin of GraphQlCatalogSwatches (20), which keeps the options
  that carry the key, so the document path reads no swatch table.
