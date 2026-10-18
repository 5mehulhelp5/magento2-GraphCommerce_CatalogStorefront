<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSnowdogMenu\Test\Unit\Model\DataExporter\Provider;

use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\DataExporter\Provider\NodeCmsBlocks;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\DataExporter\Provider\NodeCmsPages;
use GraphCommerce\CatalogStorefrontSnowdogMenu\Model\ResourceModel\CmsNodeRows;
use PHPUnit\Framework\TestCase;

/**
 * The CMS rows of the nodes in the shapes of the exporter: the extractor passes the rows of the
 * repeated nodes field one list per menu, keyed by the menu's lookup, takes each row's value from
 * the key named after the field, and finds its node by the `using` field.
 */
class NodeCmsRowsTest extends TestCase
{
    private const NODES = [
        'menu-1' => [['nodeId' => '311', 'type' => 'cms_page'], ['nodeId' => '312', 'type' => 'cms_block']],
        'menu-2' => [['nodeId' => '311', 'type' => 'cms_page']],
    ];

    public function testAPageRowComesUnderTheFieldWithItsNode(): void
    {
        $page = ['nodeId' => '311', 'pageId' => '7', 'identifier' => 'test', 'storeId' => '0'];
        $rows = $this->createMock(CmsNodeRows::class);
        $rows->expects($this->once())->method('pages')->with([311, 312])->willReturn([$page]);

        $this->assertSame(
            [['nodeId' => '311', 'cmsPages' => $page]],
            (new NodeCmsPages($rows))->get(self::NODES)
        );
    }

    public function testABlockRowComesUnderTheFieldWithItsNode(): void
    {
        $block = ['nodeId' => '312', 'identifier' => 'women-block', 'content' => '<p>Women</p>', 'storeId' => '0'];
        $rows = $this->createMock(CmsNodeRows::class);
        $rows->expects($this->once())->method('blocks')->with([311, 312])->willReturn([$block]);

        $this->assertSame(
            [['nodeId' => '312', 'cmsBlocks' => $block]],
            (new NodeCmsBlocks($rows))->get(self::NODES)
        );
    }
}
