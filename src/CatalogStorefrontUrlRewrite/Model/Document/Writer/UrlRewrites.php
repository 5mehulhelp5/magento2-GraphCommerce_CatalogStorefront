<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontUrlRewrite\Model\Document\Writer;

use GraphCommerce\CatalogStorefrontApi\Document\FeedWriterInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontUrlRewrite\Model\Collation;
use GraphCommerce\CatalogStorefrontUrlRewrite\Model\ResourceModel\ColumnCollations;
use GraphCommerce\CatalogStorefrontUrlRewrite\Model\UrlRewriteDocument;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Writes the url rewrite feed rows as url rewrite documents and deletes the documents of the rows
 * the feed reports removed. Every batch writes the collation document of every store view, so a
 * store view without a single row still has its url rewrite documents.
 *
 * A batch stops before it writes when a string column of the table uses a collation the fold does
 * not know: the exporter then keeps the rows and retries them, and the log names the column.
 */
class UrlRewrites implements FeedWriterInterface
{
    public function __construct(
        private readonly MetadataDocumentStorageInterface $storage,
        private readonly Collation $collation,
        private readonly ColumnCollations $columnCollations,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function write(array $rows): void
    {
        $collations = $this->columnCollations->byColumn();
        foreach (UrlRewriteDocument::STRINGS as $column) {
            if (!in_array($collations[$column] ?? null, Collation::SUPPORTED, true)) {
                throw new \RuntimeException(sprintf(
                    'url_rewrite.%s uses the collation %s, which the url rewrite documents do not fold; supported: %s',
                    $column,
                    $collations[$column] ?? 'none',
                    implode(', ', Collation::SUPPORTED)
                ));
            }
        }

        $upserts = [];
        $deletes = [];
        foreach ($rows as $row) {
            $id = (int)($row['urlRewriteId'] ?? 0);
            $store = (string)($row['storeViewCode'] ?? '');
            if ($id === 0 || $store === '') {
                throw new \RuntimeException('A url rewrite feed row has no urlRewriteId or storeViewCode');
            }
            if (!empty($row['deleted'])) {
                $deletes[$store][] = $id;
                continue;
            }
            $document = [];
            foreach (UrlRewriteDocument::COLUMNS as $field => $column) {
                $document[$column] = isset($row[$field]) ? (string)$row[$field] : null;
            }
            foreach (UrlRewriteDocument::STRINGS as $column) {
                $document[$column . UrlRewriteDocument::KEY_SUFFIX] = $this->collation->fold($document[$column]);
            }
            $upserts[$store][$id] = $document;
        }
        foreach ($this->storeManager->getStores() as $store) {
            $upserts[(string)$store->getCode()][UrlRewriteDocument::COLLATION] = [
                UrlRewriteDocument::KIND => UrlRewriteDocument::COLLATION,
                UrlRewriteDocument::COLLATION => $collations['request_path'],
            ];
        }

        foreach ($upserts as $store => $documents) {
            $this->storage->upsert(UrlRewriteDocument::ENTITY, $store, $documents);
        }
        foreach ($deletes as $store => $ids) {
            $this->storage->delete(UrlRewriteDocument::ENTITY, $store, $ids);
        }
    }
}
