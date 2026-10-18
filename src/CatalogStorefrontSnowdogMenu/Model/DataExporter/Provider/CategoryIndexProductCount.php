<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Model\DataExporter\Provider;

use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\ResourceModel\CategoryIndexProductCounts;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Adds to a categories feed row the number of rows of the category in the category product index of
 * the store view: the count a category node of a Snowdog menu reads to hide a category without
 * products. It counts every row, whatever the visibility of the product, as the menu does, which the
 * product count of the category does not. The count is the count at export time.
 */
class CategoryIndexProductCount
{
    public function __construct(
        private readonly CategoryIndexProductCounts $counts,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * @param array<int, array{categoryId: string, storeViewCode: string}> $values the rows the field is added to
     * @return array<string, array{categoryId: string, storeViewCode: string, menuProductCount: int}>
     */
    public function get(array $values): array
    {
        $idsByStore = [];
        foreach ($values as $value) {
            $idsByStore[$value['storeViewCode']][] = (int)$value['categoryId'];
        }
        $output = [];
        foreach ($idsByStore as $storeViewCode => $ids) {
            $ids = array_values(array_unique($ids));
            $counts = $this->counts->counts((int)$this->storeManager->getStore($storeViewCode)->getId(), $ids);
            foreach ($ids as $id) {
                $output[$storeViewCode . '_' . $id] = [
                    'categoryId' => (string)$id,
                    'storeViewCode' => $storeViewCode,
                    'menuProductCount' => $counts[$id] ?? 0,
                ];
            }
        }

        return $output;
    }
}
