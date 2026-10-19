# GraphCommerce_CatalogStorefrontSwatches

The swatches of the attribute documents. It depends on Magento_Swatches.

- [`Plugin/DataExporter/AttributeOptionSwatches`](Plugin/DataExporter/AttributeOptionSwatches.php)
  adds `swatch` (type and value, the file of an image swatch) to every option of a swatch
  attribute on the product attributes feed, picked per store view as core's swatch helper
  picks it. [etc/et_schema.xml](etc/et_schema.xml) declares the field, so every attribute
  row changes hash and the next feed run exports the attributes again.
- [etc/mview.xml](etc/mview.xml) subscribes the attributes feed to `eav_attribute_option_swatch`:
  a swatch names its option, and the base module's `AttributeOptionSubscription` marks the
  option's attribute.
