<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReview\Model\DataExporter\Processor;

use GraphCommerce\CatalogStorefrontReview\Model\ResourceModel\RatingEntities;
use Magento\DataExporter\Export\DataProcessorInterface;
use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;
use Magento\DataExporter\Export\Request\Info;
use Magento\DataExporter\Export\Request\Node;
use Magento\ProductReviewDataExporter\Model\Provider\RatingMetadata;

/**
 * Lets the rating metadata feed export during indexing: immediate export
 * requires a data processor, and the exporter's provider is a plain one. Every
 * row gets the rating's position and the code of the entity it rates, which
 * the exporter's provider leaves out and the review form orders and filters by.
 */
class Ratings implements DataProcessorInterface
{
    public function __construct(
        private readonly RatingMetadata $ratingMetadata,
        private readonly RatingEntities $ratingEntities,
    ) {
    }

    public function execute(
        array $arguments,
        callable $dataProcessorCallback,
        FeedIndexMetadata $metadata,
        ?Node $node = null,
        ?Info $info = null
    ): void {
        // The indexer hands over rating_id; the provider reads ratingId.
        $rows = $this->ratingMetadata->get(array_map(
            static fn(array $argument) => ['ratingId' => $argument['rating_id'] ?? $argument['ratingId']],
            $arguments
        ));
        $ratings = $this->ratingEntities->positionsAndEntities(array_column($rows, 'ratingId'));
        foreach ($rows as $index => $row) {
            $rows[$index] += $ratings[(int)$row['ratingId']] ?? [];
        }

        $dataProcessorCallback($rows);
    }
}
