<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReviewFrontend\Plugin\Detail;

use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontReview\Model\Read\RatingDocuments;
use GraphCommerce\CatalogStorefrontReviewFrontend\Model\ReviewDocuments;
use Magento\Framework\DataObject;
use Magento\Review\Model\ResourceModel\Review\Collection;
use Magento\Review\Model\ReviewFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Fills a marked review collection from the review documents.
 *
 * The list block loads the collection and then asks for the votes of every review, which is a
 * query for the reviews and one for each of them. A review is a document of its own, with its
 * votes, so the page the block renders is one search. The votes travel on the items, and the rate
 * votes seam finds them there. A vote is the rating's name and the percent of the value over the
 * rating's value count, as a whole number, as the vote table holds it.
 *
 * A store view without review documents, a vote of a rating without a document and a storage error
 * stop the read and are logged.
 */
class ReviewsFromDocuments
{
    public function __construct(
        private readonly ReviewDocuments $reviews,
        private readonly RatingDocuments $ratings,
        private readonly ReviewFactory $reviewFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly Strict $strict,
    ) {
    }

    /**
     * @param Collection $subject
     * @param \Closure $proceed
     * @param bool $printQuery
     * @param bool $logQuery
     * @return Collection
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundLoad(Collection $subject, \Closure $proceed, $printQuery = false, $logQuery = false)
    {
        $productId = $subject->getFlag(ReviewCollectionFlag::PRODUCT);
        if ($productId === null) {
            return $proceed($printQuery, $logQuery);
        }
        // The block loads the collection, and every read of its items loads it again.
        if ($subject->getFlag(ReviewCollectionFlag::SERVED)) {
            return $subject;
        }

        $items = [];
        try {
            $storeViewCode = (string)$this->storeManager->getStore()->getCode();
            $page = $this->reviews->page(
                $storeViewCode,
                (int)$productId,
                (int)$subject->getPageSize(),
                max(1, (int)$subject->getCurPage())
            );
            foreach ($page['documents'] as $document) {
                $votes = [];
                foreach ((array)($document['votes'] ?? []) as $ratingId => $value) {
                    $scale = $this->ratings->scale($storeViewCode, (int)$ratingId);
                    $name = $this->ratings->name($storeViewCode, (int)$ratingId);
                    if (!$scale || $name === null) {
                        throw new \UnexpectedValueException(sprintf(
                            'Rating %d of review %d has no document in store view "%s": run bin/magento catalog-storefront:rebuild rating',
                            (int)$ratingId,
                            (int)($document['reviewId'] ?? 0),
                            $storeViewCode
                        ));
                    }
                    $votes[] = new DataObject([
                        'rating_id' => (int)$ratingId,
                        'value' => (int)$value,
                        'percent' => (int)round((int)$value / $scale * 100),
                        'rating_code' => $name,
                    ]);
                }

                $review = $this->reviewFactory->create();
                $review->setData([
                    'review_id' => (int)($document['reviewId'] ?? 0),
                    'entity_pk_value' => (int)($document['productId'] ?? 0),
                    'title' => $document['title'] ?? null,
                    'detail' => $document['text'] ?? null,
                    'nickname' => $document['nickname'] ?? null,
                    'created_at' => $document['createdAt'] ?? null,
                ]);
                $review->setRatingVotes($votes);
                $items[] = $review;
            }
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);
        }

        foreach ($items as $item) {
            $subject->addItem($item);
        }
        $subject->setFlag(ReviewCollectionFlag::SERVED, true);

        return $subject;
    }
}
