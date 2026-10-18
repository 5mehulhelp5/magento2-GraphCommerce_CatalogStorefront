<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontApi\Document;

/**
 * Writes what the documents of an entity must hold even where its feeds export no row: a marker
 * that says the documents of a store view are written, for an entity whose readers tell a missing
 * document from documents that were never written. The rebuild command calls it for every store
 * view after the feeds filled the staged index and before that index serves the reads (di.xml
 * `seeders` on the command, by entity). A feed batch writes the same marker, so the documents of a
 * store view that the feeds never wrote have none, and a read of them stops.
 */
interface RebuildSeederInterface
{
    public function seed(string $storeViewCode): void;
}
