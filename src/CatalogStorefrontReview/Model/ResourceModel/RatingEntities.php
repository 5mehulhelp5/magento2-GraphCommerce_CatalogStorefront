<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReview\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * The position of a rating and the code of the entity it rates: what the review form orders and
 * filters its ratings by, and what the rating metadata feed of the exporter leaves out.
 */
class RatingEntities extends AbstractDb
{
    /**
     * @inheritDoc
     */
    protected function _construct(): void
    {
        $this->_init('rating', 'rating_id');
    }

    /**
     * @param int[] $ratingIds
     * @return array<int, array{position: int, entityCode: string}> by rating id, the ratings that exist
     */
    public function positionsAndEntities(array $ratingIds): array
    {
        $ratingIds = array_values(array_unique(array_map('intval', $ratingIds)));
        if ($ratingIds === []) {
            return [];
        }

        $connection = $this->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['rating' => $this->getMainTable()], ['rating_id', 'position'])
                ->join(
                    ['entity' => $this->getTable('rating_entity')],
                    'entity.entity_id = rating.entity_id',
                    ['entity_code']
                )
                ->where('rating.rating_id IN (?)', $ratingIds)
        );

        $ratings = [];
        foreach ($rows as $row) {
            $ratings[(int)$row['rating_id']] = [
                'position' => (int)$row['position'],
                'entityCode' => (string)$row['entity_code'],
            ];
        }

        return $ratings;
    }
}
