<?php

declare(strict_types=1);

namespace Wicket\Directory\Query;

use Wicket\Directory\Config\DirectoryConfig;
use Wicket\Directory\Config\DirectoryType;

/**
 * A visitor's search, filter, sort and page choices for one directory.
 *
 * Parameters are flat GET keys prefixed with the directory ID, e.g.
 * `wd12_keyword`, `wd12_location`, `wd12_sort`, `wd12_pg` and
 * `wd12_{facet_key}[]`. The prefix keeps two directories on one page apart.
 * Flat keys let the base search-form component pre-fill from $_GET itself.
 *
 * A group's member list (the roster) has params of its own, under
 * roster_prefix() (`wd12m_keyword`, …): searching the roster never resets
 * the group list's search, and the reverse. Which group it shows is
 * `wd12_group` (requested_group()).
 *
 * Instances only come from from_request() and for_roster(), so every value is
 * sanitized and validated: unknown facet values and sorts are dropped, the page
 * is at least 1, and params for controls the block hides are ignored. So is the
 * location of a type that has none (groups).
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
     * The unprefixed name of the param that picks the group whose members show.
     */
    public const GROUP_PARAM = 'group';

    /**
     * Use from_request() or for_roster().
     *
     * @param int                         $directory_id Directory post ID; the param prefix.
     * @param string                      $keyword      Keyword search, or ''.
     * @param string                      $location     Location search, or ''.
     * @param string                      $sort         Sort token, one of DirectoryType::sort_options().
     * @param int                         $page         Page number, 1 or more. Not capped to the
     *                                                  last page; that is only known after the query.
     * @param array<string, list<string>> $facets       Facet key => selected values. Facets with no
     *                                                  selection are left out.
     * @param string                      $prefix       The GET key prefix: prefix() for the directory,
     *                                                  roster_prefix() for a member list.
     * @param string                      $group        The group UUID whose members these params search;
     *                                                  '' for the directory's own list. Hook callbacks
     *                                                  tell a roster query by it.
     */
    private function __construct(
        public readonly int $directory_id,
        public readonly string $keyword,
        public readonly string $location,
        public readonly string $sort,
        public readonly int $page,
        public readonly array $facets,
        public readonly string $prefix = '',
        public readonly string $group = '',
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
        return self::parse(
            $directory_id,
            self::prefix($directory_id),
            '',
            $config->type,
            $config->facets,
            $facet_options,
            $default_sort,
            [$hide_keyword, $hide_location, $hide_filters, $hide_order_by],
            $query,
        );
    }

    /**
     * Parse the params of one group's member list.
     *
     * The list is of people, so the sort tokens and the location are the
     * individual directory's. Keys are under roster_prefix(), and the same
     * Hide toggles apply.
     *
     * @param int                         $directory_id  Directory post ID.
     * @param string                      $group         The group's UUID (already looked up).
     * @param list<Facet>                 $facets        The member list's facets.
     * @param array<string, list<string>> $facet_options Facet key => allowed values.
     * @param string                      $default_sort  The member list's default sort; invalid falls
     *                                                   back to the individual default.
     * @param bool                        $hide_keyword  The block hides keyword search.
     * @param bool                        $hide_location The block hides location search.
     * @param bool                        $hide_filters  The block hides the filters panel.
     * @param bool                        $hide_order_by The block hides Order by.
     * @param array<mixed>|null           $query         Raw, slashed query vars. Defaults to $_GET.
     *
     * @return self
     */
    public static function for_roster(
        int $directory_id,
        string $group,
        array $facets = [],
        array $facet_options = [],
        string $default_sort = '',
        bool $hide_keyword = false,
        bool $hide_location = false,
        bool $hide_filters = false,
        bool $hide_order_by = false,
        ?array $query = null,
    ): self {
        return self::parse(
            $directory_id,
            self::roster_prefix($directory_id),
            strtolower(trim($group)),
            DirectoryType::Individual,
            $facets,
            $facet_options,
            $default_sort,
            [$hide_keyword, $hide_location, $hide_filters, $hide_order_by],
            $query,
        );
    }

    /**
     * The group a visitor asked to see the members of.
     *
     * Only a well-formed UUID is returned. It isn't checked against the
     * directory: look the group up with DirectoryRepository::group() first.
     *
     * @param int               $directory_id Directory post ID.
     * @param array<mixed>|null $query        Raw, slashed query vars. Defaults to $_GET.
     *
     * @return string Lower-case UUID; '' when there is none.
     */
    public static function requested_group(int $directory_id, ?array $query = null): string
    {
        $query = wp_unslash($query ?? $_GET);
        $value = self::scalar($query[self::prefix($directory_id) . self::GROUP_PARAM] ?? null);
        $value = $value !== null ? strtolower(trim($value)) : '';

        return wp_is_uuid($value) ? $value : '';
    }

    /**
     * Parse one set of params.
     *
     * @param int                         $directory_id  Directory post ID.
     * @param string                      $prefix        GET key prefix.
     * @param string                      $group         Group UUID for a member list; '' otherwise.
     * @param DirectoryType               $type          What is listed: picks the sort tokens and
     *                                                   whether there is a location.
     * @param list<Facet>                 $facets        Configured facets.
     * @param array<string, list<string>> $facet_options Facet key => allowed values.
     * @param string                      $default_sort  The block's default sort.
     * @param array{bool, bool, bool, bool} $hidden      Hide keyword, location, filters, Order by.
     * @param array<mixed>|null           $query         Raw, slashed query vars. Defaults to $_GET.
     *
     * @return self
     */
    private static function parse(
        int $directory_id,
        string $prefix,
        string $group,
        DirectoryType $type,
        array $facets,
        array $facet_options,
        string $default_sort,
        array $hidden,
        ?array $query,
    ): self {
        [$hide_keyword, $hide_location, $hide_filters, $hide_order_by] = $hidden;

        // WordPress slashes $_GET, so unslash once here and pass raw values on.
        $query = wp_unslash($query ?? $_GET);
        $raw = static fn (string $name): mixed => $query[$prefix . $name] ?? null;

        // An unknown visitor sort falls back to the block's default, not the type's.
        $sort = $hide_order_by ? null : sanitize_key((string) self::scalar($raw('sort')));
        $sort = $sort !== null && array_key_exists($sort, $type->sort_options())
            ? $sort
            : $type->sanitize_sort($default_sort);

        return new self(
            $directory_id,
            $hide_keyword ? '' : self::text($raw('keyword')),
            $hide_location || !$type->has_location() ? '' : self::text($raw('location')),
            $sort,
            self::page($raw('pg')),
            $hide_filters ? [] : self::facets($facets, $facet_options, $raw),
            $prefix,
            $group,
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
     * The GET key prefix for a directory's member list.
     *
     * No directory's prefix() is a prefix of it, or the reverse, so the
     * group list and the member list never read or clear each other's params.
     *
     * @param int $directory_id Directory post ID.
     *
     * @return string E.g. `wd12m_`.
     */
    public static function roster_prefix(int $directory_id): string
    {
        return 'wd' . $directory_id . 'm_';
    }

    /**
     * Whether these are a member list's params.
     *
     * @return bool
     */
    public function is_roster(): bool
    {
        return $this->group !== '';
    }

    /**
     * The key that identifies a facet in GET params and option lists.
     *
     * `org_type` for the org-type facet, `group_type` for the group-type
     * facet; `{schema_key}__{field}` for a data field. Case is kept, and there are no dots, because PHP turns dots in
     * GET keys into underscores.
     *
     * @param Facet $facet A facet from DirectoryConfig::$facets.
     *
     * @return string
     */
    public static function facet_key(array $facet): string
    {
        if ($facet['source'] === DirectoryConfig::FACET_ORG_TYPE || $facet['source'] === DirectoryConfig::FACET_GROUP_TYPE) {
            return $facet['source'];
        }

        return $facet['schema_key'] . '__' . $facet['field'];
    }

    /**
     * The full GET key for one of these params.
     *
     * @param string $name `keyword`, `location`, `sort`, `pg` or a facet key.
     *
     * @return string E.g. `wd12_keyword`, or `wd12m_keyword` for a member list.
     */
    public function param(string $name): string
    {
        return ($this->prefix !== '' ? $this->prefix : self::prefix($this->directory_id)) . $name;
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

                // The strict allow-list match is the sanitizing. sanitize_text_field()
                // would break enum values such as "< 1" (it gives "&lt; 1").
                if ($item !== null && in_array($item, $allowed, true)) {
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
