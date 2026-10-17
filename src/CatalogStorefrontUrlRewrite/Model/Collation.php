<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontUrlRewrite\Model;

/**
 * Folds a string the way the url rewrite table compares it.
 *
 * MySQL and MariaDB compare the string columns of the table through the collation of the column.
 * A general_ci collation gives each character one weight, in which case and most accents fold
 * together, and ignores trailing spaces. The fold maps each character to its weight, so two strings
 * the table finds equal fold to the same key. The weights come from the database
 * (`dev/url-rewrite/collation-map.php` writes `data/general_ci.php`): the map holds the characters
 * of the basic multilingual plane whose weight is another character, every other character of that
 * plane is its own weight, and every character above it weighs U+FFFD.
 */
class Collation
{
    /** The collations of the general_ci family, which give every character the weight of the map. */
    public const SUPPORTED = ['utf8mb4_general_ci', 'utf8mb3_general_ci', 'utf8_general_ci'];

    private const MAP = __DIR__ . '/../data/general_ci.php';

    /**
     * @param array<int, string>|null $weights the weight of each character that is not its own; the generated map by default
     */
    public function __construct(
        private ?array $weights = null,
    ) {
    }

    /**
     * @return string|null the folded key; null for null and for a string that is not UTF-8, which no row matches
     */
    public function fold(?string $value): ?string
    {
        if ($value === null || !mb_check_encoding($value, 'UTF-8')) {
            return null;
        }
        if ($this->weights === null) {
            if (!is_file(self::MAP)) {
                throw new \RuntimeException('The collation map ' . self::MAP . ' is missing: run dev/url-rewrite/collation-map.php');
            }
            $this->weights = require self::MAP;
        }

        $key = '';
        foreach (mb_str_split(rtrim($value, ' ')) as $character) {
            $codePoint = mb_ord($character);
            $key .= $codePoint > 0xFFFF ? "\u{FFFD}" : ($this->weights[$codePoint] ?? $character);
        }

        return $key;
    }
}
