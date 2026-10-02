<?php

declare(strict_types=1);

namespace Wicket\Directory\Api;

/**
 * One page of directory results, as DirectoryRepository returns it.
 *
 * Holds the raw API resources: EntryMapper turns them into card DTOs. The
 * same shape is what the transient cache stores (see to_array()).
 *
 * An empty page is either a real "no results" or, when `unavailable` is set,
 * an API failure. Templates show the friendly "unavailable" alert for the
 * latter and the no-results message for the former.
 *
 * @phpstan-type Tier array{id: string, slug: string, name: string}
 */
final class ResultPage
{
    /**
     * @param list<array<string, mixed>>  $items       Resources from the response's `data`, in order.
     * @param list<array<string, mixed>>  $included    Records from the response's `included` (emails,
     *                                                 phones, addresses, web addresses).
     * @param int                         $total       Matching records across all pages.
     * @param int                         $page        Current page number, from 1. Clamped to
     *                                                 total_pages when there are results.
     * @param int                         $total_pages Number of pages; 0 when there are no results.
     * @param int                         $per_page    Page size.
     * @param array<string, list<Tier>>   $tiers       Active membership tiers keyed by entity UUID.
     *                                                 Only filled when the card's tier toggle is on.
     * @param bool                        $unavailable The API failed; the page is empty for that reason.
     */
    public function __construct(
        public readonly array $items,
        public readonly array $included,
        public readonly int $total,
        public readonly int $page,
        public readonly int $total_pages,
        public readonly int $per_page,
        public readonly array $tiers = [],
        public readonly bool $unavailable = false,
    ) {}

    /**
     * A page with no results.
     *
     * @param int  $per_page    Page size.
     * @param bool $unavailable Whether it's empty because the API failed.
     *
     * @return self
     */
    public static function empty(int $per_page, bool $unavailable = false): self
    {
        return new self([], [], 0, 1, 0, max(1, $per_page), [], $unavailable);
    }

    /**
     * Build a page from a `{people|organizations}/query` response.
     *
     * @param array<string, mixed>      $response  Decoded response: `data`, `included`, `meta.page`.
     * @param int                       $page      The page number requested; used when `meta` lacks it.
     * @param int                       $per_page  The page size requested; used when `meta` lacks it.
     * @param array<string, list<Tier>> $tiers     Tier labels for the page's entities.
     *
     * @return self
     */
    public static function from_response(array $response, int $page, int $per_page, array $tiers = []): self
    {
        $meta = is_array($response['meta']['page'] ?? null) ? $response['meta']['page'] : [];
        $total = self::int($meta['total_items'] ?? null, 0);
        $total_pages = self::int($meta['total_pages'] ?? null, 0);
        $number = self::int($meta['number'] ?? null, $page);

        return new self(
            self::records($response['data'] ?? null),
            self::records($response['included'] ?? null),
            $total,
            $total_pages > 0 ? max(1, min($number, $total_pages)) : 1,
            $total_pages,
            max(1, self::int($meta['size'] ?? null, $per_page)),
            $tiers,
        );
    }

    /**
     * Rebuild a page from its cached form.
     *
     * @param mixed $data A value from to_array(), e.g. read back from a transient.
     *
     * @return self|null Null when the value isn't a cached page.
     */
    public static function from_array(mixed $data): ?self
    {
        if (!is_array($data) || !is_array($data['items'] ?? null) || !is_array($data['included'] ?? null) || !is_array($data['tiers'] ?? null)) {
            return null;
        }

        foreach (['total', 'page', 'total_pages', 'per_page'] as $key) {
            if (!is_int($data[$key] ?? null)) {
                return null;
            }
        }

        return new self(
            self::records($data['items']),
            self::records($data['included']),
            $data['total'],
            $data['page'],
            $data['total_pages'],
            $data['per_page'],
            $data['tiers'],
        );
    }

    /**
     * The page in the form the cache stores.
     *
     * Unavailable pages are never cached, so the flag isn't part of it.
     *
     * @return array{items: list<array<string, mixed>>, included: list<array<string, mixed>>, total: int, page: int, total_pages: int, per_page: int, tiers: array<string, list<Tier>>}
     */
    public function to_array(): array
    {
        return [
            'items'       => $this->items,
            'included'    => $this->included,
            'total'       => $this->total,
            'page'        => $this->page,
            'total_pages' => $this->total_pages,
            'per_page'    => $this->per_page,
            'tiers'       => $this->tiers,
        ];
    }

    /**
     * Whether the page has no items.
     *
     * @return bool
     */
    public function is_empty(): bool
    {
        return $this->items === [];
    }

    /**
     * The 1-based position of the first item, for "Displaying X–Y of N".
     *
     * @return int 0 when the page is empty.
     */
    public function first_number(): int
    {
        return $this->is_empty() ? 0 : ($this->page - 1) * $this->per_page + 1;
    }

    /**
     * The 1-based position of the last item, for "Displaying X–Y of N".
     *
     * @return int 0 when the page is empty.
     */
    public function last_number(): int
    {
        return $this->is_empty() ? 0 : $this->first_number() + count($this->items) - 1;
    }

    /**
     * Active membership tiers for one entity.
     *
     * @param string $id Person or organization UUID.
     *
     * @return list<Tier>
     */
    public function tiers_for(string $id): array
    {
        return $this->tiers[$id] ?? [];
    }

    /**
     * The array records in a list, re-indexed.
     *
     * @param mixed $value Raw list.
     *
     * @return list<array<string, mixed>>
     */
    private static function records(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * A non-negative integer from a decoded JSON value.
     *
     * @param mixed $value   Raw value.
     * @param int   $default Used when the value isn't numeric.
     *
     * @return int
     */
    private static function int(mixed $value, int $default): int
    {
        return is_numeric($value) ? max(0, (int) $value) : $default;
    }
}
