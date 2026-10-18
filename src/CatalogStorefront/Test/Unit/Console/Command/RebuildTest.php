<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Console\Command;

use GraphCommerce\CatalogStorefront\Console\Command\Rebuild;
use GraphCommerce\CatalogStorefront\Model\Document\Scopes;
use GraphCommerce\CatalogStorefront\Model\Feeds;
use GraphCommerce\CatalogStorefrontApi\Document\RebuildSeederInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class RebuildTest extends TestCase
{
    /** @var string[] every seed and promote, in order */
    private array $calls = [];

    private function command(): Rebuild
    {
        $metadata = $this->createStub(MetadataDocumentStorageInterface::class);
        $metadata->method('promote')->willReturnCallback(function (string $entity, string $storeViewCode): void {
            $this->calls[] = "promote $entity $storeViewCode";
        });
        $stores = [];
        foreach (['default', 'second'] as $code) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getCode')->willReturn($code);
            $stores[] = $store;
        }
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);
        $feeds = $this->createStub(Feeds::class);
        $feeds->method('byEntity')->willReturn(['review' => [], 'rating' => []]);
        $seeder = $this->createStub(RebuildSeederInterface::class);
        $seeder->method('seed')->willReturnCallback(function (string $storeViewCode): void {
            $this->calls[] = "seed $storeViewCode";
        });

        return new Rebuild(
            $this->createStub(ProductDocumentStorageInterface::class),
            $metadata,
            $storeManager,
            $this->createStub(ResourceConnection::class),
            $this->createStub(IndexerRegistry::class),
            $this->createStub(State::class),
            $feeds,
            $this->createStub(Scopes::class),
            [],
            ['review' => ['marker' => $seeder]]
        );
    }

    public function testTheSeedersWriteEveryStoreViewBeforeItsIndexServesTheReads(): void
    {
        (new CommandTester($this->command()))->execute(['entities' => ['review'], '--promote' => true]);

        $this->assertSame(['seed default', 'promote review default', 'seed second', 'promote review second'], $this->calls);
    }

    public function testAnEntityWithoutSeedersIsPromotedAsItIs(): void
    {
        (new CommandTester($this->command()))->execute(['entities' => ['rating'], '--promote' => true]);

        $this->assertSame(['promote rating default', 'promote rating second'], $this->calls);
    }
}
