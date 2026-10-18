<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReviewFrontend\Plugin\Detail;

use Magento\Review\Model\ResourceModel\Review\Collection;

/**
 * Leaves the votes of a review collection the documents filled as they are.
 *
 * Core asks the vote table for every review of the page, one query each. A review document carries
 * its votes, and the seam that fills the collection puts them on the items.
 */
class RateVotesFromDocuments
{
    /**
     * @param Collection $subject
     * @param \Closure $proceed
     * @return Collection
     */
    public function aroundAddRateVotes(Collection $subject, \Closure $proceed)
    {
        return $subject->getFlag(ReviewCollectionFlag::SERVED) ? $subject : $proceed();
    }
}
