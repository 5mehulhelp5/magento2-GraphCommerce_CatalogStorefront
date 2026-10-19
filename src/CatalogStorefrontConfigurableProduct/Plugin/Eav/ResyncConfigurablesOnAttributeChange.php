<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProduct\Plugin\Eav;

use GraphCommerce\CatalogStorefrontConfigurableProduct\Model\ResourceModel\ConfigurableAttributeState;
use Magento\Catalog\Model\Product;
use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\ResourceModel\Entity\Attribute as AttributeResource;
use Magento\Framework\DB\Select;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Model\AbstractModel;

/**
 * Schedules the products feed for the configurables whose documents copy what an attribute save
 * changed and core's option label resync does not compare: the attribute's labels (the label of
 * the configurable option) and the swatches of its options (the swatch of each value).
 *
 * A changed label schedules every configurable that uses the attribute; a changed, added or
 * removed swatch schedules the products that store one of those options, and the products feed
 * adds their configurables to the sync itself, as it does for core's label resync. Above the
 * threshold, or with the feed not on schedule, the feed is invalidated for a full resync, as core
 * does. A failure is logged and invalidates the feed too: the save has committed, and a missed
 * resync would leave the documents stale without a sign.
 */
class ResyncConfigurablesOnAttributeChange
{
    private const PRODUCTS_FEED = 'catalog_data_exporter_products';

    private ?int $productEntityTypeId = null;

    /**
     * @param ConfigurableAttributeState $state
     * @param EavConfig $eavConfig
     * @param IndexerRegistry $indexerRegistry
     * @param CommerceDataExportLoggerInterface $logger
     * @param int $threshold the products scheduled one by one at most; more invalidate the feed
     */
    public function __construct(
        private readonly ConfigurableAttributeState $state,
        private readonly EavConfig $eavConfig,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly CommerceDataExportLoggerInterface $logger,
        private readonly int $threshold = 1000,
    ) {
    }

    /**
     * @param AttributeResource $subject
     * @param \Closure $proceed
     * @param AbstractModel $object
     * @return AttributeResource
     */
    public function aroundSave(AttributeResource $subject, \Closure $proceed, AbstractModel $object)
    {
        $attributeId = (int)$object->getId();
        $before = null;
        $failure = null;
        try {
            if ($this->handles($object)) {
                $before = $this->snapshot($attributeId);
            }
        } catch (\Throwable $e) {
            $failure = $e;
        }

        $result = $proceed($object);

        try {
            if ($failure !== null) {
                throw $failure;
            }
            if ($before !== null) {
                $products = $this->products($attributeId, (string)$object->getBackendTable(), $before, $this->snapshot($attributeId));
                if ($products !== null) {
                    $this->schedule($products, $attributeId);
                }
            }
        } catch (\Throwable $e) {
            $this->fail($attributeId, $e);
        }

        return $result;
    }

    /**
     * An existing product attribute that a configurable uses.
     */
    private function handles(AbstractModel $object): bool
    {
        if ($object->isObjectNew() || !$object->getId()) {
            return false;
        }
        $this->productEntityTypeId ??= (int)$this->eavConfig->getEntityType(Product::ENTITY)->getId();

        return (int)$object->getEntityTypeId() === $this->productEntityTypeId && $this->state->isUsed((int)$object->getId());
    }

    /**
     * @return array{labels: array<int, string>, swatches: array<int, array<int, string>>}
     */
    private function snapshot(int $attributeId): array
    {
        return ['labels' => $this->state->labels($attributeId), 'swatches' => $this->state->swatches($attributeId)];
    }

    /**
     * The products whose documents copy what changed, or null for none.
     */
    private function products(int $attributeId, string $backendTable, array $before, array $after): ?Select
    {
        if ($before['labels'] !== $after['labels']) {
            return $this->state->configurables($attributeId);
        }
        $changed = [];
        foreach (array_keys($before['swatches'] + $after['swatches']) as $optionId) {
            if (($before['swatches'][$optionId] ?? []) !== ($after['swatches'][$optionId] ?? [])) {
                $changed[] = $optionId;
            }
        }
        if ($changed === []) {
            return null;
        }

        // Without a value table to look the options up in, every configurable that uses the attribute.
        return $backendTable !== ''
            ? $this->state->productsWithOptions($attributeId, $backendTable, $changed)
            : $this->state->configurables($attributeId);
    }

    private function schedule(Select $products, int $attributeId): void
    {
        $indexer = $this->indexerRegistry->get(self::PRODUCTS_FEED);
        if (!$indexer->getView()->isEnabled()) {
            $indexer->invalidate();
            $this->logger->info(sprintf(
                'Configurable documents: attribute %d changed its labels or swatches. Full resync scheduled: the products feed does not update on schedule.',
                $attributeId
            ));

            return;
        }
        $count = $this->state->count($products, $this->threshold + 1);
        if ($count === 0) {
            return;
        }
        if ($count > $this->threshold) {
            $indexer->invalidate();
            $this->logger->info(sprintf(
                'Configurable documents: attribute %d changed its labels or swatches, affected products: more than %d. Full resync scheduled.',
                $attributeId,
                $this->threshold
            ));

            return;
        }
        $changelog = $indexer->getView()->getChangelog();
        $this->state->queue($products, $changelog->getName(), $changelog->getColumnName());
        $this->logger->info(sprintf(
            'Configurable documents: attribute %d changed its labels or swatches, affected products: %d. Partial resync scheduled.',
            $attributeId,
            $count
        ));
    }

    private function fail(int $attributeId, \Throwable $e): void
    {
        $this->logger->error(
            sprintf(
                'Configurable documents: scheduling the products of attribute %d failed, so the products feed is invalidated for a full resync: %s',
                $attributeId,
                $e->getMessage()
            ),
            ['exception' => $e]
        );
        try {
            $this->indexerRegistry->get(self::PRODUCTS_FEED)->invalidate();
        } catch (\Throwable $invalidation) {
            $this->logger->critical(
                sprintf('Configurable documents: invalidating the products feed failed too; run a products feed resync: %s', $invalidation->getMessage()),
                ['exception' => $invalidation]
            );
        }
    }
}
