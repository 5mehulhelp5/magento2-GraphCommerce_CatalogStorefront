<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Model\ResourceModel;

use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;

/**
 * The active pages and blocks the CMS nodes of a menu name, once per store each is assigned to: the
 * rows Snowdog's CMS page and CMS block node types read, for every store. The identifier is matched
 * in the database, through the collation of its columns, as the node types match it.
 */
class CmsNodeRows extends AbstractDb
{
    /**
     * @param Context $context
     * @param MetadataPool $metadataPool
     * @param string $connectionName
     */
    public function __construct(
        Context $context,
        private readonly MetadataPool $metadataPool,
        $connectionName = null,
    ) {
        parent::__construct($context, $connectionName);
    }

    /**
     * @inheritDoc
     */
    protected function _construct(): void
    {
        $this->_init('snowmenu_node', 'node_id');
    }

    /**
     * @param int[] $nodeIds
     * @return array<int, array{nodeId: string, pageId: string, identifier: string, storeId: string}>
     */
    public function pages(array $nodeIds): array
    {
        if ($nodeIds === []) {
            return [];
        }
        $metadata = $this->metadataPool->getMetadata(PageInterface::class);
        $connection = $this->getConnection();

        return $connection->fetchAll(
            $connection->select()
                ->from(['node' => $this->getMainTable()], ['nodeId' => 'node.node_id'])
                ->join(
                    ['page' => $this->getTable('cms_page')],
                    'page.identifier = node.content',
                    ['pageId' => 'page.' . $metadata->getIdentifierField(), 'identifier' => 'page.identifier']
                )
                ->join(
                    ['store' => $this->getTable('cms_page_store')],
                    sprintf('store.%1$s = page.%1$s', $metadata->getLinkField()),
                    ['storeId' => 'store.store_id']
                )
                ->where('node.node_id IN (?)', $nodeIds)
                ->where('node.type = ?', 'cms_page')
                ->where('page.is_active = ?', 1)
                ->order('store.store_id ASC')
        );
    }

    /**
     * @param int[] $nodeIds
     * @return array<int, array{nodeId: string, identifier: string, content: string|null, storeId: string}>
     */
    public function blocks(array $nodeIds): array
    {
        if ($nodeIds === []) {
            return [];
        }
        $metadata = $this->metadataPool->getMetadata(BlockInterface::class);
        $connection = $this->getConnection();

        return $connection->fetchAll(
            $connection->select()
                ->from(['node' => $this->getMainTable()], ['nodeId' => 'node.node_id'])
                ->join(
                    ['block' => $this->getTable('cms_block')],
                    'block.identifier = node.content',
                    ['identifier' => 'block.identifier', 'content' => 'block.content']
                )
                ->join(
                    ['store' => $this->getTable('cms_block_store')],
                    sprintf('store.%1$s = block.%1$s', $metadata->getLinkField()),
                    ['storeId' => 'store.store_id']
                )
                ->where('node.node_id IN (?)', $nodeIds)
                ->where('node.type = ?', 'cms_block')
                ->where('block.is_active = ?', 1)
                ->order('store.store_id ASC')
        );
    }
}
