<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Model\DataExporter\Provider;

use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\ResourceModel\CmsNodeRows;

/**
 * Adds to the nodes of a menu feed row the active blocks a CMS block node names, per store. The nodes
 * come as the exporter passes the rows of a repeated field below the root, one list per menu, and
 * each row goes back as the exporter joins a nested field to its parent: the node id, and the row
 * under the field's name.
 */
class NodeCmsBlocks
{
    private const FIELD = 'cmsBlocks';

    public function __construct(
        private readonly CmsNodeRows $rows,
    ) {
    }

    /**
     * @param array<string, array<int, array{nodeId: string}>> $values the nodes of each exported menu
     * @return array<int, array{nodeId: string, cmsBlocks: array{nodeId: string, identifier: string, content: string|null, storeId: string}}>
     */
    public function get(array $values): array
    {
        $nodeIds = array_column(array_merge([], ...array_values($values)), 'nodeId');

        return array_map(
            static fn(array $row): array => ['nodeId' => $row['nodeId'], self::FIELD => $row],
            $this->rows->blocks(array_values(array_unique(array_map('intval', $nodeIds))))
        );
    }
}
