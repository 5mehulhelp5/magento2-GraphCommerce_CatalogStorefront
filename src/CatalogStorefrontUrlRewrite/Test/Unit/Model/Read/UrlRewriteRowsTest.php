<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontUrlRewrite\Test\Unit\Model\Read;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontUrlRewrite\Model\Read\UrlRewriteRows;
use PHPUnit\Framework\TestCase;

class UrlRewriteRowsTest extends TestCase
{
    public function testTheRowsComeInIdOrderWithoutTheCollationDocument(): void
    {
        $storage = $this->createMock(MetadataDocumentStorageInterface::class);
        $storage->expects($this->once())->method('any')->with(
            'url_rewrite',
            'default',
            [['request_path_key' => ['A']], ['kind' => 'collation']]
        )->willReturn([
            '20' => ['id' => '20', 'request_path' => 'b'],
            'collation' => ['id' => 'collation', 'kind' => 'collation'],
            '10' => ['id' => '10', 'request_path' => 'a'],
        ]);

        $rows = (new UrlRewriteRows($storage))->rows('default', [['request_path_key' => ['A']]]);

        $this->assertSame([10 => ['request_path' => 'a'], 20 => ['request_path' => 'b']], $rows);
    }

    public function testAStoreViewWithoutTheCollationDocumentStopsTheRead(): void
    {
        $storage = $this->createStub(MetadataDocumentStorageInterface::class);
        $storage->method('any')->willReturn(['10' => ['id' => '10']]);

        $this->expectException(DocumentReadException::class);
        $this->expectExceptionMessage('Store view "default" has no url rewrite documents');

        (new UrlRewriteRows($storage))->rows('default', []);
    }
}
