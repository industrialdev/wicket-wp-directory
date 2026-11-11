<?php

declare(strict_types=1);

namespace Wicket\Directory\Query;

use LogicException;
use Wicket\Directory\Config\DirectoryConfig;
use Wicket\Directory\Config\DirectoryType;

/**
 * Builds the `people/query` of one group's member list (the roster).
 *
 * The roster is a people query joined to the group's member rows, so each
 * person appears once, and the individual directory's keyword, location and
 * sort keys work unchanged. The keys are the verified ones in api-queries.md,
 * "Roster: `people/query` joined to group members":
 *
 * - the group: `group_members_group_uuid_eq`;
 * - Group Member Status Active: `group_members_active_eq: true`; All: no key;
 *   Inactive: `uuid_not_in` the group's active people, which the caller
 *   fetches first (`group_members_active_eq: false` would also match current
 *   members who have past terms);
 * - the keyword also matches role labels: the caller matches them in PHP
 *   (matching_roles()), and their slugs join the keyword's OR group as
 *   `group_members_resource_type_slug_in`;
 * - sort: person keys only; a sort on the join is an HTTP 500.
 *
 * The directory's own eligibility (Group Status, types, exclude list) isn't
 * part of this query: the caller looks the group up through
 * DirectoryRepository::group() before building it.
 */
final class RosterQueryBuilder extends QueryBuilder
{
    /**
     * Endpoint of the member list.
     */
    public const ENDPOINT = 'people/query';

    /**
     * @param string            $group         The group's UUID, already looked up.
     * @param list<string>      $role_slugs    Role slugs whose labels match the keyword (matching_roles()).
     * @param list<string>|null $active_people For Group Member Status Inactive: the UUIDs of the
     *                                         group's active people. Required in that mode.
     */
    public function __construct(
        private readonly string $group,
        private readonly array $role_slugs = [],
        private readonly ?array $active_people = null,
    ) {}

    /**
     * The role slugs whose label contains the keyword, ignoring case.
     *
     * @param string                $keyword Keyword search, or ''.
     * @param array<string, string> $labels  Role slug => label (EnumLabels::group_member_roles()).
     *
     * @return list<string> Empty when there is no keyword or no label matches.
     */
    public static function matching_roles(string $keyword, array $labels): array
    {
        if ($keyword === '') {
            return [];
        }

        $slugs = [];

        foreach ($labels as $slug => $label) {
            if (mb_stripos($label, $keyword) !== false) {
                $slugs[] = (string) $slug;
            }
        }

        return $slugs;
    }

    /**
     * @return DirectoryType
     */
    protected function type(): DirectoryType
    {
        return DirectoryType::Group;
    }

    /**
     * A member list lists people.
     *
     * @return DirectoryType
     */
    protected function listed_type(): DirectoryType
    {
        return DirectoryType::Individual;
    }

    /**
     * @return string
     */
    protected function endpoint(): string
    {
        return self::ENDPOINT;
    }

    /**
     * @param DirectoryConfig $config The group directory's config.
     * @param RequestParams   $params The member list's params.
     * @param string          $lang   Two-letter language code (unused: person keys aren't translated).
     *
     * @throws LogicException When Group Member Status is Inactive and the active people weren't given.
     *
     * @return array<string, mixed>|null Null when the group isn't a UUID.
     */
    protected function filter(DirectoryConfig $config, RequestParams $params, string $lang): ?array
    {
        $group = strtolower(trim($this->group));

        if (!wp_is_uuid($group)) {
            return null;
        }

        $filter = ['group_members_group_uuid_eq' => $group];
        $status = $config->roster['member_status'];

        if ($status === DirectoryConfig::MEMBER_STATUS_ACTIVE) {
            $filter['group_members_active_eq'] = true;
        } elseif ($status === DirectoryConfig::MEMBER_STATUS_INACTIVE) {
            if ($this->active_people === null) {
                throw new LogicException('An Inactive member list needs the group\'s active people.');
            }

            // Nobody active: every member is inactive. An empty list would be dropped anyway.
            if ($this->active_people !== []) {
                $filter['uuid_not_in'] = array_values($this->active_people);
            }
        }

        $keyword = PersonQueryBuilder::keyword_group($params->keyword);

        if ($keyword !== null && $this->role_slugs !== []) {
            // On the same member row as the group key, so it means "has this role in this group".
            $keyword['group_members_resource_type_slug_in'] = array_values($this->role_slugs);
        }

        $groups = array_values(array_filter([$keyword, $this->location_group($params->location)]));

        if ($groups !== []) {
            $filter['g'] = $groups;
        }

        // The member list's own facets come with the Group Member card (phase 2 ticket P2.4).

        return $filter;
    }

    /**
     * @param string $token One of DirectoryType::Individual->sort_options().
     * @param string $lang  Two-letter language code (unused).
     *
     * @return string
     */
    protected function sort_key(string $token, string $lang): string
    {
        return PersonQueryBuilder::person_sort_key($token);
    }
}
