<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontUrlRewriteFrontend\Model\Read;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontUrlRewrite\Model\Collation;
use GraphCommerce\CatalogStorefrontUrlRewrite\Model\Read\UrlRewriteRows;
use GraphCommerce\CatalogStorefrontUrlRewrite\Model\UrlRewriteDocument as Document;
use Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\OptionProvider;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\UrlRewrite\Service\V1\Data\UrlRewriteFactory;

/**
 * Core's url rewrite storages over the url rewrite documents.
 *
 * Three storages read the url rewrite table: the table storage, the catalog storage, which joins
 * the product category relation, and the dynamic storage, which builds the url of a product in a
 * category from the rewrite of the product and the rewrite of the category. With product rewrites
 * per category off, core hands the lookups of the catalog storage to the dynamic storage. A lookup
 * reads every row the storages can select for it in one search per store view, kept for the
 * request, so the composite finder's two storages share one search. The steps of the storage then
 * run on the rows: the same filters, the same first row, the same rewrite made of it. The table
 * compares its string columns through its collation and its integer columns as numbers, and so
 * does the finder.
 *
 * A lookup by request path takes the rows in the order of the request path index, any other lookup
 * in primary key order, as the table returns them for one entity. The dynamic storage asks whether
 * the product shows in the category, which the category ids of the product document answer: they
 * are the category product index. The answer to a lookup is kept for the request, because a page
 * repeats lookups (a category link in the menu, the breadcrumbs and the layered navigation).
 */
class UrlFinder implements ResetAfterRequestInterface
{
    public const TABLE = 'table';

    public const CATALOG = 'catalog';

    public const DYNAMIC = 'dynamic';

    private const CATEGORY_PRODUCT_REWRITES = 'catalog/seo/generate_category_product_rewrites';

    private const TARGET_IN_CATEGORY = '#^catalog/product/view/id/(\d+)/category/(\d+)$#';

    /** @var array<string, array|null> the rows of each answer, by storage, method and filter */
    private array $answers = [];

    /** @var array<string, array<int, array>> the rows read for a filter */
    private array $reads = [];

    public function __construct(
        private readonly UrlRewriteRows $urlRewriteRows,
        private readonly Collation $collation,
        private readonly ProductDocumentsInterface $products,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly UrlRewriteFactory $urlRewriteFactory,
        private readonly DataObjectHelper $dataObjectHelper,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param string $storage the storage asked: TABLE, CATALOG or DYNAMIC
     */
    public function findOneByData(array $data, string $storage): ?UrlRewrite
    {
        $storage = $this->answering($storage);
        $key = serialize([$storage, 'one', $data]);
        if (!array_key_exists($key, $this->answers)) {
            $rows = $this->read($data);
            $this->answers[$key] = $storage === self::DYNAMIC ? $this->dynamicOne($rows, $data) : $this->tableOne($rows, $data, $storage);
        }

        return $this->answers[$key] === null ? null : $this->urlRewrite($this->answers[$key]);
    }

    /**
     * @param array<string, mixed> $data
     * @param string $storage the storage asked: TABLE, CATALOG or DYNAMIC
     * @return UrlRewrite[]
     */
    public function findAllByData(array $data, string $storage): array
    {
        $storage = $this->answering($storage);
        $key = serialize([$storage, 'all', $data]);
        if (!array_key_exists($key, $this->answers)) {
            $rows = $this->read($data);
            $this->answers[$key] = match ($storage) {
                self::DYNAMIC => $this->dynamicAll($rows, $data),
                self::CATALOG => array_map(fn(array $document) => $this->row($document, self::CATALOG), array_values($this->related($rows, $data))),
                default => array_map(fn(array $document) => $this->row($document, self::TABLE), array_values($this->select($rows, $data))),
            };
        }

        return array_map(fn(array $row) => $this->urlRewrite($row), $this->answers[$key]);
    }

    public function _resetState(): void
    {
        $this->answers = [];
        $this->reads = [];
    }

    /**
     * The storage that answers: with product rewrites per category off, core hands the catalog
     * storage's lookups to the dynamic storage.
     */
    private function answering(string $storage): string
    {
        return $storage === self::CATALOG && !$this->categoryRewrites() ? self::DYNAMIC : $storage;
    }

    /**
     * The table storage's lookup of one row; the catalog storage takes the same steps over the
     * relation join.
     *
     * @param array<int, array> $rows
     */
    private function tableOne(array $rows, array $data, string $storage): ?array
    {
        if (array_key_exists(UrlRewrite::REQUEST_PATH, $data) && is_string($data[UrlRewrite::REQUEST_PATH])) {
            $requestPath = $data[UrlRewrite::REQUEST_PATH];
            $decodedPath = urldecode($requestPath);
            $data[UrlRewrite::REQUEST_PATH] = array_unique([
                rtrim($requestPath, '/'),
                rtrim($requestPath, '/') . '/',
                rtrim($decodedPath, '/'),
                rtrim($decodedPath, '/') . '/',
            ]);
            $found = $this->inPathOrder($storage === self::TABLE ? $this->select($rows, $data) : $this->related($rows, $data));
            if (!$found) {
                return null;
            }
            $row = $this->mostRelevant($requestPath, array_map(fn(array $document) => $this->row($document, $storage), $found));

            return $this->prepare($requestPath, $row);
        }
        $found = $storage === self::TABLE ? $this->select($rows, $data) : $this->related($rows, $data);

        return $found ? $this->row(reset($found), $storage) : null;
    }

    private function dynamicOne(array $rows, array $data): ?array
    {
        if (isset($data[UrlRewrite::REQUEST_PATH], $data[UrlRewrite::STORE_ID]) && is_string($data[UrlRewrite::REQUEST_PATH])) {
            return $this->dynamicByRequestPath($rows, $data);
        }
        if (isset($data[UrlRewrite::TARGET_PATH], $data[UrlRewrite::STORE_ID]) && !isset($data[UrlRewrite::ENTITY_TYPE])) {
            $found = $this->dynamicByTargetPath($rows, $data);
            if ($found !== null) {
                return $found;
            }
        }
        $found = $this->dynamicByFilter($rows, $data);

        return $found ? reset($found) : null;
    }

    private function dynamicAll(array $rows, array $data): array
    {
        $rewrites = array_map(fn(array $document) => $this->row($document, self::DYNAMIC), array_values($this->related($rows, $data)));
        $remaining = [];
        if (isset($data[UrlRewrite::ENTITY_ID]) && is_array($data[UrlRewrite::ENTITY_ID])) {
            $remaining = array_fill_keys($data[UrlRewrite::ENTITY_ID], 1);
            foreach ($rewrites as $rewrite) {
                unset($remaining[$rewrite[UrlRewrite::ENTITY_ID]]);
            }
        }
        if ($remaining) {
            $data[UrlRewrite::ENTITY_ID] = array_keys($remaining);
            $rewrites = array_merge($rewrites, $this->dynamicByFilter($rows, $data));
        }

        return $rewrites;
    }

    private function dynamicByRequestPath(array $rows, array $data): ?array
    {
        $requestPath = $data[UrlRewrite::REQUEST_PATH];
        $productUrl = $this->baseName($requestPath);
        $data[UrlRewrite::REQUEST_PATH] = [$productUrl];
        $data[UrlRewrite::ENTITY_TYPE] = 'product';
        $product = $this->dynamicFirst($rows, $data);
        if ($product === null) {
            return null;
        }
        $categorySuffix = $this->categorySuffix($data[UrlRewrite::STORE_ID]);
        $categoryPath = substr($requestPath, 0, -1 * strlen('/' . $productUrl));
        if ($product[UrlRewrite::REDIRECT_TYPE]) {
            $productUrl = $product[UrlRewrite::TARGET_PATH];
        }
        if ($categoryPath) {
            $data[UrlRewrite::REQUEST_PATH] = [$categoryPath . $categorySuffix];
            unset($data[UrlRewrite::IS_AUTOGENERATED]);
            $data[UrlRewrite::ENTITY_TYPE] = 'category';
            $category = $this->dynamicFirst($rows, $data);
            if ($category === null) {
                return null;
            }
            if ($category[UrlRewrite::REDIRECT_TYPE]) {
                $product[UrlRewrite::REDIRECT_TYPE] = OptionProvider::PERMANENT;
                $categoryPath = str_replace($categorySuffix, '', $category[UrlRewrite::TARGET_PATH] ?? '');
            }
            if (!$this->showsInCategory((int)$product[UrlRewrite::ENTITY_ID], (int)$category[UrlRewrite::ENTITY_ID])) {
                return null;
            }
            $product[UrlRewrite::TARGET_PATH] = $product[UrlRewrite::TARGET_PATH] . '/category/' . $category[UrlRewrite::ENTITY_ID];
        }
        if ($product[UrlRewrite::REDIRECT_TYPE]) {
            $product[UrlRewrite::TARGET_PATH] = $categoryPath . '/' . $productUrl;
        }
        $product[UrlRewrite::REQUEST_PATH] = $requestPath;

        return $product;
    }

    private function dynamicByTargetPath(array $rows, array $data): ?array
    {
        if (!preg_match(self::TARGET_IN_CATEGORY, $data[UrlRewrite::TARGET_PATH], $matches)) {
            return null;
        }
        [, $productId, $categoryId] = $matches;
        $storeId = $data[UrlRewrite::STORE_ID];
        $product = $this->dynamicFirst($rows, [
            UrlRewrite::ENTITY_TYPE => 'product',
            UrlRewrite::ENTITY_ID => $productId,
            UrlRewrite::STORE_ID => $storeId,
            UrlRewrite::METADATA => ['category_id' => $categoryId],
        ]);
        if ($product === null) {
            return null;
        }
        $categorySuffix = $this->categorySuffix($storeId);
        $category = $this->dynamicFirst($rows, [
            UrlRewrite::ENTITY_TYPE => 'category',
            UrlRewrite::ENTITY_ID => $categoryId,
            UrlRewrite::STORE_ID => $storeId,
        ]);
        if ($category !== null) {
            $productUrl = $this->baseName((string)$product[UrlRewrite::REQUEST_PATH]);
            $product[UrlRewrite::REQUEST_PATH] = str_replace($categorySuffix, '', $category[UrlRewrite::REQUEST_PATH] ?? '')
                . '/' . $productUrl;
            $product[UrlRewrite::TARGET_PATH] = $product[UrlRewrite::TARGET_PATH] . '/category/' . $categoryId;
        }

        return $product;
    }

    /**
     * @return array<int, array> the rewrites of the products, with the category path where the metadata names a category
     */
    private function dynamicByFilter(array $rows, array $data): array
    {
        if (empty($data[UrlRewrite::ENTITY_TYPE])) {
            return [];
        }
        $metadata = $data[UrlRewrite::METADATA] ?? [];
        unset($data[UrlRewrite::METADATA]);
        $products = array_map(fn(array $document) => $this->row($document, self::DYNAMIC), array_values($this->related($rows, $data)));
        $categoryId = is_array($metadata) && !empty($metadata['category_id']) ? $metadata['category_id'] : null;
        if ($categoryId === null) {
            return $products;
        }
        $data[UrlRewrite::ENTITY_ID] = $categoryId;
        $data[UrlRewrite::ENTITY_TYPE] = 'category';
        $category = $this->dynamicFirst($rows, $data);
        $categoryPath = str_replace($this->categorySuffix($data[UrlRewrite::STORE_ID] ?? null), '', $category[UrlRewrite::REQUEST_PATH] ?? '');

        return array_map(function (array $product) use ($categoryPath): array {
            $product[UrlRewrite::REQUEST_PATH] = $categoryPath . '/' . $this->baseName((string)$product[UrlRewrite::REQUEST_PATH]);

            return $product;
        }, $products);
    }

    private function dynamicFirst(array $rows, array $data): ?array
    {
        $found = $this->related($rows, $data);
        if (isset($data[UrlRewrite::REQUEST_PATH])) {
            $found = $this->inPathOrder($found);
        }

        return $found ? $this->row(reset($found), self::DYNAMIC) : null;
    }

    /**
     * The table storage's select: every filter is a column, compared with IN.
     *
     * @param array<int, array> $rows
     * @return array<int, array> in id order
     */
    private function select(array $rows, array $data): array
    {
        return array_filter($rows, function (array $document) use ($data): bool {
            foreach ($data as $column => $values) {
                if (!$this->matches($document, (string)$column, (array)$values)) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * The catalog and dynamic storages' select: the metadata is not a column, it names the category
     * of the product category relation, and without one the row has no relation.
     */
    private function related(array $rows, array $data): array
    {
        $metadata = $data[UrlRewrite::METADATA] ?? null;
        unset($data[UrlRewrite::METADATA]);
        $categoryId = is_array($metadata) && !empty($metadata['category_id']) ? $metadata['category_id'] : null;

        return array_filter(
            $this->select($rows, $data),
            static fn(array $document): bool => $categoryId === null
                ? $document['category_id'] === null
                : $document['category_id'] !== null && is_scalar($categoryId) && (float)$document['category_id'] === (float)$categoryId
        );
    }

    /**
     * A row matches a column filter as the table compares: a string column through the collation, or
     * as a number for a number in the filter; an integer column as a number.
     */
    private function matches(array $document, string $column, array $values): bool
    {
        $isString = in_array($column, Document::STRINGS, true);
        if (!$isString && !in_array($column, Document::INTEGERS, true)) {
            throw new \InvalidArgumentException('The url rewrite table has no column ' . $column);
        }
        $value = $document[$column] ?? null;
        if ($value === null) {
            return false;
        }
        foreach ($values as $wanted) {
            if (!is_scalar($wanted)) {
                continue;
            }
            if ($isString && is_string($wanted)) {
                $key = $this->collation->fold($wanted);
                if ($key !== null && $key === $document[$column . Document::KEY_SUFFIX]) {
                    return true;
                }
            } elseif ((float)$value === (float)$wanted) {
                return true;
            }
        }

        return false;
    }

    /**
     * The order of the request path index: by request path, as the collation sorts it, then by store.
     *
     * @param array<int, array> $documents
     * @return array<int, array>
     */
    private function inPathOrder(array $documents): array
    {
        uasort($documents, static fn(array $a, array $b): int => [
            (string)$a['request_path' . Document::KEY_SUFFIX],
            (int)$a[UrlRewrite::STORE_ID],
        ] <=> [
            (string)$b['request_path' . Document::KEY_SUFFIX],
            (int)$b[UrlRewrite::STORE_ID],
        ]);

        return $documents;
    }

    /**
     * The row a storage selects: the table's columns, with the relation's for the catalog storage.
     * The dynamic storage selects every column of the relation over the row, so a row without a
     * relation has no id.
     */
    private function row(array $document, string $storage): array
    {
        $row = array_intersect_key($document, array_flip([...Document::STRINGS, ...Document::INTEGERS]));
        if ($storage === self::TABLE) {
            return $row;
        }
        if ($storage === self::DYNAMIC) {
            $related = $document['category_id'] !== null || $document['product_id'] !== null;
            $row[UrlRewrite::URL_REWRITE_ID] = $related ? $document[UrlRewrite::URL_REWRITE_ID] : null;
        }

        return $row + ['category_id' => $document['category_id'], 'product_id' => $document['product_id']];
    }

    private function mostRelevant(string $requestPath, array $rows): array
    {
        $prioritized = [];
        foreach ($rows as $row) {
            $rowPath = $row[UrlRewrite::REQUEST_PATH];
            $trimmedRowPath = rtrim($rowPath ?? '', '/');
            $priority = match (true) {
                $trimmedRowPath === rtrim($row[UrlRewrite::TARGET_PATH] ?? '', '/') => 99,
                $rowPath === $requestPath => 1,
                $rowPath === urldecode($requestPath) => 2,
                $trimmedRowPath === rtrim($requestPath, '/') => 3,
                $trimmedRowPath === rtrim(urldecode($requestPath), '/') => 4,
                default => 5,
            };
            $prioritized[$priority] = $row;
        }
        ksort($prioritized);

        return array_shift($prioritized);
    }

    /**
     * A row found for another form of the request path, which is no redirect itself, becomes a
     * permanent redirect to the row's path.
     */
    private function prepare(string $requestPath, array $row): array
    {
        if (in_array($row[UrlRewrite::REQUEST_PATH], [$requestPath, urldecode($requestPath)], true)
            || in_array((int)$row[UrlRewrite::REDIRECT_TYPE], [OptionProvider::TEMPORARY, OptionProvider::PERMANENT], true)
        ) {
            return $row;
        }

        return [
            UrlRewrite::ENTITY_TYPE => 'custom',
            UrlRewrite::ENTITY_ID => '0',
            UrlRewrite::REQUEST_PATH => $requestPath,
            UrlRewrite::TARGET_PATH => $row[UrlRewrite::REQUEST_PATH],
            UrlRewrite::REDIRECT_TYPE => OptionProvider::PERMANENT,
            UrlRewrite::STORE_ID => $row[UrlRewrite::STORE_ID],
            UrlRewrite::DESCRIPTION => null,
            UrlRewrite::IS_AUTOGENERATED => '0',
            UrlRewrite::METADATA => null,
        ];
    }

    /**
     * Reads the rows every storage's steps can select for a lookup, in one search per store view,
     * once per request. The search filters on a superset; the steps compare exactly.
     *
     * @return array<int, array> by url rewrite id, in id order
     */
    private function read(array $data): array
    {
        $key = serialize($data);
        if (isset($this->reads[$key])) {
            return $this->reads[$key];
        }
        $filters = [$data];
        $requestPath = $data[UrlRewrite::REQUEST_PATH] ?? null;
        if (is_string($requestPath)) {
            $decodedPath = urldecode($requestPath);
            $filters[0][UrlRewrite::REQUEST_PATH] = [
                rtrim($requestPath, '/'),
                rtrim($requestPath, '/') . '/',
                rtrim($decodedPath, '/'),
                rtrim($decodedPath, '/') . '/',
            ];
        }
        if (!$this->categoryRewrites()) {
            if (is_string($requestPath)) {
                $productUrl = $this->baseName($requestPath);
                $filters[] = [UrlRewrite::REQUEST_PATH => $productUrl, UrlRewrite::ENTITY_TYPE => 'product'];
                $categoryPath = substr($requestPath, 0, -1 * strlen('/' . $productUrl));
                if ($categoryPath) {
                    $filters[] = [
                        UrlRewrite::REQUEST_PATH => $categoryPath . $this->categorySuffix($data[UrlRewrite::STORE_ID] ?? null),
                        UrlRewrite::ENTITY_TYPE => 'category',
                    ];
                }
            }
            $targetPath = $data[UrlRewrite::TARGET_PATH] ?? null;
            if (is_string($targetPath) && preg_match(self::TARGET_IN_CATEGORY, $targetPath, $matches)) {
                $filters[] = [UrlRewrite::ENTITY_TYPE => 'product', UrlRewrite::ENTITY_ID => $matches[1]];
                $filters[] = [UrlRewrite::ENTITY_TYPE => 'category', UrlRewrite::ENTITY_ID => $matches[2]];
            }
            $metadata = $data[UrlRewrite::METADATA] ?? null;
            if (is_array($metadata) && !empty($metadata['category_id'])) {
                $filters[] = [UrlRewrite::ENTITY_TYPE => 'category', UrlRewrite::ENTITY_ID => $metadata['category_id']];
            }
        }
        $alternatives = array_values(array_filter(array_map(fn(array $filter) => $this->terms($filter), $filters), 'is_array'));

        $codes = [];
        foreach ($this->storeManager->getStores() as $store) {
            $codes[(int)$store->getId()] = (string)$store->getCode();
        }
        if (array_key_exists(UrlRewrite::STORE_ID, $data)) {
            $wanted = array_map(
                static fn($storeId): int => (int)(float)$storeId,
                array_filter((array)$data[UrlRewrite::STORE_ID], 'is_scalar')
            );
            $codes = array_intersect_key($codes, array_flip($wanted));
        }

        $rows = [];
        foreach ($codes as $code) {
            $rows += $this->urlRewriteRows->rows($code, $alternatives);
        }
        ksort($rows);

        return $this->reads[$key] = $rows;
    }

    /**
     * The declared fields a filter selects on: a string column by its folded key unless the filter
     * holds a number, an integer column by its value, the store by its index, the metadata not at all.
     *
     * @return array<string, string[]>|null null where no row can match
     */
    private function terms(array $filter): ?array
    {
        $terms = [];
        foreach ($filter as $column => $values) {
            if ($column === UrlRewrite::STORE_ID || $column === UrlRewrite::METADATA) {
                continue;
            }
            $values = array_filter((array)$values, 'is_scalar');
            if (in_array($column, Document::STRINGS, true)) {
                if (array_filter($values, static fn($value): bool => !is_string($value))) {
                    continue;
                }
                $keys = array_filter(array_map(fn(string $value) => $this->collation->fold($value), $values), 'is_string');
                if ($keys === []) {
                    return null;
                }
                $terms[$column . Document::KEY_SUFFIX] = array_values(array_unique($keys));
            } elseif (in_array($column, Document::INTEGERS, true)) {
                if ($values === []) {
                    return null;
                }
                $terms[(string)$column] = array_values(array_unique(array_map(static fn($value): string => (string)(int)(float)$value, $values)));
            } else {
                throw new \InvalidArgumentException('The url rewrite table has no column ' . $column);
            }
        }

        return $terms;
    }

    private function showsInCategory(int $productId, int $categoryId): bool
    {
        $storeViewCode = (string)$this->storeManager->getStore()->getCode();
        $document = $this->products->documents($storeViewCode, [$productId])[$productId] ?? null;
        if (!is_array($document) || !array_key_exists('categoryIds', $document)) {
            throw new DocumentReadException(sprintf(
                'Product %d of store view "%s" requires a document with its category ids',
                $productId,
                $storeViewCode
            ));
        }

        return in_array($categoryId, array_map('intval', (array)$document['categoryIds']), true);
    }

    private function categoryRewrites(): bool
    {
        return (bool)$this->scopeConfig->getValue(self::CATEGORY_PRODUCT_REWRITES);
    }

    private function categorySuffix(mixed $storeId): string
    {
        return (string)($this->scopeConfig->getValue(
            CategoryUrlPathGenerator::XML_PATH_CATEGORY_URL_SUFFIX,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?? '');
    }

    private function baseName(string $path): string
    {
        return (string)preg_replace('|.*?([^/]+)$|', '\1', $path, 1);
    }

    private function urlRewrite(array $row): UrlRewrite
    {
        $urlRewrite = $this->urlRewriteFactory->create();
        $this->dataObjectHelper->populateWithArray($urlRewrite, $row, UrlRewrite::class);

        return $urlRewrite;
    }
}
