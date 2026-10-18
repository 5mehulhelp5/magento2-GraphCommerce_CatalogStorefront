<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Test\Unit\Model\ResourceModel;

use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\ResourceModel\CmsNodeRows;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Model\ResourceModel\Db\Context;
use PHPUnit\Framework\TestCase;

/**
 * The pages and blocks the CMS nodes name, joined in the database as the node types join them.
 */
class CmsNodeRowsTest extends TestCase
{
    /** @var array<int, array{0: string, 1: array}> the select calls in order */
    private array $calls = [];

    private function rows(array $fetched): CmsNodeRows
    {
        $select = $this->createMock(Select::class);
        foreach (['from', 'join', 'where', 'order'] as $method) {
            $select->method($method)->willReturnCallback(function (...$arguments) use ($select, $method) {
                // The mock passes the defaults of the optional parameters too.
                while ($arguments !== [] && end($arguments) === null) {
                    array_pop($arguments);
                }
                $this->calls[] = [$method, $arguments];

                return $select;
            });
        }
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->with($select)->willReturn($fetched);
        $metadata = $this->createStub(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('row_id');
        $metadata->method('getIdentifierField')->willReturn('page_id');
        $pool = $this->createMock(MetadataPool::class);
        $pool->method('getMetadata')->willReturnCallback(
            static fn(string $type) => in_array($type, [PageInterface::class, BlockInterface::class], true) ? $metadata : null
        );
        $rows = $this->getMockBuilder(CmsNodeRows::class)
            ->setConstructorArgs([$this->createStub(Context::class), $pool])
            ->onlyMethods(['getConnection', 'getMainTable', 'getTable'])
            ->getMock();
        $rows->method('getConnection')->willReturn($connection);
        $rows->method('getMainTable')->willReturn('snowmenu_node');
        $rows->method('getTable')->willReturnArgument(0);

        return $rows;
    }

    public function testThePagesAreTheActiveOnesTheNodesNamePerStore(): void
    {
        $row = ['nodeId' => '7', 'pageId' => '5', 'identifier' => 'about-us', 'storeId' => '0'];

        $this->assertSame([$row], $this->rows([$row])->pages([7, 8]));
        $this->assertSame([
            ['from', [['node' => 'snowmenu_node'], ['nodeId' => 'node.node_id']]],
            ['join', [['page' => 'cms_page'], 'page.identifier = node.content', ['pageId' => 'page.page_id', 'identifier' => 'page.identifier']]],
            ['join', [['store' => 'cms_page_store'], 'store.row_id = page.row_id', ['storeId' => 'store.store_id']]],
            ['where', ['node.node_id IN (?)', [7, 8]]],
            ['where', ['node.type = ?', 'cms_page']],
            ['where', ['page.is_active = ?', 1]],
            ['order', ['store.store_id ASC']],
        ], $this->calls, 'the link field of the edition joins the store rows');
    }

    public function testTheBlocksAreTheActiveOnesTheNodesNamePerStore(): void
    {
        $row = ['nodeId' => '8', 'identifier' => 'promo', 'content' => '<p>Sale</p>', 'storeId' => '1'];

        $this->assertSame([$row], $this->rows([$row])->blocks([8]));
        $this->assertSame(
            [['block' => 'cms_block'], 'block.identifier = node.content', ['identifier' => 'block.identifier', 'content' => 'block.content']],
            $this->calls[1][1]
        );
        $this->assertSame(['node.type = ?', 'cms_block'], $this->calls[4][1]);
    }

    public function testNoNodesReadNothing(): void
    {
        $rows = $this->rows([]);

        $this->assertSame([], $rows->pages([]));
        $this->assertSame([], $rows->blocks([]));
        $this->assertSame([], $this->calls);
    }
}
