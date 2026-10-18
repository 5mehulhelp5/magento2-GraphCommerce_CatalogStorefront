<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReviewFrontend\Model;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontReview\Model\Document\Writer\Reviews;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Whether the review documents of a store view are written, from its marker document, held for the
 * request. With the marker, a product without review documents has no visible reviews; without it,
 * the store view has no review documents and a read stops. The summaries find the marker in their
 * own aggregation, so a page that renders a summary reads it once.
 */
class ReviewIndex implements ResetAfterRequestInterface
{
    /** @var array<string, true> the store views whose marker a read of this request found */
    private array $indexed = [];

    public function __construct(
        private readonly MetadataDocumentStorageInterface $storage,
    ) {
    }

    /**
     * Records that a read of this request found the marker of the store view.
     */
    public function found(string $storeViewCode): void
    {
        $this->indexed[$storeViewCode] = true;
    }

    /**
     * @throws \UnexpectedValueException when the store view has no marker
     */
    public function assertIndexed(string $storeViewCode): void
    {
        if (isset($this->indexed[$storeViewCode])) {
            return;
        }
        if (!isset($this->storage->get(Reviews::ENTITY, $storeViewCode, [Reviews::MARKER])[Reviews::MARKER])) {
            throw new \UnexpectedValueException(sprintf(
                'Store view "%s" has no review documents: run bin/magento catalog-storefront:rebuild review',
                $storeViewCode
            ));
        }
        $this->indexed[$storeViewCode] = true;
    }

    public function _resetState(): void
    {
        $this->indexed = [];
    }
}
