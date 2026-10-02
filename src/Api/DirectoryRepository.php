<?php

declare(strict_types=1);

namespace Wicket\Directory\Api;

use Closure;
use RuntimeException;
use Throwable;
use Wicket\Directory\Config\DirectoryConfig;
use Wicket\Directory\Config\DirectoryType;
use Wicket\Directory\Query\DirectoryQuery;
use Wicket\Directory\Query\OrganizationQueryBuilder;
use Wicket\Directory\Query\PersonQueryBuilder;
use Wicket\Directory\Query\RequestParams;
use WP_Post;

/**
 * Runs a directory's MDP query and returns one page of results.
 *
 * - Picks the query builder from the directory type and sends the query with
 *   wicket_api_client().
 * - Caches each page (with its tier labels) in a transient. The TTL defaults
 *   to CACHE_TTL and can be changed with the wicket_directory/cache_ttl filter.
 * - Never throws and never prints: an API failure is logged and returned as an
 *   empty page flagged `unavailable`. Failures aren't cached.
 *
 * @phpstan-import-type Tier from ResultPage
 */
final class DirectoryRepository
{
    /**
     * Default cache lifetime in seconds (10 minutes).
     */
    public const CACHE_TTL = 600;

    /**
     * Transient name prefix. The rest is an md5 hash, so names stay well under
     * the 172-character limit.
     */
    public const TRANSIENT_PREFIX = 'wicket_directory_';

    /**
     * Bump when the cached ResultPage shape changes, so old entries are skipped.
     */
    public const CACHE_FORMAT = 1;

    /**
     * Log source: entries go to the wicket-directory-*.log files.
     */
    public const LOG_SOURCE = 'wicket-directory';

    /**
     * Page size of the tier lookup. The MDP caps page[size] at 2000, and people
     * often hold several active memberships, so ask for the maximum.
     */
    public const TIER_PAGE_SIZE = 2000;

    /**
     * Returns the API client.
     *
     * @var Closure(): mixed
     */
    private Closure $client_factory;

    /**
     * @param (callable(): mixed)|null $client_factory Returns the API client. Defaults to
     *                                                 wicket_api_client(). Tests pass a fake.
     */
    public function __construct(?callable $client_factory = null)
    {
        $this->client_factory = $client_factory !== null
            ? Closure::fromCallable($client_factory)
            : static fn (): mixed => wicket_api_client();
    }

    /**
     * Fetch one page of a directory.
     *
     * A query that can't match anything (DirectoryQuery::$matches_nothing)
     * returns an empty page without calling the API. A page number past the
     * last page returns the last page.
     *
     * @param WP_Post         $directory The directory post. Its ID, slug and modified date feed the
     *                                   cache key; its slug feeds the query_args_{slug} filter.
     * @param DirectoryConfig $config    The directory's config.
     * @param RequestParams   $params    The visitor's sanitized choices.
     * @param int             $per_page  Page size; the builders clamp it to 1–50.
     * @param string|null     $lang      Two-letter language code. Defaults to wicket_get_current_language().
     *
     * @return ResultPage
     */
    public function fetch(
        WP_Post $directory,
        DirectoryConfig $config,
        RequestParams $params,
        int $per_page,
        ?string $lang = null,
    ): ResultPage {
        try {
            $lang ??= (string) wicket_get_current_language();
            $query = $this->builder($config->type)->build($config, $params, $per_page, $directory->post_name, $lang);
        } catch (Throwable $e) {
            $this->log_error($directory, 'Could not build the directory query: ' . $e->getMessage());

            return ResultPage::empty($per_page, true);
        }

        $page_size = self::page_size($query, $per_page);

        if ($query->matches_nothing) {
            return ResultPage::empty($page_size);
        }

        $ttl = $this->cache_ttl($directory, $config);
        $key = self::cache_key($directory, $config, $query, $lang);

        if ($ttl > 0) {
            $cached = ResultPage::from_array(get_transient($key));

            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $client = $this->client();
            $response = $this->query($client, $query);
            $requested = self::page_number($query);
            $last = self::int($response['meta']['page']['total_pages'] ?? null);

            // A link past the last page (e.g. after results shrank) shows the last page, not an empty one.
            if (($response['data'] ?? []) === [] && $last >= 1 && $requested > $last) {
                $query = $query->with_page($last);
                $requested = $last;
                $response = $this->query($client, $query);
            }
        } catch (Throwable $e) {
            $this->log_error($directory, sprintf('Directory query %s failed: %s', $query->endpoint, $e->getMessage()));

            return ResultPage::empty($page_size, true);
        }

        $page = ResultPage::from_response($response, $requested, $page_size);
        $cacheable = true;

        if ($config->card['membership_tier'] === true && !$page->is_empty()) {
            try {
                $page = new ResultPage(
                    $page->items,
                    $page->included,
                    $page->total,
                    $page->page,
                    $page->total_pages,
                    $page->per_page,
                    $this->tiers($client, $config, $page->items, $lang),
                );
            } catch (Throwable $e) {
                // Show the results without tiers, but don't cache them, so the next visit retries.
                $this->log_error($directory, 'Directory tier lookup failed: ' . $e->getMessage());
                $cacheable = false;
            }
        }

        if ($cacheable && $ttl > 0) {
            set_transient($key, $page->to_array(), $ttl);
        }

        return $page;
    }

    /**
     * The query builder for a directory type.
     *
     * @param DirectoryType $type Directory type.
     *
     * @return PersonQueryBuilder|OrganizationQueryBuilder
     */
    private function builder(DirectoryType $type): PersonQueryBuilder|OrganizationQueryBuilder
    {
        return match ($type) {
            DirectoryType::Individual   => new PersonQueryBuilder(),
            DirectoryType::Organization => new OrganizationQueryBuilder(),
        };
    }

    /**
     * The API client.
     *
     * @throws RuntimeException When the client can't be created (SDK missing or the API is down).
     *
     * @return object
     */
    private function client(): object
    {
        $client = ($this->client_factory)();

        if (!is_object($client) || !method_exists($client, 'post')) {
            throw new RuntimeException('The Wicket API client is not available.');
        }

        return $client;
    }

    /**
     * Send a directory query.
     *
     * @param object         $client API client.
     * @param DirectoryQuery $query  The query; must not match nothing.
     *
     * @throws RuntimeException When the response isn't a JSON:API list.
     *
     * @return array<string, mixed>
     */
    private function query(object $client, DirectoryQuery $query): array
    {
        $response = $client->post($query->path(), ['json' => $query->body()]);

        if (!is_array($response) || !is_array($response['data'] ?? null)) {
            throw new RuntimeException('Unexpected response from ' . $query->endpoint . '.');
        }

        return $response;
    }

    /**
     * Active membership tiers for the entities on a page: one batch request.
     *
     * `include=person_memberships` doesn't exist on people/query, so this asks
     * `{person|organization}_memberships/query` for every entity on the page.
     * When the directory is limited to tiers, only those tiers are kept;
     * otherwise every distinct active tier is. See api-queries.md, "Tiers on
     * the card".
     *
     * @param object                     $client API client.
     * @param DirectoryConfig            $config The directory's config.
     * @param list<array<string, mixed>> $items  The page's resources.
     * @param string                     $lang   Two-letter language code, for `name_{lang}`.
     *
     * @throws RuntimeException When the response isn't a JSON:API list.
     *
     * @return array<string, list<Tier>> Keyed by entity UUID; entities with no tier are left out.
     */
    private function tiers(object $client, DirectoryConfig $config, array $items, string $lang): array
    {
        $entity = $config->type === DirectoryType::Individual ? 'person' : 'organization';
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (array $item): string => is_string($item['id'] ?? null) ? $item['id'] : '', $items),
            static fn (string $id): bool => $id !== ''
        )));

        if ($ids === []) {
            return [];
        }

        $query_string = preg_replace('/\%5B\d+\%5D/', '%5B%5D', http_build_query([
            'page'    => ['size' => self::TIER_PAGE_SIZE],
            'include' => 'membership',
        ]));

        $response = $client->post($entity . '_memberships/query?' . $query_string, ['json' => ['filter' => [
            $entity . '_uuid_in' => $ids,
            'status_eq'          => 'Active',
        ]]]);

        if (!is_array($response) || !is_array($response['data'] ?? null)) {
            throw new RuntimeException('Unexpected response from ' . $entity . '_memberships/query.');
        }

        $memberships = [];

        foreach (is_array($response['included'] ?? null) ? $response['included'] : [] as $record) {
            if (is_array($record) && ($record['type'] ?? null) === 'memberships' && is_string($record['id'] ?? null)) {
                $memberships[$record['id']] = self::tier($record, $lang);
            }
        }

        $allowed = array_flip($config->eligibility['membership_ids']);
        $wanted = array_flip($ids);
        $tiers = [];

        foreach ($response['data'] as $row) {
            $owner = $row['relationships'][$entity]['data']['id'] ?? null;
            $tier_id = $row['relationships']['membership']['data']['id'] ?? null;

            if (!is_string($owner) || !isset($wanted[$owner]) || !is_string($tier_id) || !isset($memberships[$tier_id])) {
                continue;
            }

            if ($allowed !== [] && !isset($allowed[strtolower($tier_id)])) {
                continue;
            }

            $tiers[$owner][$tier_id] = $memberships[$tier_id];
        }

        return array_map('array_values', $tiers);
    }

    /**
     * A tier label from an included `memberships` record.
     *
     * @param array<string, mixed> $record Included record.
     * @param string               $lang   Two-letter language code.
     *
     * @return Tier
     */
    private static function tier(array $record, string $lang): array
    {
        $attributes = is_array($record['attributes'] ?? null) ? $record['attributes'] : [];
        $slug = is_string($attributes['slug'] ?? null) ? $attributes['slug'] : '';
        $name = '';

        foreach (['name_' . strtolower($lang), 'name', 'name_en'] as $key) {
            if (is_string($attributes[$key] ?? null) && $attributes[$key] !== '') {
                $name = $attributes[$key];

                break;
            }
        }

        return [
            'id'   => (string) $record['id'],
            'slug' => $slug,
            'name' => $name !== '' ? $name : $slug,
        ];
    }

    /**
     * The cache lifetime for a directory.
     *
     * @param WP_Post         $directory The directory post.
     * @param DirectoryConfig $config    The directory's config.
     *
     * @return int Seconds; 0 or less disables the cache.
     */
    private function cache_ttl(WP_Post $directory, DirectoryConfig $config): int
    {
        /**
         * Change how long a directory's API responses are cached.
         *
         * Return 0 to disable the cache (e.g. while debugging).
         *
         * @param int             $ttl       Seconds. Default 600 (10 minutes).
         * @param DirectoryConfig $config    The directory's config.
         * @param WP_Post         $directory The directory post.
         */
        $ttl = apply_filters('wicket_directory/cache_ttl', self::CACHE_TTL, $config, $directory);

        return is_numeric($ttl) ? (int) $ttl : self::CACHE_TTL;
    }

    /**
     * The transient name for a query.
     *
     * The hash covers the directory ID, cache_version, language and the full
     * query (filter, sort, page, includes, after the query_args filters). It
     * also covers the whole stored config and the post's modified date, so
     * every save invalidates the directory's cache, even one that writes the
     * meta without bumping cache_version (REST, code) or changes nothing. Old
     * entries aren't deleted; they expire with their TTL.
     *
     * @param WP_Post         $directory The directory post.
     * @param DirectoryConfig $config    The directory's config.
     * @param DirectoryQuery  $query     The built query.
     * @param string          $lang      Language code.
     *
     * @return string
     */
    private static function cache_key(WP_Post $directory, DirectoryConfig $config, DirectoryQuery $query, string $lang): string
    {
        return self::TRANSIENT_PREFIX . md5(serialize([
            self::CACHE_FORMAT,
            $directory->ID,
            $config->cache_version,
            $lang,
            $directory->post_modified_gmt,
            $config->to_array(),
            $query->endpoint,
            $query->args,
        ]));
    }

    /**
     * The page number a query asks for.
     *
     * @param DirectoryQuery $query The query.
     *
     * @return int
     */
    private static function page_number(DirectoryQuery $query): int
    {
        return max(1, self::int($query->args['page']['number'] ?? null));
    }

    /**
     * The page size a query asks for.
     *
     * @param DirectoryQuery $query    The query.
     * @param int            $fallback Used when a query_args filter removed it.
     *
     * @return int
     */
    private static function page_size(DirectoryQuery $query, int $fallback): int
    {
        return max(1, self::int($query->args['page']['size'] ?? null) ?: $fallback);
    }

    /**
     * A non-negative integer from a decoded or filtered value.
     *
     * @param mixed $value Raw value.
     *
     * @return int 0 when the value isn't numeric.
     */
    private static function int(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /**
     * Log an error to the wicket-directory log.
     *
     * @param WP_Post $directory The directory post.
     * @param string  $message   What failed.
     *
     * @return void
     */
    private function log_error(WP_Post $directory, string $message): void
    {
        Wicket()->log()->error($message, [
            'source'       => self::LOG_SOURCE,
            'directory_id' => $directory->ID,
        ]);
    }
}
