<?php

declare(strict_types=1);

namespace Wicket\Directory\Query;

use Wicket\Directory\Config\DirectoryConfig;

/**
 * A visitor's search, filter, sort and page choices for one directory.
 *
 * Parameters are flat GET keys prefixed with the directory ID, e.g.
 * `wd12_keyword`, `wd12_location`, `wd12_sort`, `wd12_pg` and
 * `wd12_{facet_key}[]`. The prefix keeps two directories on one page apart.
 * Flat keys let the base search-form component pre-fill from $_GET itself.
 *
 * Instances only come from from_request(), so every value is sanitized and
 * validated: unknown facet values and sorts are dropped, the page is at least 1,
 * and params for controls the block hides are ignored.
 *
 * @phpstan-import-type Facet from DirectoryConfig
 */
final class RequestParams
{
    /**
     * Longest keyword or location kept, in characters.
     */
    public const MAX_TEXT_LENGTH = 200;

    /**
     * Use from_request().
     *
     * @param int                         $directory_id Directory post ID; the param prefix.
     * @param string                      $keyword      Keyword search, or ''.
     * @param string                      $location     Location search, or ''.
     * @param string                      $sort         Sort token, one of DirectoryType::sort_options().
     * @param int                         $page         Page number, 1 or more. Not capped to the
     *                                                  last page; that is only known after the query.
     * @param array<string, list<string>> $facets       Facet key => selected values. Facets with no
     *                                                  selection are left out.
     */
    private function __construct(
        public readonly int $directory_id,
        public readonly string $keyword,
        public readonly string $location,
        public readonly string $sort,
        public readonly int $page,
        public readonly array $facets,
    ) {}

    /**
     * Parse a directory's params from the request.
     *
     * @param int                         $directory_id  Directory post ID.
     * @param DirectoryConfig             $config        The directory's config (type and facets).
     * @param array<string, list<string>> $facet_options Facet key => allowed values. A facet missing
     *                                                   here accepts no values.
     * @param string                      $default_sort  The block's default sort; invalid falls back
     *                                                   to the type's default.
     * @param bool                        $hide_keyword  The block hides keyword search.
     * @param bool                        $hide_location The block hides location search.
     * @param bool                        $hide_filters  The block hides the filters panel.
     * @param bool                        $hide_order_by The block hides Order by.
     * @param array<mixed>|null           $query         Raw, slashed query vars. Defaults to $_GET.
     *
     * @return self
     */
    public static function from_request(
        int $directory_id,
        DirectoryConfig $config,
        array $facet_options = [],
        string $default_sort = '',
        bool $hide_keyword = false,
        bool $hide_location = false,
        bool $hide_filters = false,
        bool $hide_order_by = false,
        ?array $query = null,
    ): self {
        // WordPress slashes $_GET, so unslash once here and pass raw values on.
        $query = wp_unslash($query ?? $_GET);
        $prefix = self::prefix($directory_id);
        $raw = static fn (string $name): mixed => $query[$prefix . $name] ?? null;

        // An unknown visitor sort falls back to the block's default, not the type's.
        $sort = $hide_order_by ? null : sanitize_key((string) self::scalar($raw('sort')));
        $sort = $sort !== null && array_key_exists($sort, $config->type->sort_options())
            ? $sort
            : $config->type->sanitize_sort($default_sort);

        return new self(
            $directory_id,
            $hide_keyword ? '' : self::text($raw('keyword')),
            $hide_location ? '' : self::text($raw('location')),
            $sort,
            self::page($raw('pg')),
            $hide_filters ? [] : self::facets($config->facets, $facet_options, $raw),
        );
    }

    /**
     * The GET key prefix for a directory.
     *
     * @param int $directory_id Directory post ID.
     *
     * @return string E.g. `wd12_`.
     */
    public static function prefix(int $directory_id): string
    {
        return 'wd' . $directory_id . '_';
    }

    /**
     * The key that identifies a facet in GET params and option lists.
     *
     * `org_type` for the org-type facet; `{schema_key}__{field}` for a data
     * field. Case is kept, and there are no dots, because PHP turns dots in
     * GET keys into underscores.
     *
     * @param Facet $facet A facet from DirectoryConfig::$facets.
     *
     * @return string
     */
    public static function facet_key(array $facet): string
    {
        if ($facet['source'] === DirectoryConfig::FACET_ORG_TYPE) {
            return DirectoryConfig::FACET_ORG_TYPE;
        }

        return $facet['schema_key'] . '__' . $facet['field'];
    }

    /**
     * The full GET key for one of this directory's params.
     *
     * @param string $name `keyword`, `location`, `sort`, `pg` or a facet key.
     *
     * @return string E.g. `wd12_keyword`.
     */
    public function param(string $name): string
    {
        return self::prefix($this->directory_id) . $name;
    }

    /**
     * The values selected for a facet.
     *
     * @param Facet $facet A facet from DirectoryConfig::$facets.
     *
     * @return list<string>
     */
    public function facet_values(array $facet): array
    {
        return $this->facets[self::facet_key($facet)] ?? [];
    }

    /**
     * Selected, allowed values for each configured facet.
     *
     * @param list<Facet>                 $facets  Configured facets.
     * @param array<string, list<string>> $options Facet key => allowed values.
     * @param callable(string): mixed     $raw     Reads one unprefixed param.
     *
     * @return array<string, list<string>>
     */
    private static function facets(array $facets, array $options, callable $raw): array
    {
        $selected = [];

        foreach ($facets as $facet) {
            $key = self::facet_key($facet);
            $value = $raw($key);

            if ($value === null) {
                continue;
            }

            $allowed = array_map('strval', $options[$key] ?? []);
            $values = [];

            foreach (is_array($value) ? $value : [$value] as $item) {
                $item = self::scalar($item);

                if ($item === null) {
                    continue;
                }

                $item = sanitize_text_field($item);

                if (in_array($item, $allowed, true)) {
                    $values[] = $item;
                }
            }

            if ($values !== []) {
                $selected[$key] = array_values(array_unique($values));
            }
        }

        return $selected;
    }

    /**
     * A sanitized, length-capped free-text value.
     *
     * @param mixed $value Raw value.
     *
     * @return string
     */
    private static function text(mixed $value): string
    {
        $value = self::scalar($value);

        if ($value === null) {
            return '';
        }

        return mb_substr(sanitize_text_field($value), 0, self::MAX_TEXT_LENGTH);
    }

    /**
     * The page number: a positive integer, else 1.
     *
     * @param mixed $value Raw value.
     *
     * @return int
     */
    private static function page(mixed $value): int
    {
        $value = self::scalar($value);

        if ($value === null || !ctype_digit($value)) {
            return 1;
        }

        return max(1, (int) $value);
    }

    /**
     * A query value as a string, or null when it is missing or an array.
     *
     * @param mixed $value Raw value.
     *
     * @return string|null
     */
    private static function scalar(mixed $value): ?string
    {
        return is_string($value) || is_int($value) ? (string) $value : null;
    }
}
