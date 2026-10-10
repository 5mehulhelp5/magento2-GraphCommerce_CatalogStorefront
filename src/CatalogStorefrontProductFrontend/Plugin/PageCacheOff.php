<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Plugin;

use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\PageCache\Model\Config;
use Magento\Store\Model\ScopeInterface;

/**
 * The page cache is off for a request that picks its path by header, so each parity
 * check renders the selected reader, and for a category page of a store view that keeps
 * its category pages out of the page cache. Such a response carries no public cache
 * headers, so neither Varnish nor the built-in cache stores it, and the blocks Varnish
 * includes by ESI render inline.
 */
class PageCacheOff
{
    public const CACHE_CATEGORY_PAGES = 'catalog/storefront_documents/cache_category_pages';

    public function __construct(
        private readonly Mode $mode,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly RequestInterface $request,
    ) {
    }

    public function afterIsEnabled(Config $subject, bool $result): bool
    {
        return $result
            && $this->mode->requested() === null
            && !($this->request instanceof HttpRequest
                && $this->request->getFullActionName() === 'catalog_category_view'
                && !$this->scopeConfig->isSetFlag(self::CACHE_CATEGORY_PAGES, ScopeInterface::SCOPE_STORE));
    }
}
