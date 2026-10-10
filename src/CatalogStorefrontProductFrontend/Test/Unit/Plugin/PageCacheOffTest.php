<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use GraphCommerce\CatalogStorefrontProductFrontend\Plugin\PageCacheOff;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\PageCache\Model\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PageCacheOffTest extends TestCase
{
    #[DataProvider('requests')]
    public function testPageCache(?string $requested, string $action, bool $cacheCategoryPages, bool $enabled, bool $expected): void
    {
        $mode = $this->createStub(Mode::class);
        $mode->method('requested')->willReturn($requested);
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('isSetFlag')->willReturnMap([
            [PageCacheOff::CACHE_CATEGORY_PAGES, 'store', null, $cacheCategoryPages],
        ]);
        $request = $this->createStub(HttpRequest::class);
        $request->method('getFullActionName')->willReturn($action);
        $plugin = new PageCacheOff($mode, $config, $request);

        self::assertSame($expected, $plugin->afterIsEnabled($this->createStub(Config::class), $enabled));
    }

    public static function requests(): iterable
    {
        yield 'ordinary enabled' => [null, 'cms_index_index', true, true, true];
        yield 'ordinary disabled' => [null, 'cms_index_index', true, false, false];
        yield 'keyed core' => [Mode::CORE, 'cms_index_index', true, true, false];
        yield 'keyed documents' => [Mode::DOCUMENTS, 'cms_index_index', true, true, false];
        yield 'keyed disabled' => [Mode::DOCUMENTS, 'cms_index_index', true, false, false];
        yield 'category cached' => [null, 'catalog_category_view', true, true, true];
        yield 'category uncached' => [null, 'catalog_category_view', false, true, false];
        yield 'product with category pages uncached' => [null, 'catalog_product_view', false, true, true];
        yield 'search with category pages uncached' => [null, 'catalogsearch_result_index', false, true, true];
    }
}
