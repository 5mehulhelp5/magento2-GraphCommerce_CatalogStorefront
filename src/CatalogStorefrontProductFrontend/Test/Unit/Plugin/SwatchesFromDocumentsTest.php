<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefront\Model\Read\AttributeDocuments;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Read\AttributeOptions;
use GraphCommerce\CatalogStorefrontProductFrontend\Plugin\SwatchesFromDocuments;
use Magento\Framework\App\State;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Swatches\Helper\Data as SwatchHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The swatches of options as core's swatch helper answers them, from the attribute documents of the store view.
 */
class SwatchesFromDocumentsTest extends TestCase
{
    private const OPTIONS = [
        '49' => ['id' => '49', 'label' => 'Black', 'swatch' => ['type' => 1, 'value' => '#000000']],
        '52' => ['id' => '52', 'label' => 'Gold', 'swatch' => ['type' => 2, 'value' => '/g/o/gold.png']],
        '166' => ['id' => '166', 'label' => 'XS', 'swatch' => ['type' => 0, 'value' => 'XS']],
        '170' => ['id' => '170', 'label' => 'XL'],
    ];

    private bool $proceeded = false;

    private bool $served = true;

    private AttributeDocuments&MockObject $documents;

    private function plugin(?LoggerInterface $logger = null): SwatchesFromDocuments
    {
        $this->documents = $this->createMock(AttributeDocuments::class);
        $this->documents->method('optionsById')->willReturnCallback(
            static fn(string $store, array $ids): array => array_intersect_key(self::OPTIONS, array_flip(array_map('strval', $ids)))
        );
        $mode = $this->createMock(Mode::class);
        $mode->method('listing')->willReturnCallback(fn(int $storeId): bool => $storeId === 1 && $this->served);
        $mode->method('detail')->willReturn(false);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn('1');
        $store->method('getCode')->willReturn('default');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $strict = new Strict($this->createStub(StorefrontKey::class), $logger ?? $this->createStub(LoggerInterface::class));
        $attributes = new AttributeOptions($this->documents, $mode, $stores, $this->createStub(State::class), $strict);

        return new SwatchesFromDocuments($attributes, $this->documents, $strict);
    }

    private function proceed(): \Closure
    {
        return function () {
            $this->proceeded = true;

            return ['core'];
        };
    }

    public function testTheSwatchesAreTheDocumentsInTheTablesShape(): void
    {
        // The pages read the type and the value; the table returns them as strings.
        $plugin = $this->plugin();

        $this->assertSame([
            49 => ['option_id' => '49', 'type' => '1', 'value' => '#000000'],
            52 => ['option_id' => '52', 'type' => '2', 'value' => '/g/o/gold.png'],
            166 => ['option_id' => '166', 'type' => '0', 'value' => 'XS'],
        ], $plugin->aroundGetSwatchesByOptionsId($this->createStub(SwatchHelper::class), $this->proceed(), [49, 52, 166]));
        $this->assertFalse($this->proceeded, 'a query per page is what this avoids');
    }

    public function testAnOptionWithoutASwatchIsAbsentAsInCores(): void
    {
        $plugin = $this->plugin();

        $this->assertSame(
            [49 => ['option_id' => '49', 'type' => '1', 'value' => '#000000']],
            $plugin->aroundGetSwatchesByOptionsId($this->createStub(SwatchHelper::class), $this->proceed(), [170, 49])
        );
    }

    public function testAnOptionNoDocumentHoldsStopsTheReadAndIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains(
            'Options 999 are in no attribute document of store view "default"'
        ));
        $plugin = $this->plugin($logger);

        $this->expectException(DocumentReadException::class);
        try {
            $plugin->aroundGetSwatchesByOptionsId($this->createStub(SwatchHelper::class), $this->proceed(), [49, 999]);
        } finally {
            $this->assertFalse($this->proceeded);
        }
    }

    public function testAStoreServedFromTheDatabaseAsksCore(): void
    {
        $plugin = $this->plugin();
        $this->served = false;
        $this->documents->expects($this->never())->method('optionsById');

        $this->assertSame(['core'], $plugin->aroundGetSwatchesByOptionsId($this->createStub(SwatchHelper::class), $this->proceed(), [49]));
        $this->assertTrue($this->proceeded);
    }
}
