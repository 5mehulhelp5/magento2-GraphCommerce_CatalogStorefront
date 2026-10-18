<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReviewFrontend\Plugin\Detail;

use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontReview\Model\Read\RatingDocuments;
use Magento\Catalog\Model\Product;
use Magento\Framework\Data\CollectionFactory;
use Magento\Review\Block\Form;
use Magento\Review\Model\Rating\OptionFactory;
use Magento\Review\Model\RatingFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds the ratings of the review form of a product served from a document from the rating documents.
 *
 * Core loads the active product ratings of the store view with their options, and counts them: three
 * queries. The rating documents of the store view hold the active ratings the store view carries, with
 * the name core's form shows, the position, the entity and the options with their values and
 * positions. The form gets the product ratings by position, each with its options by position, as a
 * collection that counts its items. Ratings and options of equal position follow their ids; core's
 * query leaves that order to the database. A store view without rating documents has no active
 * ratings, and the form shows none, as core's does.
 *
 * Core answers where the product carries no document and where the store runs in single store mode:
 * core then lists a rating whatever store views it is assigned to, which the documents of one store
 * view cannot say. A rating document without the position or the entity and a storage error stop the
 * read and are logged.
 */
class RatingsFromDocuments
{
    private const ENTITY = 'product';

    public function __construct(
        private readonly RatingDocuments $ratings,
        private readonly RatingFactory $ratingFactory,
        private readonly OptionFactory $optionFactory,
        private readonly CollectionFactory $collectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly Strict $strict,
    ) {
    }

    /**
     * @param Form $subject
     * @param \Closure $proceed
     * @return mixed
     */
    public function aroundGetRatings(Form $subject, \Closure $proceed)
    {
        // The block's product is the one the request names, as its protected getProductId() reads it.
        if (!(int)$subject->getRequest()->getParam('id', false) || $this->storeManager->isSingleStoreMode()) {
            return $proceed();
        }
        $product = $subject->getProductInfo();
        if (!$product instanceof Product || !is_array($product->getData(ProductDocumentsInterface::DOCUMENT_KEY))) {
            return $proceed();
        }

        $ratings = [];
        try {
            $storeViewCode = (string)$this->storeManager->getStore()->getCode();
            foreach ($this->ratings->documents($storeViewCode) as $ratingId => $rating) {
                if (!array_key_exists('position', $rating) || !array_key_exists('entityCode', $rating)) {
                    throw new \UnexpectedValueException(sprintf(
                        'Rating %d in store view "%s" has no position or entityCode: run bin/magento catalog-storefront:rebuild rating',
                        $ratingId,
                        $storeViewCode
                    ));
                }
                if ($rating['entityCode'] === self::ENTITY) {
                    $ratings[(int)$ratingId] = $rating;
                }
            }
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);
        }
        uksort(
            $ratings,
            static fn(int $a, int $b) => [(int)$ratings[$a]['position'], $a] <=> [(int)$ratings[$b]['position'], $b]
        );

        $collection = $this->collectionFactory->create();
        foreach ($ratings as $ratingId => $rating) {
            $options = array_map(
                static fn(array $value) => ['option_id' => (int)base64_decode((string)$value['valueId'])] + $value,
                array_values(array_filter((array)($rating['values'] ?? []), 'is_array'))
            );
            usort(
                $options,
                static fn(array $a, array $b) => [(int)($a['position'] ?? 0), $a['option_id']] <=> [(int)($b['position'] ?? 0), $b['option_id']]
            );

            $collection->addItem($this->ratingFactory->create()->setData([
                'rating_id' => (string)$ratingId,
                'rating_code' => (string)($rating['name'] ?? ''),
                'position' => (string)$rating['position'],
                'is_active' => '1',
                'entity_code' => self::ENTITY,
                'options' => array_map(
                    fn(array $option) => $this->optionFactory->create()->setData([
                        'option_id' => (string)$option['option_id'],
                        'rating_id' => (string)$ratingId,
                        'value' => (string)($option['value'] ?? ''),
                        'position' => (string)($option['position'] ?? 0),
                    ]),
                    $options
                ),
            ]));
        }

        return $collection;
    }
}
