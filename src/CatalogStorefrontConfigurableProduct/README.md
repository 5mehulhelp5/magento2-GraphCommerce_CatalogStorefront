# GraphCommerce_CatalogStorefrontConfigurableProduct

Configurable products in the documents. It depends on Magento_ConfigurableProduct,
Magento_ConfigurableProductDataExporter, Magento_ProductVariantDataExporter and Magento_Swatches.

- The variants feed writer keeps the relation as id lists: `variantIds` on the parent,
  `parentIds` on the child.
- [`Model/DataExporter/Provider/ConfigurableOptions`](Model/DataExporter/Provider/ConfigurableOptions.php)
  stores the super attributes and their values compact on the document, and
  [`Model/Read/ConfigurableOptions`](Model/Read/ConfigurableOptions.php) expands them at
  read time. `OptionsV2WithoutConfigurable` keeps the raw configurable entries out of
  `optionsV2`, which took a third of a 13 KB configurable document.
- The configurable price range over the `priceIndex` entries of the variants.
- [`Plugin/Eav/ResyncConfigurablesOnAttributeChange`](Plugin/Eav/ResyncConfigurablesOnAttributeChange.php)
  keeps those options fresh. Core's option label resync schedules the products that store a
  renamed option, and the products feed adds their configurables to the sync itself. A
  configurable's document also copies the attribute's labels and the swatches of its options,
  which core compares nowhere: an attribute save that changes a label schedules every
  configurable that uses the attribute, and one that changes, adds or removes a swatch the
  products that store those options
  ([`Model/ResourceModel/ConfigurableAttributeState`](Model/ResourceModel/ConfigurableAttributeState.php)).
  More than `threshold` products (a di.xml argument, 1000 by default, as core's) or a products
  feed not on schedule invalidate the feed for a full resync instead; so does a failure, which
  is logged.

[etc/di.xml](etc/di.xml) keeps `parentId` in the minimal variant payload the exporter
retains, so a deletion row names both sides of the relation. A deletion row without that
parent id is refused instead of leaving a stale relation;
`bin/magento catalog-storefront:rebuild product` writes every retained variants row again.
