# GraphCommerce_CatalogStorefrontReviewFrontend

Core's rendered review blocks of a product served from a document, from the review and rating
documents of GraphCommerce_CatalogStorefrontReview. Every seam acts on a product that carries a
document, or on the review collection of such a product, so a store view served from the database
renders its reviews as core does.

- [`Plugin/RatingsMemo`](Plugin/RatingsMemo.php) holds the rating collection of the review form for
  the request, on any product. The block builds the collection, loads it and loads its options again
  on every call, and the form template asks seven times: once to decide whether to render, once for
  the count, once per loop and again for the script that validates the answers. That is 17 queries for
  one list of ratings.
- [`Plugin/Detail/RatingsFromDocuments`](Plugin/Detail/RatingsFromDocuments.php) builds that collection
  from the rating documents: the product ratings of the store view by position, each with its options
  by position. A store view without rating documents has no active ratings. Core answers in single
  store mode, where it lists a rating whatever store views it is assigned to.
- [`Plugin/Detail/ReviewCollectionFlag`](Plugin/Detail/ReviewCollectionFlag.php) marks the review
  collection of the list block. [`ReviewsFromDocuments`](Plugin/Detail/ReviewsFromDocuments.php) fills
  a marked collection with one page of review documents, each with its votes,
  [`ReviewCountFromDocuments`](Plugin/Detail/ReviewCountFromDocuments.php) answers the pager's count
  from the same search, and [`RateVotesFromDocuments`](Plugin/Detail/RateVotesFromDocuments.php) leaves
  the votes the documents put on the items. The review page's own collection and the admin's carry no
  mark.
- [`Plugin/Detail/ReviewSummaryFromDocuments`](Plugin/Detail/ReviewSummaryFromDocuments.php) puts the
  review count and rating of the summary on the product (frontend area).

[`Model/ReviewSummaries`](Model/ReviewSummaries.php) is the summary of products from one aggregation
over the review documents, held for the request, and other modules read it too.
[`Model/ReviewDocuments`](Model/ReviewDocuments.php) is one page of a product's reviews, newest first
and by id among those of the same date.

A product of a store view without visible reviews has no review documents, so the readers ask for the
marker document the reviews writer puts in every store view
([`Model/ReviewIndex`](Model/ReviewIndex.php)): a store view without it has no review documents, and the
read stops. The summaries find the marker in their own aggregation, so a page that renders a summary
reads it at no cost. A store view without the marker, a vote of a rating without a document, a rating
document without the position or the entity and a storage error stop the read and are logged.

`bin/magento catalog-storefront:rebuild review rating` writes the documents these blocks read.
