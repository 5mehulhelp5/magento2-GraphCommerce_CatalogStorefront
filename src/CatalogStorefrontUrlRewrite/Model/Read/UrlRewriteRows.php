<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontUrlRewrite\Model\Read;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontUrlRewrite\Model\UrlRewriteDocument;

/**
 * Reads url rewrite rows of a store view in one search, with the collation document that says the
 * store view has its url rewrite documents. A store view without it stops the read.
 */
class UrlRewriteRows
{
    public function __construct(
        private readonly MetadataDocumentStorageInterface $storage,
    ) {
    }

    /**
     * @param array<int, array<string, scalar|scalar[]>> $alternatives filters over the declared fields, any of which a row matches
     * @return array<int, array<string, string|null>> the rows by url rewrite id, in id order, with the folded keys
     */
    public function rows(string $storeViewCode, array $alternatives): array
    {
        $documents = $this->storage->any(UrlRewriteDocument::ENTITY, $storeViewCode, [
            ...$alternatives,
            [UrlRewriteDocument::KIND => UrlRewriteDocument::COLLATION],
        ]);
        if (!isset($documents[UrlRewriteDocument::COLLATION])) {
            throw new DocumentReadException(sprintf(
                'Store view "%s" has no url rewrite documents: run bin/magento catalog-storefront:rebuild %s',
                $storeViewCode,
                UrlRewriteDocument::ENTITY
            ));
        }
        unset($documents[UrlRewriteDocument::COLLATION]);

        $rows = [];
        foreach ($documents as $id => $document) {
            unset($document['id']);
            $rows[(int)$id] = $document;
        }
        ksort($rows);

        return $rows;
    }
}
