<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReviewFrontend\Model;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontReview\Model\Document\Writer\Reviews;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The review documents of a product, newest first and by id among those of the same date, as the
 * rendered list pages them.
 *
 * The list block asks its collection to load, and the pager asks it for its size: both are one
 * search, so the answer is held for the request. A store view without review documents stops the
 * read.
 */
class ReviewDocuments implements ResetAfterRequestInterface
{
    /** @var array<string, array{documents: array[], total: int}> the page per store view, product and page */
    private array $pages = [];

    public function __construct(
        private readonly MetadataDocumentStorageInterface $storage,
        private readonly ReviewIndex $index,
    ) {
    }

    /**
     * @return array{documents: array[], total: int}
     * @throws \UnexpectedValueException when the store view has no review documents
     */
    public function page(string $storeViewCode, int $productId, int $pageSize, int $currentPage): array
    {
        $key = $storeViewCode . ':' . $productId . ':' . $pageSize . ':' . $currentPage;
        if (isset($this->pages[$key])) {
            return $this->pages[$key];
        }

        $this->index->assertIndexed($storeViewCode);
        // Core orders on the date alone and answers reviews of the same date by id, ascending.
        $found = $this->storage->find(
            Reviews::ENTITY,
            $storeViewCode,
            ['productId' => [(string)$productId]],
            [['createdAt', 'desc'], ['reviewId', 'asc']],
            $pageSize > 0 ? ($currentPage - 1) * $pageSize : 0,
            $pageSize > 0 ? $pageSize : 10000
        );

        return $this->pages[$key] = [
            'documents' => array_values((array)($found['documents'] ?? [])),
            'total' => (int)($found['total'] ?? 0),
        ];
    }

    public function _resetState(): void
    {
        $this->pages = [];
    }
}
