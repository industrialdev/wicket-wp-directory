<?php

declare(strict_types=1);

namespace Wicket\Directory\Api;

use Closure;
use RuntimeException;
use Throwable;
use Wicket\Directory\Config\DirectoryConfig;
use Wicket\Directory\Config\DirectoryType;
use Wicket\Directory\Query\DirectoryQuery;
use Wicket\Directory\Query\GroupQueryBuilder;
use Wicket\Directory\Query\OrganizationQueryBuilder;
use Wicket\Directory\Query\PersonQueryBuilder;
use Wicket\Directory\Query\RequestParams;
use Wicket\Directory\Query\RosterQueryBuilder;
use WP_Post;

/**
 * Runs a directory's MDP query and returns one page of results.
 *
 * - Picks the query builder from the directory type and sends the query with
 *   wicket_api_client().
 * - Caches each page (with its tier labels, Organization Contacts, employers or group member
 *   counts) in a transient. The TTL defaults
 *   to CACHE_TTL and can be changed with the wicket_directory/cache_ttl filter.
 * - Never throws and never prints: an API failure is logged and returned as an
 *   empty page flagged `unavailable`. Failures aren't cached.
 *
 * @phpstan-import-type Tier from ResultPage
 * @phpstan-import-type Membership from ResultPage
 * @phpstan-import-type EmployerSettings from DirectoryConfig
 */
final class DirectoryRepository
{
    /**
     * Default cache lifetime in seconds (10 minutes).
     */
    public const CACHE_TTL = 600;

    /**
     * Transient name prefix. A directory's entries add its ID and an md5 hash
     * (see transient_prefix()), so names stay well under the 172-character limit.
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
     * Page size of the group member count batch: the MDP's cap. A page of
     * groups with more active member rows than this falls back to the groups'
     * own `active_member_count`.
     */
    public const MEMBER_COUNT_PAGE_SIZE = 2000;

    /**
     * Page size of the active-people lookup for an Inactive member list:
     * the MDP's cap. A group with more active member rows than this can't
     * list its inactive members; the list shows as unavailable.
     */
    public const ACTIVE_PEOPLE_PAGE_SIZE = 2000;

    /**
     * Page size of a member list page's `group_members` batch: the MDP's cap.
     * A page of people holds far fewer rows; if it ever doesn't fit, the
     * oldest rows are the ones left out.
     */
    public const GROUP_MEMBERS_PAGE_SIZE = 2000;

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
     * The card can add batch requests per page, never per row: the tiers;
     * a group's member counts; an organization's Organization Contacts
     * (OrganizationContacts: the owners come with the tier request's
     * `include=owner`, then up to one `connections/query` and one
     * `people/query`); a person's Employer (PersonEmployers: one
     * `connections/query`). A batch that fails is logged, the page shows
     * without it and isn't cached.
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

        return $this->load($directory, $config, $query, $per_page, $lang, function (object $client, ResultPage $page) use ($directory, $config, $lang): array {
            $cacheable = true;
            $contact = $config->org_contact();
            $wants_tiers = ($config->card['membership_tier'] ?? false) === true;
            $wants_owners = $contact !== null && $contact['source'] === DirectoryConfig::ORG_CONTACT_OWNER;
            $memberships = null;

            if ($wants_tiers || $wants_owners) {
                try {
                    $entity = $config->type === DirectoryType::Individual ? 'person' : 'organization';
                    // One request serves the tier line and the membership owners.
                    $memberships = $this->memberships($client, $entity, $page->items, $wants_owners ? 'membership,owner' : 'membership');

                    if ($wants_tiers) {
                        $page = $page->with_tiers(self::tiers($memberships, $entity, $config->eligibility['membership_ids'], $page->items, $lang));
                    }
                } catch (Throwable $e) {
                    // Show the results without tiers or owners, but don't cache them, so the next visit retries.
                    $this->log_error($directory, 'Directory membership lookup (tiers, owners) failed: ' . $e->getMessage());
                    $cacheable = false;
                }
            }

            if ($contact !== null && (!$wants_owners || $memberships !== null)) {
                try {
                    $loaded = (new OrganizationContacts($client))->load(self::ids($page->items), $contact, $memberships, $config->eligibility['membership_ids']);
                    $page = $page->with_org_contacts($loaded['contacts'], $loaded['people'], $loaded['included']);
                } catch (Throwable $e) {
                    // The cards show without the section; don't cache, so the next visit retries.
                    $this->log_error($directory, 'Directory organization contact lookup failed: ' . $e->getMessage());
                    $cacheable = false;
                }
            }

            $employer = $config->employer();

            if ($employer !== null) {
                [$page, $loaded] = $this->with_employers($directory, $client, $page, $employer, $lang);
                $cacheable = $cacheable && $loaded;
            }

            if ($config->type === DirectoryType::Group && ($config->card['member_count'] ?? false) === true) {
                try {
                    $page = $page->with_member_counts($this->member_counts($client, $page->items));
                } catch (Throwable $e) {
                    // The cards fall back to active_member_count; don't cache, so the next visit retries.
                    $this->log_error($directory, 'Directory group member count failed: ' . $e->getMessage());
                    $cacheable = false;
                }
            }

            return [$page, $cacheable];
        });
    }

    /**
     * Fetch one page of a group's member list (the roster).
     *
     * The group must already have been looked up with group(): this query
     * only joins people to the group's member rows (RosterQueryBuilder).
     * Group Member Status Inactive first asks for the group's active people
     * (one `group_members/query`, cached like a page), to leave them out.
     * Pages are cached and fall back to the last page like fetch()'s.
     *
     * The Group Member card can add three batch requests per page, as
     * fetch()'s cards do: the people's tiers (when it shows the tier), their
     * rows in the group (when it shows the role, status or a date) and their
     * employers (when it shows Employer). A batch that fails is logged, the
     * page shows without it and isn't cached.
     *
     * @param WP_Post               $directory   The directory post.
     * @param DirectoryConfig       $config      The group directory's config.
     * @param string                $group       The group's UUID.
     * @param RequestParams         $params      The member list's params (RequestParams::for_roster()).
     * @param int                   $per_page    Page size; clamped to 1–50.
     * @param array<string, string> $role_labels Role slug => label in the current language, for the
     *                                           keyword (EnumLabels::group_member_roles()).
     * @param string|null           $lang        Two-letter language code. Defaults to wicket_get_current_language().
     *
     * @return ResultPage A page of `people` resources.
     */
    public function fetch_roster(
        WP_Post $directory,
        DirectoryConfig $config,
        string $group,
        RequestParams $params,
        int $per_page,
        array $role_labels = [],
        ?string $lang = null,
    ): ResultPage {
        try {
            $lang ??= (string) wicket_get_current_language();
            $active_people = null;

            if ($config->roster['member_status'] === DirectoryConfig::MEMBER_STATUS_INACTIVE) {
                $active_people = $this->active_people($directory, $config, $group);
            }

            $builder = new RosterQueryBuilder($group, RosterQueryBuilder::matching_roles($params->keyword, $role_labels), $active_people);
            $query = $builder->build($config, $params, $per_page, $directory->post_name, $lang);
        } catch (Throwable $e) {
            $this->log_error($directory, 'Could not build the member list query: ' . $e->getMessage());

            return ResultPage::empty($per_page, true);
        }

        return $this->load($directory, $config, $query, $per_page, $lang, function (object $client, ResultPage $page) use ($directory, $config, $group, $lang): array {
            $cacheable = true;

            if (($config->member_card['membership_tier'] ?? false) === true) {
                try {
                    // A group directory has no tier eligibility, so every active tier shows.
                    $memberships = $this->memberships($client, 'person', $page->items, 'membership');
                    $page = $page->with_tiers(self::tiers($memberships, 'person', [], $page->items, $lang));
                } catch (Throwable $e) {
                    $this->log_error($directory, 'Member list tier lookup failed: ' . $e->getMessage());
                    $cacheable = false;
                }
            }

            if ($config->shows_membership()) {
                try {
                    $active_only = $config->roster['member_status'] === DirectoryConfig::MEMBER_STATUS_ACTIVE;
                    $page = $page->with_group_members($this->group_members($client, $group, $page->items, $active_only));
                } catch (Throwable $e) {
                    $this->log_error($directory, 'Member list group member lookup failed: ' . $e->getMessage());
                    $cacheable = false;
                }
            }

            $employer = $config->employer(true);

            if ($employer !== null) {
                [$page, $loaded] = $this->with_employers($directory, $client, $page, $employer, $lang);
                $cacheable = $cacheable && $loaded;
            }

            return [$page, $cacheable];
        });
    }

    /**
     * Add the page's employers (one `connections/query`, see PersonEmployers).
     *
     * @param WP_Post          $directory The directory post, for the log.
     * @param object           $client    API client.
     * @param ResultPage       $page      A page of `people` resources.
     * @param EmployerSettings $settings  The card's Employer settings (DirectoryConfig::employer()).
     * @param string           $lang      Language code.
     *
     * @return array{ResultPage, bool} The page, and false when the lookup failed (logged; the page
     *                                 shows without employers and mustn't be cached).
     */
    private function with_employers(WP_Post $directory, object $client, ResultPage $page, array $settings, string $lang): array
    {
        try {
            $loaded = (new PersonEmployers($client))->load(self::ids($page->items), $settings, $lang);

            return [$page->with_employers($loaded['employers'], $loaded['organizations']), true];
        } catch (Throwable $e) {
            $this->log_error($directory, 'Directory employer lookup failed: ' . $e->getMessage());

            return [$page, false];
        }
    }

    /**
     * Run a built query: cache lookup, the API request, the last-page
     * fallback, extra lookups, then the cache write.
     *
     * @param WP_Post                                                     $directory The directory post.
     * @param DirectoryConfig                                             $config    The directory's config.
     * @param DirectoryQuery                                              $query     The built query.
     * @param int                                                         $per_page  Requested page size.
     * @param string                                                      $lang      Language code.
     * @param (Closure(object, ResultPage): array{ResultPage, bool})|null $decorate Adds batch lookups to a
     *                                                                               non-empty page. Returns the
     *                                                                               page and whether to cache it.
     *
     * @return ResultPage
     */
    private function load(
        WP_Post $directory,
        DirectoryConfig $config,
        DirectoryQuery $query,
        int $per_page,
        string $lang,
        ?Closure $decorate = null,
    ): ResultPage {
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

        if ($decorate !== null && !$page->is_empty()) {
            [$page, $cacheable] = $decorate($client, $page);
        }

        if ($cacheable && $ttl > 0) {
            set_transient($key, $page->to_array(), $ttl);
        }

        return $page;
    }

    /**
     * One group a group directory can list, by UUID.
     *
     * The lookup sends every eligibility key with the UUID (see
     * GroupQueryBuilder::lookup()), so a group that is inactive, excluded or
     * of another type is never found, however the visitor got its UUID. Use it
     * for anything that reaches a group outside the listing, e.g. a roster link.
     * Not cached: it's one small request, and a stale hit could show a group
     * that was just excluded.
     *
     * @param WP_Post         $directory The directory post, for the log.
     * @param DirectoryConfig $config    The directory's config.
     * @param string          $uuid      The group's UUID, untrusted.
     *
     * @return array<string, mixed>|null The `groups` resource; null when the directory can't list it,
     *                                   the UUID is invalid or the API failed (logged).
     */
    public function group(WP_Post $directory, DirectoryConfig $config, string $uuid): ?array
    {
        $query = (new GroupQueryBuilder())->lookup($config, $uuid);

        if ($query->matches_nothing) {
            return null;
        }

        try {
            $response = $this->query($this->client(), $query);
        } catch (Throwable $e) {
            $this->log_error($directory, 'Directory group lookup failed: ' . $e->getMessage());

            return null;
        }

        $group = $response['data'][0] ?? null;
        $id = is_array($group) ? ($group['id'] ?? null) : null;

        // Only the group that was asked for; never trust a looser match.
        return is_string($id) && strtolower($id) === strtolower(trim($uuid)) ? $group : null;
    }

    /**
     * The query builder for a directory type.
     *
     * @param DirectoryType $type Directory type.
     *
     * @return PersonQueryBuilder|OrganizationQueryBuilder|GroupQueryBuilder
     */
    private function builder(DirectoryType $type): PersonQueryBuilder|OrganizationQueryBuilder|GroupQueryBuilder
    {
        return match ($type) {
            DirectoryType::Individual   => new PersonQueryBuilder(),
            DirectoryType::Organization => new OrganizationQueryBuilder(),
            DirectoryType::Group        => new GroupQueryBuilder(),
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
     * Active memberships of the entities on a page: one batch request.
     *
     * `include=person_memberships` doesn't exist on people/query, so this asks
     * `{person|organization}_memberships/query` for every entity on the page.
     * See api-queries.md, "Tiers on the card". An organization page that shows
     * its membership owners adds `owner` to the include ("Membership owner").
     *
     * @param object                     $client  API client.
     * @param string                     $entity  `person` or `organization`.
     * @param list<array<string, mixed>> $items   The page's resources.
     * @param string                     $include `membership`, or `membership,owner`.
     *
     * @throws RuntimeException When the response isn't a JSON:API list.
     *
     * @return array<string, mixed> The response; empty when the page has no IDs.
     */
    private function memberships(object $client, string $entity, array $items, string $include): array
    {
        $ids = self::ids($items);

        if ($ids === []) {
            return [];
        }

        $query_string = preg_replace('/\%5B\d+\%5D/', '%5B%5D', http_build_query([
            'page'    => ['size' => self::TIER_PAGE_SIZE],
            'include' => $include,
        ]));

        $response = $client->post($entity . '_memberships/query?' . $query_string, ['json' => ['filter' => [
            $entity . '_uuid_in' => $ids,
            'status_eq'          => 'Active',
        ]]]);

        if (!is_array($response) || !is_array($response['data'] ?? null)) {
            throw new RuntimeException('Unexpected response from ' . $entity . '_memberships/query.');
        }

        return $response;
    }

    /**
     * Active membership tiers for the entities on a page, from memberships().
     *
     * When the directory is limited to tiers, only those tiers are kept;
     * otherwise every distinct active tier is.
     *
     * @param array<string, mixed>       $response The memberships() response.
     * @param string                     $entity   `person` or `organization`.
     * @param list<string>               $allowed  The directory's tier UUIDs (lower case); empty for any.
     * @param list<array<string, mixed>> $items    The page's resources.
     * @param string                     $lang     Two-letter language code, for `name_{lang}`.
     *
     * @return array<string, list<Tier>> Keyed by entity UUID; entities with no tier are left out.
     */
    private static function tiers(array $response, string $entity, array $allowed, array $items, string $lang): array
    {
        $ids = self::ids($items);

        if ($ids === []) {
            return [];
        }

        $memberships = [];

        foreach (is_array($response['included'] ?? null) ? $response['included'] : [] as $record) {
            if (is_array($record) && ($record['type'] ?? null) === 'memberships' && is_string($record['id'] ?? null)) {
                $memberships[$record['id']] = self::tier($record, $lang);
            }
        }

        $allowed = array_flip($allowed);
        $wanted = array_flip($ids);
        $tiers = [];

        foreach (is_array($response['data'] ?? null) ? $response['data'] : [] as $row) {
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
     * The distinct, non-empty IDs of a page's resources, in order.
     *
     * @param list<array<string, mixed>> $items The page's resources.
     *
     * @return list<string>
     */
    private static function ids(array $items): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (array $item): string => is_string($item['id'] ?? null) ? $item['id'] : '', $items),
            static fn (string $id): bool => $id !== ''
        )));
    }

    /**
     * Distinct active members per group on a page: one batch request.
     *
     * The groups' `active_member_count` counts active member rows, so a person
     * with two overlapping active terms counts twice and the card would
     * disagree with the roster total. This asks `group_members/query` for the
     * page's active rows and counts distinct people per group (api-queries.md,
     * "Active member count").
     *
     * @param object                     $client API client.
     * @param list<array<string, mixed>> $items  The page's `groups` resources.
     *
     * @throws RuntimeException When the response isn't a JSON:API list.
     *
     * @return array<string, int> Group UUID => distinct active members, for every group on the page.
     *                            Empty when the rows don't fit in one request; the cards then
     *                            use `active_member_count`.
     */
    private function member_counts(object $client, array $items): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (array $item): string => is_string($item['id'] ?? null) ? $item['id'] : '', $items),
            static fn (string $id): bool => $id !== ''
        )));

        if ($ids === []) {
            return [];
        }

        $query_string = http_build_query(['page' => ['size' => self::MEMBER_COUNT_PAGE_SIZE]]);

        $response = $client->post('group_members/query?' . $query_string, ['json' => ['filter' => [
            'group_uuid_in' => $ids,
            'active_eq'     => true,
        ]]]);

        if (!is_array($response) || !is_array($response['data'] ?? null)) {
            throw new RuntimeException('Unexpected response from group_members/query.');
        }

        // Paging through the rest would cost more requests; the attribute is close enough.
        if (self::int($response['meta']['page']['total_items'] ?? null) > count($response['data'])) {
            return [];
        }

        $people = array_fill_keys($ids, []);

        foreach ($response['data'] as $row) {
            $group = is_array($row) ? ($row['relationships']['group']['data']['id'] ?? null) : null;
            $person = is_array($row) ? ($row['relationships']['person']['data']['id'] ?? null) : null;

            if (is_string($group) && isset($people[$group]) && is_string($person) && $person !== '') {
                $people[$group][$person] = true;
            }
        }

        return array_map('count', $people);
    }

    /**
     * Each person's row in a group, for a page of its member list: one batch request.
     *
     * A person can have several rows (one per term), so the rows come newest
     * first (`sort=-start_date`) and each person gets their newest active row,
     * else their newest row. In the Active view only active rows are asked
     * for, so it's the newest active term; in Inactive, the latest term; in
     * All, the current term if there is one (api-queries.md, "Roster card:
     * role, status and dates").
     *
     * @param object                     $client      API client.
     * @param string                     $group       The group's UUID.
     * @param list<array<string, mixed>> $items       The page's `people` resources.
     * @param bool                       $active_only Ask for active rows only (Group Member Status Active).
     *
     * @throws RuntimeException When the response isn't a JSON:API list.
     *
     * @return array<string, Membership> Keyed by person UUID; people without a row are left out.
     */
    private function group_members(object $client, string $group, array $items, bool $active_only): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (array $item): string => is_string($item['id'] ?? null) ? $item['id'] : '', $items),
            static fn (string $id): bool => $id !== ''
        )));

        if ($ids === []) {
            return [];
        }

        $filter = [
            'group_uuid_eq'  => strtolower(trim($group)),
            'person_uuid_in' => $ids,
        ];

        if ($active_only) {
            $filter['active_eq'] = true;
        }

        $query_string = http_build_query(['page' => ['size' => self::GROUP_MEMBERS_PAGE_SIZE], 'sort' => '-start_date']);
        $response = $client->post('group_members/query?' . $query_string, ['json' => ['filter' => $filter]]);

        if (!is_array($response) || !is_array($response['data'] ?? null)) {
            throw new RuntimeException('Unexpected response from group_members/query.');
        }

        $wanted = array_flip($ids);
        $rows = [];

        foreach ($response['data'] as $row) {
            $person = is_array($row) ? ($row['relationships']['person']['data']['id'] ?? null) : null;

            if (!is_string($person) || !isset($wanted[$person])) {
                continue;
            }

            $attributes = is_array($row['attributes'] ?? null) ? $row['attributes'] : [];
            $active = is_bool($attributes['active'] ?? null) ? $attributes['active'] : null;

            // Rows come newest first: keep the first, unless a later one is active and it isn't.
            if (isset($rows[$person]) && ($rows[$person]['active'] === true || $active !== true)) {
                continue;
            }

            $rows[$person] = [
                'role'       => is_string($attributes['type'] ?? null) ? $attributes['type'] : '',
                'active'     => $active,
                'start_date' => is_string($attributes['start_date'] ?? null) ? $attributes['start_date'] : '',
                'end_date'   => is_string($attributes['end_date'] ?? null) ? $attributes['end_date'] : null,
            ];
        }

        return $rows;
    }

    /**
     * The people with an active member row in a group, for an Inactive member list.
     *
     * One `group_members/query` (`group_uuid_eq` + `active_eq: true`), cached
     * for the directory's TTL like a page of results.
     *
     * @param WP_Post         $directory The directory post (cache key and TTL).
     * @param DirectoryConfig $config    The directory's config (cache key and TTL).
     * @param string          $group     The group's UUID.
     *
     * @throws RuntimeException When the request fails, or the rows don't fit in one request.
     *
     * @return list<string> Distinct person UUIDs.
     */
    private function active_people(WP_Post $directory, DirectoryConfig $config, string $group): array
    {
        $group = strtolower(trim($group));
        $ttl = $this->cache_ttl($directory, $config);
        $key = self::transient_prefix($directory->ID) . md5(serialize([
            self::CACHE_FORMAT,
            'active_people',
            $directory->ID,
            $config->cache_version,
            $directory->post_modified_gmt,
            $group,
        ]));

        if ($ttl > 0) {
            $cached = get_transient($key);

            if (is_array($cached) && array_is_list($cached)) {
                return array_values(array_filter($cached, 'is_string'));
            }
        }

        $query_string = http_build_query(['page' => ['size' => self::ACTIVE_PEOPLE_PAGE_SIZE]]);
        $response = $this->client()->post('group_members/query?' . $query_string, ['json' => ['filter' => [
            'group_uuid_eq' => $group,
            'active_eq'     => true,
        ]]]);

        if (!is_array($response) || !is_array($response['data'] ?? null)) {
            throw new RuntimeException('Unexpected response from group_members/query.');
        }

        // A partial list would show active members as inactive.
        if (self::int($response['meta']['page']['total_items'] ?? null) > count($response['data'])) {
            throw new RuntimeException('The group has too many active member rows to list its inactive members.');
        }

        $people = [];

        foreach ($response['data'] as $row) {
            $person = is_array($row) ? ($row['relationships']['person']['data']['id'] ?? null) : null;

            if (is_string($person) && $person !== '') {
                $people[$person] = true;
            }
        }

        $people = array_keys($people);

        if ($ttl > 0) {
            set_transient($key, $people, $ttl);
        }

        return array_map('strval', $people);
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
     * The start of every transient name that holds one directory's results.
     *
     * The trailing underscore keeps directory 1's names apart from directory 12's.
     *
     * @param int $directory_id The directory post ID.
     *
     * @return string
     */
    public static function transient_prefix(int $directory_id): string
    {
        return self::TRANSIENT_PREFIX . $directory_id . '_';
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
     * meta without bumping cache_version (REST, code) or changes nothing. The
     * name starts with transient_prefix(), so DirectoryCache can delete a
     * directory's old entries when it is saved; otherwise they expire with their TTL.
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
        return self::transient_prefix($directory->ID) . md5(serialize([
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
