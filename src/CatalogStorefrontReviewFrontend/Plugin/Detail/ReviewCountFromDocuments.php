<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReviewFrontend\Plugin\Detail;

use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontReviewFrontend\Model\ReviewDocuments;
use Magento\Review\Model\ResourceModel\Review\Collection;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Answers the size of a marked review collection with the number of review documents.
 *
 * The pager asks the collection how many reviews there are, and core counts them with a query of
 * its own. The search that fills the collection counted them already. A failed read stops the read
 * and is logged.
 */
class ReviewCountFromDocuments
{
    public function __construct(
        private readonly ReviewDocuments $reviews,
        private readonly StoreManagerInterface $storeManager,
        private readonly Strict $strict,
    ) {
    }

    /**
     * @param Collection $subject
     * @param \Closure $proceed
     * @return int
     */
    public function aroundGetSize(Collection $subject, \Closure $proceed)
    {
        $productId = $subject->getFlag(ReviewCollectionFlag::PRODUCT);
        if ($productId === null) {
            return $proceed();
        }

        try {
            return $this->reviews->page(
                (string)$this->storeManager->getStore()->getCode(),
                (int)$productId,
                (int)$subject->getPageSize(),
                max(1, (int)$subject->getCurPage())
            )['total'];
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);
        }
    }
}
