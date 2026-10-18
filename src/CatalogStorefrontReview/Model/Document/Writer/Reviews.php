<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReview\Model\Document\Writer;

use GraphCommerce\CatalogStorefront\Model\Document\Scopes;
use GraphCommerce\CatalogStorefrontApi\Document\FeedWriterInterface;
use GraphCommerce\CatalogStorefrontApi\Document\RebuildSeederInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontReview\Model\Document\RatingId;

/**
 * Keeps one review document per review and store view where the review is
 * visible, with its votes and their percents over the scale the row carries
 * per rating, and removes it where it is not or once it is deleted. The
 * product document carries nothing about reviews: the summary and the review
 * page are queries over the review documents.
 *
 * Every batch also writes the marker document of every store view, id 0 under
 * the product id "0", which no product has: a store view that holds it has its
 * review documents, so a product without one has no visible reviews. A read
 * by product id, or a summary of product ids, never meets it. A rebuild seeds
 * the marker too, so a store without reviews, which no batch writes, has it.
 */
class Reviews implements FeedWriterInterface, RebuildSeederInterface
{
    public const ENTITY = 'review';

    public const MARKER = 0;

    public const MARKER_PRODUCT = '0';

    private const MARKER_DOCUMENT = ['productId' => self::MARKER_PRODUCT, 'percents' => []];

    public function __construct(
        private readonly MetadataDocumentStorageInterface $reviews,
        private readonly Scopes $scopes,
        private readonly RatingId $ratingId,
    ) {
    }

    public function write(array $rows): void
    {
        $stores = $this->scopes->storeViews();
        $upserts = [];
        $deletes = [];
        foreach ($rows as $row) {
            if (empty($row['reviewId']) || empty($row['productId'])) {
                continue;
            }
            $scales = array_column((array)($row['ratingScales'] ?? []), 'scale', 'ratingId');
            $votes = [];
            $percents = [];
            foreach ((array)($row['ratings'] ?? []) as $rating) {
                $ratingId = $this->ratingId->decode((string)$rating['ratingId']);
                $votes[$ratingId] = (int)$rating['value'];
                if (!empty($scales[$ratingId])) {
                    $percents[] = (int)$rating['value'] / (int)$scales[$ratingId] * 100;
                }
            }
            foreach ($stores as $store) {
                if (empty($row['deleted']) && in_array($store, (array)($row['visibility'] ?? []), true)) {
                    $upserts[$store][(int)$row['reviewId']] = [
                        'reviewId' => (int)$row['reviewId'],
                        'productId' => (string)(int)$row['productId'],
                        'title' => $row['title'] ?? null,
                        'text' => $row['text'] ?? null,
                        'nickname' => $row['nickname'] ?? null,
                        'createdAt' => $row['createdAt'] ?? null,
                        'votes' => $votes,
                        'percents' => $percents,
                    ];
                } else {
                    $deletes[$store][] = (int)$row['reviewId'];
                }
            }
        }
        foreach ($stores as $store) {
            $this->reviews->upsert(self::ENTITY, $store, [self::MARKER => self::MARKER_DOCUMENT] + ($upserts[$store] ?? []));
            $this->reviews->delete(self::ENTITY, $store, $deletes[$store] ?? []);
        }
    }

    public function seed(string $storeViewCode): void
    {
        $this->reviews->upsert(self::ENTITY, $storeViewCode, [self::MARKER => self::MARKER_DOCUMENT]);
    }
}
