<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontCategoryFrontend\Model;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\Collection;

/**
 * A category collection that holds built models and runs no query for them.
 *
 * The collection is its categories: a caller that clears it and loads it again to select one more
 * attribute gets them back. Its select carries no filter, so running it would give every category.
 * A built category holds every attribute of its document, so a reload with more attributes
 * selected needs nothing more.
 */
class LoadedCollection extends Collection
{
    /** @var Category[] */
    private array $categories = [];

    /**
     * @param Category[] $categories
     */
    public function withItems(array $categories): self
    {
        $this->categories = $categories;

        return $this->load();
    }

    /**
     * @param bool $printQuery
     * @param bool $logQuery
     * @return $this
     */
    public function load($printQuery = false, $logQuery = false)
    {
        if (!$this->isLoaded()) {
            foreach ($this->categories as $category) {
                $this->addItem($category);
            }
            $this->_setIsLoaded();
        }

        return $this;
    }
}
