# GraphCommerce_CatalogStorefrontReview

Reviews and ratings in the documents. It depends on Magento_Review and
Magento_ProductReviewDataExporter.

- `Model/DataExporter/Processor/` makes the reviews and rating metadata feeds export during
  indexing like the modern feeds; [etc/db_schema.xml](etc/db_schema.xml) adds the modern
  columns to the exporter's own tables and [etc/di.xml](etc/di.xml) gives both indexers the
  generic serializer.
- The feed carries the review date, the rating scales, the sku of the reviewed product and
  the approved count and average per product and store view. The rating metadata rows get
  the rating's position and the code of the entity it rates
  ([`Model/ResourceModel/RatingEntities`](Model/ResourceModel/RatingEntities.php)), which the
  review form orders and filters by.
- [`Model/Document/Writer/Reviews`](Model/Document/Writer/Reviews.php) writes one document
  per review and store view where it is visible, with the vote percents over the scale of
  its rating, and with every batch the marker document of every store view: id 0, under the
  product id `0`, which no product has. A store view with the marker has its review
  documents, so a reader can tell a product without visible reviews from a store view the
  feed never wrote. [`Writer/Ratings`](Model/Document/Writer/Ratings.php) writes the rating
  metadata, which [`Model/Read/RatingDocuments`](Model/Read/RatingDocuments.php) reads. The
  product document holds no review.

`bin/magento catalog-storefront:rebuild review rating` rebuilds both entities.
