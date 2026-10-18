<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReviewFrontend\Plugin\Detail;

use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use Magento\Review\Block\Product\View as ReviewList;
use Magento\Review\Model\ResourceModel\Review\Collection;

/**
 * Marks the review collection of a product that is served from a document.
 *
 * The block is where the product is known; the collection carries the mark to the seams that fill
 * it. Every other review collection, of the review page or of the admin, is out of reach.
 */
class ReviewCollectionFlag
{
    /** The collection flag that holds the product id the reviews belong to. */
    public const PRODUCT = 'gc_review_product';

    /** The collection flag that says the documents filled it. */
    public const SERVED = 'gc_reviews_served';

    /**
     * @param ReviewList $subject
     * @param Collection $collection
     * @return Collection
     */
    public function afterGetReviewsCollection(ReviewList $subject, $collection)
    {
        if (!$collection instanceof Collection || $collection->getFlag(self::PRODUCT) !== null) {
            return $collection;
        }

        $product = $subject->getProduct();
        if ($product !== null && is_array($product->getData(ProductDocumentsInterface::DOCUMENT_KEY))) {
            $collection->setFlag(self::PRODUCT, (int)$product->getId());
        }

        return $collection;
    }
}
