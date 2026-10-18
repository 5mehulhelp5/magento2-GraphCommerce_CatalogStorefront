<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReviewFrontend\Model;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontReview\Model\Document\Writer\Reviews;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Store\Api\Data\StoreInterface;

/**
 * The review summaries of products in a store view, from the review documents: the number of visible
 * reviews and the average of their percents, rounded as the summary table holds it. One aggregation
 * answers every product asked for, the read the GraphQL review summary takes, and asks for the marker
 * group too, so it also says whether the store view has its review documents. The summaries of a
 * request are kept, so the review block and the structured data of a page share one read.
 */
class ReviewSummaries implements ResetAfterRequestInterface
{
    /** @var array<string, array<int, array{reviews_count: int, rating_summary: int}|null>> per store view by product id, null for a product without reviews */
    private array $summaries = [];

    public function __construct(
        private readonly MetadataDocumentStorageInterface $storage,
        private readonly ReviewIndex $index,
    ) {
    }

    /**
     * @param int[] $productIds
     * @return array<int, array{reviews_count: int, rating_summary: int}> by product id, only the products with reviews
     * @throws \UnexpectedValueException when the store view has no review documents
     */
    public function summaries(StoreInterface $store, array $productIds): array
    {
        $code = (string)$store->getCode();
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        $missing = array_values(array_diff($ids, array_keys($this->summaries[$code] ?? [])));
        if ($missing !== []) {
            $stats = $this->storage->stats(
                Reviews::ENTITY,
                $code,
                'productId',
                [...array_map('strval', $missing), Reviews::MARKER_PRODUCT],
                'percents'
            );
            if (!isset($stats[Reviews::MARKER_PRODUCT])) {
                throw new \UnexpectedValueException(sprintf(
                    'Store view "%s" has no review documents: run bin/magento catalog-storefront:rebuild review',
                    $code
                ));
            }
            $this->index->found($code);
            foreach ($missing as $productId) {
                $stat = $stats[(string)$productId] ?? null;
                $this->summaries[$code][$productId] = $stat === null ? null : [
                    'reviews_count' => (int)($stat['count'] ?? 0),
                    'rating_summary' => isset($stat['avg']) ? (int)round($stat['avg']) : 0,
                ];
            }
        }

        return array_filter(array_intersect_key($this->summaries[$code], array_flip($ids)));
    }

    public function _resetState(): void
    {
        $this->summaries = [];
    }
}
