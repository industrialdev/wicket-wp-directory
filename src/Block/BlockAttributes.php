<?php

declare(strict_types=1);

namespace Wicket\Directory\Block;

use Wicket\Directory\Config\DirectoryType;

/**
 * The per-placement display options of one Wicket Directory block.
 *
 * Instances only come from from_array(), so every value is sanitized: the
 * directory ID is 0 or more, the page size is clamped, and each Hide toggle is
 * a real boolean. The block's attributes are described in the MVP plan,
 * "Block `wicket/directory`".
 *
 * The default sort depends on the directory's type, which isn't known until the
 * directory is loaded. So $default_sort holds the editor's raw token, and
 * sort() resolves it against the type.
 */
final class BlockAttributes
{
    /**
     * Smallest page size.
     */
    public const MIN_RESULTS_PER_PAGE = 1;

    /**
     * Largest page size.
     */
    public const MAX_RESULTS_PER_PAGE = 50;

    /**
     * Page size when none (or a non-integer) is given.
     */
    public const DEFAULT_RESULTS_PER_PAGE = 6;

    /**
     * Use from_array().
     *
     * @param int    $directory_id              Directory post ID; 0 when none is picked.
     * @param string $default_sort              Sort token from the editor, sanitized but not yet
     *                                          checked against the type; '' when unset. Read it
     *                                          through sort().
     * @param int    $results_per_page          Page size, MIN_RESULTS_PER_PAGE to MAX_RESULTS_PER_PAGE.
     * @param bool   $hide_keyword              Hide keyword search; its GET param is ignored.
     * @param bool   $hide_location             Hide location search; its GET param is ignored.
     * @param bool   $hide_filters              Hide the Refine Results panel; facet params are ignored.
     * @param bool   $hide_order_by             Hide Order by; the sort param is ignored.
     * @param bool   $collapse_refine_on_mobile The Refine Results panel starts collapsed on mobile and tablet.
     */
    private function __construct(
        public readonly int $directory_id,
        public readonly string $default_sort,
        public readonly int $results_per_page,
        public readonly bool $hide_keyword,
        public readonly bool $hide_location,
        public readonly bool $hide_filters,
        public readonly bool $hide_order_by,
        public readonly bool $collapse_refine_on_mobile,
    ) {}

    /**
     * Sanitize a block's attributes.
     *
     * Missing or invalid values get the block.json defaults.
     *
     * @param array<string, mixed> $attributes Raw attributes, as the render callback gets them.
     *
     * @return self
     */
    public static function from_array(array $attributes): self
    {
        $per_page = self::int($attributes['resultsPerPage'] ?? null) ?? self::DEFAULT_RESULTS_PER_PAGE;
        $sort = $attributes['defaultSort'] ?? '';

        return new self(
            max(0, self::int($attributes['directoryId'] ?? null) ?? 0),
            is_string($sort) ? sanitize_key($sort) : '',
            min(self::MAX_RESULTS_PER_PAGE, max(self::MIN_RESULTS_PER_PAGE, $per_page)),
            self::bool($attributes['hideKeyword'] ?? null),
            self::bool($attributes['hideLocation'] ?? null),
            self::bool($attributes['hideFilters'] ?? null),
            self::bool($attributes['hideOrderBy'] ?? null),
            self::bool($attributes['collapseRefineOnMobile'] ?? null),
        );
    }

    /**
     * The block's default sort for a directory type.
     *
     * @param DirectoryType $type The directory's type.
     *
     * @return string A token from $type->sort_options(); the type's default when the
     *                block's is unset or belongs to another type.
     */
    public function sort(DirectoryType $type): string
    {
        return $type->sanitize_sort($this->default_sort);
    }

    /**
     * Coerce an integer attribute.
     *
     * The editor sends integers, but REST and hand-written block markup can
     * give numeric strings.
     *
     * @param mixed $value Raw value.
     *
     * @return int|null Null when the value isn't an integer.
     */
    private static function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $int = filter_var($value, FILTER_VALIDATE_INT);

        return $int === false ? null : $int;
    }

    /**
     * Coerce a toggle attribute. Anything that isn't boolean-like is false.
     *
     * @param mixed $value Raw value.
     *
     * @return bool
     */
    private static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        }

        return false;
    }
}
