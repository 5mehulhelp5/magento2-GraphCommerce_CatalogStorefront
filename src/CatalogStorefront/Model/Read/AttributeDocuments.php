<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Read;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The store view's attribute documents by code, fetched once per request and
 * code: labels, frontend input, flags and the options with their labels, from
 * the product attributes feed.
 */
class AttributeDocuments implements ResetAfterRequestInterface
{
    /** @var array<string, array<string, array|null>> per store view and code; null marks a code without a document */
    private array $byStore = [];

    /** @var array<string, bool> per store view: whether the feed has landed */
    private array $available = [];

    private array $all = [];

    public function __construct(
        private readonly MetadataDocumentStorageInterface $storage,
    ) {
    }

    /**
     * Answers from every document of the store view once those are read, so a page that lists them
     * fetches no code again.
     *
     * @param string[] $codes
     * @return array<string, array> the documents of the codes that have one, keyed by code
     */
    public function byCodes(string $storeViewCode, array $codes): array
    {
        if (isset($this->all[$storeViewCode])) {
            return array_intersect_key($this->all[$storeViewCode], array_flip($codes));
        }
        $known = $this->byStore[$storeViewCode] ?? [];
        $missing = array_values(array_diff($codes, array_keys($known)));
        if ($missing) {
            $fetched = $this->storage->get('attribute', $storeViewCode, $missing);
            foreach ($missing as $code) {
                $known[$code] = $fetched[$code] ?? null;
            }
            $this->byStore[$storeViewCode] = $known;
        }

        return array_filter(array_intersect_key($known, array_flip($codes)));
    }

    /**
     * The options of the ids, from the documents this request has read; when one is not among
     * them, from every document of the store view, read once. A caller that knows only option ids,
     * as core's swatch helper does, cannot name the attribute.
     *
     * @param array<int|string> $optionIds
     * @return array<string, array> the options found, keyed by option id; an id that no document holds is absent
     */
    public function optionsById(string $storeViewCode, array $optionIds): array
    {
        $wanted = array_flip(array_map('strval', $optionIds));
        $options = $this->options($this->all[$storeViewCode] ?? array_filter($this->byStore[$storeViewCode] ?? []), $wanted);
        if (count($options) < count($wanted) && !isset($this->all[$storeViewCode])) {
            $options = $this->options($this->all($storeViewCode), $wanted);
        }

        return $options;
    }

    /**
     * @param array<string, array> $documents
     * @param array<string, int> $wanted the option ids as keys
     * @return array<string, array> the wanted options of the documents, keyed by option id
     */
    private function options(array $documents, array $wanted): array
    {
        $options = [];
        foreach ($documents as $document) {
            foreach ((array)($document['options'] ?? []) as $option) {
                $id = (string)($option['id'] ?? '');
                if (isset($wanted[$id])) {
                    $options[$id] = $option;
                }
            }
        }

        return $options;
    }

    /**
     * @return array<string, array> every attribute document of the store view, by code
     */
    public function all(string $storeViewCode): array
    {
        return $this->all[$storeViewCode] ??= $this->storage->all('attribute', $storeViewCode);
    }

    public function available(string $storeViewCode): bool
    {
        return $this->available[$storeViewCode] ??= $this->storage->count('attribute', $storeViewCode) > 0;
    }

    public function _resetState(): void
    {
        $this->byStore = [];
        $this->available = [];
        $this->all = [];
    }
}
