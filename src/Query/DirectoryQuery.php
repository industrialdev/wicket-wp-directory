<?php

declare(strict_types=1);

namespace Wicket\Directory\Query;

use LogicException;

/**
 * A built MDP query, ready for wicket_api_client()->post().
 *
 * `filter` goes in the JSON body; `page`, `sort` and `include` go in the query
 * string, as in every existing caller (see api-queries.md, "Request shape").
 *
 * Instances only come from a QueryBuilder.
 */
final class DirectoryQuery
{
    /**
     * Use a QueryBuilder.
     *
     * @param string               $endpoint        E.g. `organizations/query`.
     * @param array<string, mixed> $args            `filter`, `sort`, `page` and `include`, after the
     *                                              wicket_directory/query_args filters.
     * @param bool                 $matches_nothing The conditions can't match any record (e.g. the
     *                                              visitor's org types don't intersect the eligible
     *                                              ones). Don't call the API; render an empty page.
     *                                              `args` still carries the sort and page.
     */
    public function __construct(
        public readonly string $endpoint,
        public readonly array $args,
        public readonly bool $matches_nothing = false,
    ) {}

    /**
     * The same query for another page.
     *
     * DirectoryRepository uses it to re-fetch the last page when a link points
     * past it.
     *
     * @param int $number Page number; at least 1.
     *
     * @return self
     */
    public function with_page(int $number): self
    {
        $args = $this->args;
        $page = is_array($args['page'] ?? null) ? $args['page'] : [];
        $page['number'] = max(1, $number);
        $args['page'] = $page;

        return new self($this->endpoint, $args, $this->matches_nothing);
    }

    /**
     * The request path with its query string.
     *
     * @return string E.g. `organizations/query?page%5Bsize%5D=6&page%5Bnumber%5D=1&sort=legal_name_en&include=…`.
     */
    public function path(): string
    {
        $query = array_diff_key($this->args, ['filter' => true]);

        // Ruby doesn't like the numeric keys PHP adds to array params, e.g. page[0].
        $query = preg_replace('/\%5B\d+\%5D/', '%5B%5D', http_build_query($query));

        return $query === '' ? $this->endpoint : $this->endpoint . '?' . $query;
    }

    /**
     * The JSON request body.
     *
     * An empty filter is sent as an object: the MDP rejects `[]`.
     *
     * @throws LogicException When the query matches nothing. Sending it would
     *                        drop the impossible condition and list every record.
     *
     * @return array{filter: array<string, mixed>|object}
     */
    public function body(): array
    {
        if ($this->matches_nothing) {
            throw new LogicException('This directory query matches nothing; don\'t send it.');
        }

        $filter = $this->args['filter'] ?? [];

        return ['filter' => is_array($filter) && $filter !== [] ? $filter : (object) []];
    }
}
