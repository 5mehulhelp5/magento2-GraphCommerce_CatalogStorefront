# GraphCommerce_CatalogStorefrontSwatchesGraphQl

The `swatch` field (`SwatchDataInterface`, as `ConfigurableProductOptionValue.swatch`) on
`AggregationOptionInterface`, `CustomAttributeOptionInterface` and
`AttributeSelectedOptionInterface`. It depends on Magento_SwatchesGraphQl.

- [`Model/Resolver/SwatchData`](Model/Resolver/SwatchData.php) answers the swatch an option
  carries under the prefiller key, and builds the thumbnail URL of a document image swatch.
- The document path takes the swatch from the attribute documents
  (GraphCommerce_CatalogStorefrontSwatches): `CatalogStorefrontGraphQl` puts it on the options
  it builds, [`Plugin/Layer/AggregationSwatches`](Plugin/Layer/AggregationSwatches.php) on the
  aggregation options from the documents the facet read primed.
- The core path takes it from core's swatch helper
  ([`Model/Swatches`](Model/Swatches.php)): `Plugin/Output/*` on core's attribute metadata and
  attribute value providers, `AggregationSwatches` on the aggregation options. Only the options
  of a swatch attribute carry one.
