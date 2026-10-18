<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReviewFrontend\Plugin\Detail;

use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontReviewFrontend\Model\ReviewSummaries;
use Magento\Catalog\Model\Product;
use Magento\Framework\Model\AbstractModel;
use Magento\Review\Model\AppendSummaryData;
use Magento\Review\Model\Review;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Puts the review summary of a product served from a document on it from the review documents.
 *
 * The review summary of a product page reads the summary row of the product for the store view. The
 * review summaries give the same count and rating. A product without visible reviews gets null for
 * both, as the read of no row gives; the templates test both for a value, so a product whose reviews
 * are all waiting for approval, which core answers with a row of zeros, renders the same.
 *
 * Another entity, another store view and a product without a document get the row. A store view
 * without review documents and a storage error stop the read and are logged.
 */
class ReviewSummaryFromDocuments
{
    public function __construct(
        private readonly ReviewSummaries $summaries,
        private readonly StoreManagerInterface $storeManager,
        private readonly Strict $strict,
    ) {
    }

    /**
     * @param AppendSummaryData $subject
     * @param \Closure $proceed
     * @param AbstractModel $object
     * @param int $storeId
     * @param string $entityCode
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundExecute(AppendSummaryData $subject, \Closure $proceed, AbstractModel $object, int $storeId, string $entityCode): void
    {
        $store = $this->storeManager->getStore();
        if ($entityCode !== Review::ENTITY_PRODUCT_CODE
            || !$object instanceof Product
            || !is_array($object->getData(ProductDocumentsInterface::DOCUMENT_KEY))
            || (int)$store->getId() !== $storeId
        ) {
            $proceed($object, $storeId, $entityCode);

            return;
        }

        $summary = null;
        try {
            $summary = $this->summaries->summaries($store, [(int)$object->getId()])[(int)$object->getId()] ?? null;
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);
        }

        // The table gives the columns as strings, and no row gives null.
        $object->addData([
            'reviews_count' => $summary === null ? null : (string)$summary['reviews_count'],
            'rating_summary' => $summary === null ? null : (string)$summary['rating_summary'],
        ]);
    }
}
