<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReview\Model\Read;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The store view's active ratings, from the rating metadata feed, loaded once
 * per request: the documents as the review form reads them, and the value
 * scale and name of one rating. A vote's percent is its value over the
 * rating's number of values; a rating the store view does not carry does not
 * count.
 */
class RatingDocuments implements ResetAfterRequestInterface
{
    /** @var array<string, array<int, array>> the rating documents per store view, by rating id */
    private array $documents = [];

    public function __construct(
        private readonly MetadataDocumentStorageInterface $storage,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>> by rating id
     */
    public function documents(string $storeViewCode): array
    {
        if (!isset($this->documents[$storeViewCode])) {
            $this->documents[$storeViewCode] = [];
            foreach ($this->storage->all('rating', $storeViewCode) as $id => $rating) {
                $this->documents[$storeViewCode][(int)$id] = (array)$rating;
            }
        }

        return $this->documents[$storeViewCode];
    }

    public function scale(string $storeViewCode, int $ratingId): ?int
    {
        $rating = $this->documents($storeViewCode)[$ratingId] ?? null;

        return $rating === null ? null : count((array)($rating['values'] ?? []));
    }

    public function name(string $storeViewCode, int $ratingId): ?string
    {
        $rating = $this->documents($storeViewCode)[$ratingId] ?? null;

        return $rating === null ? null : (string)($rating['name'] ?? '');
    }

    public function _resetState(): void
    {
        $this->documents = [];
    }
}
