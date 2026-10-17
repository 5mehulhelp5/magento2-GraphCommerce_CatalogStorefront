<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontUrlRewriteFrontend\Plugin;

use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use GraphCommerce\CatalogStorefrontUrlRewriteFrontend\Model\Read\UrlFinder;
use Magento\CatalogUrlRewrite\Model\Storage\DbStorage as CatalogStorage;
use Magento\CatalogUrlRewrite\Model\Storage\DynamicStorage;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\Storage\DbStorage;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;

/**
 * Answers every url rewrite lookup of a rendered page from the url rewrite documents, where the
 * store view of the request serves its listings or its product pages from documents. It wraps the
 * table storage and, through it, the catalog and dynamic storages that extend it, because callers
 * ask them directly as well as through the composite url finder: the product collection asks the
 * catalog storage for the urls of its products. A read that fails stops the page.
 */
class UrlFinderFromDocuments
{
    public function __construct(
        private readonly UrlFinder $urlFinder,
        private readonly Mode $mode,
        private readonly StoreManagerInterface $storeManager,
        private readonly Strict $strict,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return UrlRewrite|null
     */
    public function aroundFindOneByData(DbStorage $subject, \Closure $proceed, array $data)
    {
        if (!$this->fromDocuments()) {
            return $proceed($data);
        }
        try {
            return $this->urlFinder->findOneByData($data, $this->storage($subject));
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return UrlRewrite[]
     */
    public function aroundFindAllByData(DbStorage $subject, \Closure $proceed, array $data)
    {
        if (!$this->fromDocuments()) {
            return $proceed($data);
        }
        try {
            return $this->urlFinder->findAllByData($data, $this->storage($subject));
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);
        }
    }

    private function storage(DbStorage $subject): string
    {
        return match (true) {
            $subject instanceof DynamicStorage => UrlFinder::DYNAMIC,
            $subject instanceof CatalogStorage => UrlFinder::CATALOG,
            default => UrlFinder::TABLE,
        };
    }

    private function fromDocuments(): bool
    {
        $storeId = (int)$this->storeManager->getStore()->getId();

        return $this->mode->listing($storeId) || $this->mode->detail($storeId);
    }
}
