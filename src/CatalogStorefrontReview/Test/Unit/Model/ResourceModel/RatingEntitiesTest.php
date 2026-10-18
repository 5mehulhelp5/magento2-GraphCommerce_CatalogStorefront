<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReview\Test\Unit\Model\ResourceModel;

use GraphCommerce\CatalogStorefrontReview\Model\ResourceModel\RatingEntities;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

class RatingEntitiesTest extends TestCase
{
    private function resource(AdapterInterface $connection): RatingEntities
    {
        $resource = $this->getMockBuilder(RatingEntities::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConnection', 'getMainTable', 'getTable'])
            ->getMock();
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getMainTable')->willReturn('rating');
        $resource->method('getTable')->willReturnArgument(0);

        return $resource;
    }

    public function testEveryRatingHasItsPositionAndTheCodeOfItsEntity(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->expects($this->once())->method('where')->with('rating.rating_id IN (?)', [1, 4])->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([
            ['rating_id' => '1', 'position' => '2', 'entity_code' => 'product'],
            ['rating_id' => '4', 'position' => '0', 'entity_code' => 'product'],
        ]);

        $this->assertSame(
            [1 => ['position' => 2, 'entityCode' => 'product'], 4 => ['position' => 0, 'entityCode' => 'product']],
            $this->resource($connection)->positionsAndEntities(['1', '4', '1'])
        );
    }

    public function testNoRatingsReadNothing(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->never())->method('select');

        $this->assertSame([], $this->resource($connection)->positionsAndEntities([]));
    }
}
