<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Plugin;

use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\Read\MenuDocuments;
use GraphCommerce\CatalogStorefrontUrlRewrite\Model\Collation;
use GraphCommerce\CatalogStorefrontUrlRewrite\Model\Read\UrlRewriteRows;
use GraphCommerce\CatalogStorefrontUrlRewrite\Model\UrlRewriteDocument;
use Magento\Bundle\Pricing\Price\FinalPrice as BundleFinalPrice;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Pricing\Render;
use Magento\GroupedProduct\Pricing\Price\FinalPrice as GroupedFinalPrice;
use Magento\Tax\Helper\Data as TaxHelper;
use Magento\Tax\Pricing\Adjustment as TaxAdjustment;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\Weee\Helper\Data as WeeeHelper;
use Magento\Weee\Model\Tax as WeeeTax;
use Magento\Weee\Pricing\Adjustment as WeeeAdjustment;
use Magento\Weee\Pricing\TaxAdjustment as WeeeTaxAdjustment;
use Snowdog\Menu\Model\NodeType\Product;

/**
 * Answers the data of the product nodes of a menu from the documents.
 *
 * For the products of its nodes, core reads the request paths of their url rewrites (product, no
 * redirect, no category), the minimal price of the price index and the thumbnail and name of the
 * products of the store's website. The url rewrite documents hold the request paths and the product
 * documents the products. The answer has the shape core's has.
 *
 * The price is the price the product's listing card advertises, not the price index's minimal
 * price: the model built from the document priced through core's final price for the customer
 * group of the request, the lowest amount for a bundle and a grouped product as their price boxes
 * show it, the amount the tax and the fixed product tax renderers display in a listing, in base
 * currency, which the node block converts. A product without a document stops the read.
 */
class ProductNodesFromDocuments
{
    private const ENTITY_TYPE = 'product';

    public function __construct(
        private readonly MenuDocuments $documents,
        private readonly ProductDocumentsInterface $products,
        private readonly UrlRewriteRows $urlRewriteRows,
        private readonly Collation $collation,
        private readonly TaxHelper $taxHelper,
        private readonly WeeeHelper $weeeHelper,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly Strict $strict,
    ) {
    }

    /**
     * @param Product $subject
     * @param \Closure $proceed
     * @param \Snowdog\Menu\Model\Menu\Node[] $nodes
     * @param int|string $storeId
     * @return array{0: array, 1: array<int, string>, 2: array<int, float>, 3: array<int, string>, 4: array<int, string>}
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundFetchData(Product $subject, \Closure $proceed, array $nodes, $storeId)
    {
        $store = $this->documents->store();
        if ($store === null || (int)$storeId !== (int)$store->getId()) {
            return $proceed($nodes, $storeId);
        }
        $storeViewCode = (string)$store->getCode();

        $localNodes = [];
        $productIds = [];
        foreach ($nodes as $node) {
            $localNodes[$node->getId()] = $node;
            $productIds[] = (int)$node->getContent();
        }
        $ids = array_values(array_unique($productIds));
        sort($ids);

        $urls = [];
        $prices = [];
        $images = [];
        $titles = [];
        try {
            $models = $this->products->build($store, $this->products->documents($storeViewCode, $ids));
            $missing = array_diff($ids, array_keys($models));
            if ($missing !== []) {
                throw new \UnexpectedValueException(sprintf(
                    'Products %s of a menu have no document in store view "%s"',
                    implode(', ', $missing),
                    $storeViewCode
                ));
            }
            // The rows come in id order, as the table yields them, so the last row of a product wins as in core's pairs.
            foreach ($this->urlRewriteRows->rows($storeViewCode, [[
                UrlRewrite::ENTITY_TYPE . UrlRewriteDocument::KEY_SUFFIX => $this->collation->fold(self::ENTITY_TYPE),
                UrlRewrite::REDIRECT_TYPE => '0',
                UrlRewrite::ENTITY_ID => array_map('strval', $ids),
            ]]) as $row) {
                if ($row[UrlRewrite::METADATA] === null) {
                    $urls[(int)$row[UrlRewrite::ENTITY_ID]] = $row[UrlRewrite::REQUEST_PATH];
                }
            }
            $rate = (float)$this->priceCurrency->convert(1, $store);
            foreach ($models as $id => $model) {
                $images[$id] = $model->getData('thumbnail') ?? '';
                $titles[$id] = $model->getData('name') ?? '';
                $final = $model->getPriceInfo()->getPrice(FinalPrice::PRICE_CODE);
                $amount = match (true) {
                    $final instanceof BundleFinalPrice => $final->getMinimalPrice(),
                    $final instanceof GroupedFinalPrice => $final->getMinProduct()
                        ?->getPriceInfo()->getPrice(FinalPrice::PRICE_CODE)->getAmount(),
                    default => $final->getAmount(),
                };
                if ($amount === null) {
                    continue;
                }
                $value = $this->taxHelper->displayPriceExcludingTax()
                    ? $amount->getValue(TaxAdjustment::ADJUSTMENT_CODE)
                    : $amount->getValue();
                if ($this->weeeHelper->typeOfDisplay([WeeeTax::DISPLAY_EXCL_DESCR_INCL], Render::ZONE_ITEM_LIST, $store)) {
                    $value -= (float)$amount->getAdjustmentAmount(WeeeAdjustment::ADJUSTMENT_CODE)
                        + (float)$amount->getAdjustmentAmount(WeeeTaxAdjustment::ADJUSTMENT_CODE);
                }
                $prices[$id] = $value / $rate;
            }
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);
        }

        return [$localNodes, $urls, $prices, $images, $titles];
    }
}
