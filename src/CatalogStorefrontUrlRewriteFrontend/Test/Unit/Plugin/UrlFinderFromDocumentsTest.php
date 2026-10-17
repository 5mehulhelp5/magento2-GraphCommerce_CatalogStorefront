<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontUrlRewriteFrontend\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use GraphCommerce\CatalogStorefrontUrlRewriteFrontend\Model\Read\UrlFinder;
use GraphCommerce\CatalogStorefrontUrlRewriteFrontend\Plugin\UrlFinderFromDocuments;
use Magento\CatalogUrlRewrite\Model\Storage\DbStorage as CatalogStorage;
use Magento\CatalogUrlRewrite\Model\Storage\DynamicStorage;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\Storage\DbStorage;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UrlFinderFromDocumentsTest extends TestCase
{
    /** @var string[] the storages the finder was asked for, in order */
    private array $asked = [];

    private function plugin(bool $documents, ?\Throwable $failure = null): UrlFinderFromDocuments
    {
        $this->asked = [];
        $urlFinder = $this->createStub(UrlFinder::class);
        if ($failure === null) {
            $urlFinder->method('findOneByData')->willReturnCallback(function (array $data, string $storage): UrlRewrite {
                $this->asked[] = $storage;

                return new UrlRewrite(['request_path' => 'documents'], new Json());
            });
            $urlFinder->method('findAllByData')->willReturnCallback(function (array $data, string $storage): array {
                $this->asked[] = $storage;

                return ['documents'];
            });
        } else {
            $urlFinder->method('findOneByData')->willThrowException($failure);
        }
        $mode = $this->createStub(Mode::class);
        $mode->method('listing')->willReturn(false);
        $mode->method('detail')->willReturn($documents);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn('1');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new UrlFinderFromDocuments(
            $urlFinder,
            $mode,
            $storeManager,
            new Strict($this->createStub(StorefrontKey::class), $this->createStub(LoggerInterface::class))
        );
    }

    public function testEachStorageTakesItsDocumentAnswer(): void
    {
        $plugin = $this->plugin(true);
        $core = static fn() => 'core';

        $this->assertSame('documents', $plugin->aroundFindOneByData($this->createStub(DbStorage::class), $core, [])->getRequestPath());
        $this->assertSame(['documents'], $plugin->aroundFindAllByData($this->createStub(CatalogStorage::class), $core, []));
        $plugin->aroundFindOneByData($this->createStub(DynamicStorage::class), $core, []);
        $this->assertSame([UrlFinder::TABLE, UrlFinder::CATALOG, UrlFinder::DYNAMIC], $this->asked);
    }

    public function testAStoreViewServedFromTheDatabaseAsksCore(): void
    {
        $plugin = $this->plugin(false);
        $core = static fn() => 'core';

        $this->assertSame('core', $plugin->aroundFindOneByData($this->createStub(DbStorage::class), $core, []));
        $this->assertSame('core', $plugin->aroundFindAllByData($this->createStub(CatalogStorage::class), $core, []));
        $this->assertSame([], $this->asked);
    }

    public function testAFailedReadStopsThePage(): void
    {
        $plugin = $this->plugin(true, new \RuntimeException('cluster down'));

        $this->expectException(DocumentReadException::class);
        $this->expectExceptionMessage('cluster down');

        $plugin->aroundFindOneByData($this->createStub(DbStorage::class), static fn() => 'core', []);
    }
}
