<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReview\Test\Unit\Model\DataExporter\Processor;

use GraphCommerce\CatalogStorefrontReview\Model\DataExporter\Processor\Ratings;
use GraphCommerce\CatalogStorefrontReview\Model\ResourceModel\RatingEntities;
use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;
use Magento\ProductReviewDataExporter\Model\Provider\RatingMetadata;
use PHPUnit\Framework\TestCase;

class RatingsTest extends TestCase
{
    public function testEveryRatingGetsItsPositionAndEntity(): void
    {
        $metadata = $this->createMock(RatingMetadata::class);
        $metadata->expects($this->once())->method('get')->with([['ratingId' => 1], ['ratingId' => 4]])->willReturn([
            ['ratingId' => '1', 'storeViewCode' => 'default', 'name' => 'Quality', 'values' => []],
            ['ratingId' => '4', 'storeViewCode' => 'default', 'name' => 'Rating', 'values' => []],
            ['ratingId' => '1', 'storeViewCode' => 'second', 'name' => 'Quality', 'values' => []],
        ]);
        $entities = $this->createMock(RatingEntities::class);
        $entities->expects($this->once())->method('positionsAndEntities')->with(['1', '4', '1'])->willReturn([
            1 => ['position' => 2, 'entityCode' => 'product'],
            4 => ['position' => 0, 'entityCode' => 'product'],
        ]);

        $rows = null;
        (new Ratings($metadata, $entities))->execute(
            [['rating_id' => 1], ['ratingId' => 4]],
            static function (array $output) use (&$rows) {
                $rows = $output;
            },
            $this->createStub(FeedIndexMetadata::class)
        );

        $this->assertSame([2, 'product'], [$rows[0]['position'], $rows[0]['entityCode']]);
        $this->assertSame([0, 'product'], [$rows[1]['position'], $rows[1]['entityCode']]);
        $this->assertSame([2, 'product'], [$rows[2]['position'], $rows[2]['entityCode']]);
    }

    public function testARatingTheTableNoLongerHoldsKeepsItsRow(): void
    {
        $metadata = $this->createStub(RatingMetadata::class);
        $metadata->method('get')->willReturn([['ratingId' => '9', 'storeViewCode' => 'default', 'name' => 'Gone', 'values' => []]]);
        $entities = $this->createStub(RatingEntities::class);
        $entities->method('positionsAndEntities')->willReturn([]);

        $rows = null;
        (new Ratings($metadata, $entities))->execute(
            [['rating_id' => 9]],
            static function (array $output) use (&$rows) {
                $rows = $output;
            },
            $this->createStub(FeedIndexMetadata::class)
        );

        $this->assertSame([['ratingId' => '9', 'storeViewCode' => 'default', 'name' => 'Gone', 'values' => []]], $rows);
    }
}
