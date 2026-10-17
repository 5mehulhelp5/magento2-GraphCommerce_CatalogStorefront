<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontUrlRewriteFrontend\Test\Unit\Model\Read;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontUrlRewrite\Model\Collation;
use GraphCommerce\CatalogStorefrontUrlRewrite\Model\Read\UrlRewriteRows;
use GraphCommerce\CatalogStorefrontUrlRewrite\Model\UrlRewriteDocument;
use GraphCommerce\CatalogStorefrontUrlRewriteFrontend\Model\Read\UrlFinder;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\UrlRewrite\Service\V1\Data\UrlRewriteFactory;
use PHPUnit\Framework\TestCase;

/**
 * The expected answers are the ones core's storages give for the same table rows. The router asks
 * the composite url finder, which asks the table storage and then the catalog storage.
 */
class UrlFinderTest extends TestCase
{
    /** @var array<int, string> the store view codes the finder read, in order */
    private array $reads = [];

    private Collation $collation;

    protected function setUp(): void
    {
        $map = array_combine(range(97, 122), range('A', 'Z'));
        $map[mb_ord('é')] = 'E';
        $this->collation = new Collation($map);
    }

    /**
     * @param array<int, array<string, ?string>> $rows table rows with their relation, by id
     */
    private function finder(array $rows, bool $categoryRewrites = false, array $products = []): UrlFinder
    {
        $this->reads = [];
        $documents = [];
        foreach ($rows as $id => $row) {
            $document = $row + array_fill_keys(array_values(UrlRewriteDocument::COLUMNS), null);
            $document['url_rewrite_id'] = (string)$id;
            foreach (UrlRewriteDocument::STRINGS as $column) {
                $document[$column . UrlRewriteDocument::KEY_SUFFIX] = $this->collation->fold($document[$column]);
            }
            $documents[$id] = $document;
        }
        $storeIds = ['default' => '1', 'second' => '2'];
        $urlRewriteRows = $this->createMock(UrlRewriteRows::class);
        $urlRewriteRows->method('rows')->willReturnCallback(
            function (string $code, array $alternatives) use ($documents, $storeIds): array {
                $this->reads[] = $code;
                // The search: the rows of the store view that match any alternative on the declared fields.
                return array_filter($documents, static function (array $document) use ($code, $storeIds, $alternatives): bool {
                    if ($document['store_id'] !== $storeIds[$code]) {
                        return false;
                    }
                    foreach ($alternatives as $alternative) {
                        foreach ($alternative as $field => $values) {
                            if (!in_array((string)$document[$field], $values, true)) {
                                continue 2;
                            }
                        }
                        return true;
                    }
                    return false;
                });
            }
        );

        $productDocuments = $this->createStub(ProductDocumentsInterface::class);
        $productDocuments->method('documents')->willReturnCallback(
            static fn(string $code, array $ids): array => array_intersect_key($products, array_flip($ids))
        );
        $stores = [];
        foreach ($storeIds as $code => $id) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            $store->method('getCode')->willReturn($code);
            $stores[] = $store;
        }
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);
        $storeManager->method('getStore')->willReturn($stores[0]);
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(static fn(string $path) => match ($path) {
            'catalog/seo/generate_category_product_rewrites' => $categoryRewrites ? '1' : '0',
            'catalog/seo/category_url_suffix' => '.html',
            default => null,
        });
        $factory = $this->createStub(UrlRewriteFactory::class);
        $factory->method('create')->willReturnCallback(static fn() => new UrlRewrite([], new Json()));
        $helper = $this->createStub(DataObjectHelper::class);
        $helper->method('populateWithArray')->willReturnCallback(static function (UrlRewrite $urlRewrite, array $row) {
            foreach ($row as $key => $value) {
                $setter = 'set' . str_replace('_', '', ucwords($key, '_'));
                if (method_exists($urlRewrite, $setter)) {
                    $urlRewrite->$setter($value);
                }
            }
        });

        return new UrlFinder($urlRewriteRows, $this->collation, $productDocuments, $storeManager, $config, $factory, $helper);
    }

    private static function row(string $type, string $entityId, string $requestPath, string $targetPath, array $more = []): array
    {
        return $more + [
            'entity_type' => $type,
            'entity_id' => $entityId,
            'request_path' => $requestPath,
            'target_path' => $targetPath,
            'redirect_type' => '0',
            'store_id' => '1',
            'is_autogenerated' => '1',
        ];
    }

    /**
     * @return array<int, array> the rows of a catalog with product rewrites per category off
     */
    private static function rows(): array
    {
        return [
            10 => self::row('category', '25', 'women/tops-women.html', 'catalog/category/view/id/25'),
            11 => self::row('category', '25', 'women/tops-women.html', 'catalog/category/view/id/25', ['store_id' => '2']),
            20 => self::row('product', '1812', 'breathe-easy-tank.html', 'catalog/product/view/id/1812'),
            21 => self::row('product', '1813', 'antonia-racer-tank.html', 'catalog/product/view/id/1813'),
            22 => self::row('product', '1814', 'no-document.html', 'catalog/product/view/id/1814'),
            30 => self::row('custom', '0', 'old-tops.html', 'women/tops-women.html', ['redirect_type' => '301', 'is_autogenerated' => '0']),
            40 => self::row('custom', '0', 'gc-parite.html', 'women/tops-women.html', ['is_autogenerated' => '0']),
        ];
    }

    private static function products(): array
    {
        return [1812 => ['categoryIds' => [2, 20, 25]], 1813 => ['categoryIds' => [2, 20]], 1814 => ['sku' => 'no-category-ids']];
    }

    private static function lookup(string $requestPath, int $storeId = 1): array
    {
        return [UrlRewrite::REQUEST_PATH => $requestPath, UrlRewrite::STORE_ID => $storeId];
    }

    /**
     * @return array<string, mixed> the lookup of the product collection of category 25
     */
    private static function collection(array $productIds): array
    {
        return [
            UrlRewrite::ENTITY_TYPE => 'product',
            UrlRewrite::ENTITY_ID => $productIds,
            UrlRewrite::STORE_ID => 1,
            UrlRewrite::IS_AUTOGENERATED => 1,
            UrlRewrite::METADATA => ['category_id' => 25],
        ];
    }

    public function testThePathOfARowRoutesToItsTarget(): void
    {
        $rewrite = $this->finder(self::rows())->findOneByData(self::lookup('women/tops-women.html'), UrlFinder::TABLE);

        $this->assertSame('10', $rewrite->getUrlRewriteId());
        $this->assertSame('catalog/category/view/id/25', $rewrite->getTargetPath());
        $this->assertSame('women/tops-women.html', $rewrite->getRequestPath());
    }

    public function testAnotherFormOfThePathRedirectsToTheRowPermanently(): void
    {
        $finder = $this->finder(self::rows());
        foreach (['Women/Tops-Women.html', 'women/tops-women.html/', 'gc-parité.html', 'gc-parit%C3%A9.html'] as $requestPath) {
            $rewrite = $finder->findOneByData(self::lookup($requestPath), UrlFinder::TABLE);
            $this->assertSame('custom', $rewrite->getEntityType(), $requestPath);
            $this->assertSame(301, $rewrite->getRedirectType(), $requestPath);
            $this->assertSame($requestPath, $rewrite->getRequestPath(), $requestPath);
            $this->assertSame(str_starts_with($requestPath, 'gc-') ? 'gc-parite.html' : 'women/tops-women.html', $rewrite->getTargetPath());
        }
    }

    public function testARedirectRowIsTheAnswerAsItIs(): void
    {
        $rewrite = $this->finder(self::rows())->findOneByData(self::lookup('old-tops.html'), UrlFinder::TABLE);

        $this->assertSame('30', $rewrite->getUrlRewriteId());
        $this->assertSame(301, $rewrite->getRedirectType());
        $this->assertSame('women/tops-women.html', $rewrite->getTargetPath());
    }

    public function testAProductInACategoryRoutesThroughTheCatalogStorageFromTheProductAndTheCategoryRewrite(): void
    {
        $finder = $this->finder(self::rows(), false, self::products());
        $lookup = self::lookup('women/tops-women/breathe-easy-tank.html');

        $this->assertNull($finder->findOneByData($lookup, UrlFinder::TABLE), 'the table holds no such path');
        $rewrite = $finder->findOneByData($lookup, UrlFinder::CATALOG);
        $this->assertNull($rewrite->getUrlRewriteId(), 'the dynamic storage joins the relation over the row');
        $this->assertSame('1812', $rewrite->getEntityId());
        $this->assertSame('women/tops-women/breathe-easy-tank.html', $rewrite->getRequestPath());
        $this->assertSame('catalog/product/view/id/1812/category/25', $rewrite->getTargetPath());
        $this->assertSame(['default'], $this->reads, 'both storages answer from one read');
    }

    public function testAProductOutsideTheCategoryOfThePathHasNoRoute(): void
    {
        $finder = $this->finder(self::rows(), false, self::products());

        $this->assertNull($finder->findOneByData(self::lookup('women/tops-women/antonia-racer-tank.html'), UrlFinder::CATALOG));
        $this->assertNull($finder->findOneByData(self::lookup('women/missing/breathe-easy-tank.html'), UrlFinder::CATALOG));
        $this->assertNull(
            $finder->findOneByData(self::lookup('catalog/category/view/id/25'), UrlFinder::CATALOG),
            'the router asks the target after the forward'
        );
    }

    public function testAProductWithoutItsCategoryIdsStopsTheRead(): void
    {
        $this->expectException(DocumentReadException::class);

        $this->finder(self::rows(), false, self::products())
            ->findOneByData(self::lookup('women/tops-women/no-document.html'), UrlFinder::CATALOG);
    }

    public function testTheCatalogStorageGivesTheProductsOfACollectionThePathOfItsCategory(): void
    {
        $finder = $this->finder(self::rows());

        $this->assertSame(
            ['women/tops-women/breathe-easy-tank.html', 'women/tops-women/antonia-racer-tank.html'],
            array_map(static fn(UrlRewrite $rewrite) => $rewrite->getRequestPath(), $finder->findAllByData(self::collection([1812, 1813]), UrlFinder::CATALOG))
        );
        $this->assertSame([], $finder->findAllByData(self::collection([1812, 1813]), UrlFinder::TABLE), 'no row holds that metadata');
        $this->assertCount(2, $finder->findAllByData(self::collection([1812, 1813]), UrlFinder::DYNAMIC));
    }

    public function testTheUrlOfOneProductTakesThePathOfTheCategoryOfTheMetadata(): void
    {
        $finder = $this->finder(self::rows());

        $this->assertSame('women/tops-women/breathe-easy-tank.html', $finder->findOneByData([
            UrlRewrite::ENTITY_TYPE => 'product',
            UrlRewrite::ENTITY_ID => '1812',
            UrlRewrite::STORE_ID => '1',
            UrlRewrite::METADATA => ['category_id' => '25'],
        ], UrlFinder::CATALOG)->getRequestPath());
        $this->assertSame('breathe-easy-tank.html', $finder->findOneByData([
            UrlRewrite::ENTITY_TYPE => 'product',
            UrlRewrite::ENTITY_ID => '01812',
            UrlRewrite::STORE_ID => 1,
        ], UrlFinder::TABLE)->getRequestPath(), 'an integer column compares as a number');
    }

    public function testWithProductRewritesPerCategoryTheCatalogStorageAnswersWithTheRelation(): void
    {
        $rows = self::rows() + [50 => self::row(
            'product',
            '1812',
            'women/tops-women/breathe-easy-tank.html',
            'catalog/product/view/id/1812/category/25',
            ['metadata' => '{"category_id":"25"}', 'category_id' => '25', 'product_id' => '1812']
        )];
        $finder = $this->finder($rows, true);

        $this->assertSame([], $finder->findAllByData(self::collection([1812]), UrlFinder::TABLE));
        $this->assertSame(
            ['50'],
            array_map(static fn(UrlRewrite $rewrite) => $rewrite->getUrlRewriteId(), $finder->findAllByData(self::collection([1812]), UrlFinder::CATALOG))
        );
        $this->assertSame('50', $finder->findOneByData(['entity_id' => 1812] + self::collection([]), UrlFinder::CATALOG)->getUrlRewriteId());
    }

    public function testAStoreFilterReadsThatStoreViewOnly(): void
    {
        $finder = $this->finder(self::rows());

        $this->assertSame('11', $finder->findOneByData(self::lookup('women/tops-women.html', 2), UrlFinder::TABLE)->getUrlRewriteId());
        $this->assertSame(['second'], $this->reads);
    }

    public function testALookupIsReadOncePerRequest(): void
    {
        $finder = $this->finder(self::rows());

        $finder->findOneByData(self::lookup('women/tops-women.html'), UrlFinder::TABLE);
        $finder->findOneByData(self::lookup('women/tops-women.html'), UrlFinder::TABLE);
        $finder->findOneByData(self::lookup('women/tops-women.html'), UrlFinder::CATALOG);
        $this->assertSame(['default'], $this->reads);

        $finder->_resetState();
        $finder->findOneByData(self::lookup('women/tops-women.html'), UrlFinder::TABLE);
        $this->assertSame(['default', 'default'], $this->reads);
    }

    public function testAColumnTheTableDoesNotHaveFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->finder(self::rows())->findAllByData(['category_id' => 25, UrlRewrite::STORE_ID => 1], UrlFinder::TABLE);
    }
}
