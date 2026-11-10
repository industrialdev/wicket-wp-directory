<?php

declare(strict_types=1);

namespace Wicket\Directory\Query;

use Wicket\Directory\Config\DirectoryConfig;
use Wicket\Directory\Config\DirectoryType;

/**
 * Builds `groups/query` for group directories.
 *
 * The keys are the verified ones in api-queries.md, "Groups directory". On
 * groups, `type_eq` and `type_in` are silently ignored: the group type is
 * `resource_type_slug_in`. Groups have no location predicate and no JSON
 * schema, so there is no location group and no `search_query`.
 */
final class GroupQueryBuilder extends QueryBuilder
{
    /**
     * Endpoint of the group list and the group lookup.
     */
    public const ENDPOINT = 'groups/query';

    /**
     * The query that finds one group, but only if this directory can list it.
     *
     * Every eligibility key goes with the UUID, so a direct link to a group
     * that is inactive (or active, for an Inactive directory), excluded or of
     * another type matches nothing (api-queries.md, "A direct roster link").
     * Visitor params don't apply, and the query_args filters don't run: they
     * shape the listing, not which groups exist.
     *
     * @param DirectoryConfig $config The group directory's config.
     * @param string          $uuid   The group's UUID, e.g. from a roster link.
     *
     * @return DirectoryQuery Matches nothing when the UUID isn't a UUID or the config isn't a group
     *                        directory's.
     */
    public function lookup(DirectoryConfig $config, string $uuid): DirectoryQuery
    {
        $uuid = strtolower(trim($uuid));
        $filter = ['uuid_in' => [$uuid]] + $this->eligibility($config);

        $args = [
            'filter' => $filter,
            'page'   => ['size' => 1, 'number' => 1],
        ];

        return new DirectoryQuery(self::ENDPOINT, $args, $config->type !== DirectoryType::Group || !wp_is_uuid($uuid));
    }

    /**
     * @return DirectoryType
     */
    protected function type(): DirectoryType
    {
        return DirectoryType::Group;
    }

    /**
     * @return string
     */
    protected function endpoint(): string
    {
        return self::ENDPOINT;
    }

    /**
     * Groups have no contact records to include.
     *
     * @return string
     */
    protected function includes(): string
    {
        return '';
    }

    /**
     * @param DirectoryConfig $config The directory's config.
     * @param RequestParams   $params The visitor's choices.
     * @param string          $lang   Two-letter language code.
     *
     * @return array<string, mixed>|null
     */
    protected function filter(DirectoryConfig $config, RequestParams $params, string $lang): ?array
    {
        $filter = $this->eligibility($config);
        $types = $this->group_types($config, $params);

        if ($types === null) {
            return null;
        }

        if ($types !== []) {
            $filter['resource_type_slug_in'] = $types;
        }

        if ($params->keyword !== '') {
            // Bare `name_cont` is ignored on groups; only the language column matches.
            $filter['name_' . $lang . '_cont'] = $params->keyword;
        }

        return $filter;
    }

    /**
     * @param string $token One of DirectoryType::Group->sort_options().
     * @param string $lang  Two-letter language code.
     *
     * @return string
     */
    protected function sort_key(string $token, string $lang): string
    {
        // Bare `name` is ignored as a sort key; only the language column sorts.
        return ($token === 'name_desc' ? '-' : '') . 'name_' . $lang;
    }

    /**
     * The eligibility conditions: Group Status, group types and the exclude list.
     *
     * @param DirectoryConfig $config The directory's config.
     *
     * @return array<string, mixed>
     */
    private function eligibility(DirectoryConfig $config): array
    {
        $eligibility = $config->eligibility;
        $filter = [];

        // Group Status "All" sends no key.
        if ($eligibility['group_status'] === DirectoryConfig::GROUP_STATUS_ACTIVE) {
            $filter['active_eq'] = true;
        } elseif ($eligibility['group_status'] === DirectoryConfig::GROUP_STATUS_INACTIVE) {
            $filter['active_eq'] = false;
        }

        if ($eligibility['group_types'] !== []) {
            $filter['resource_type_slug_in'] = $eligibility['group_types'];
        }

        if ($eligibility['excluded_groups'] !== []) {
            $filter['uuid_not_in'] = $eligibility['excluded_groups'];
        }

        return $filter;
    }

    /**
     * The group-type slugs for one `resource_type_slug_in`: the eligible types,
     * narrowed by the visitor's selection.
     *
     * One hash can't hold two `resource_type_slug_in` keys, so eligibility and
     * the facet are intersected here, as OrganizationQueryBuilder does for
     * org types.
     *
     * @param DirectoryConfig $config The directory's config.
     * @param RequestParams   $params The visitor's choices.
     *
     * @return list<string>|null Empty for no condition; null when the selection has no eligible type.
     */
    private function group_types(DirectoryConfig $config, RequestParams $params): ?array
    {
        $eligible = $config->eligibility['group_types'];
        $selected = [];

        foreach ($config->facets as $facet) {
            if ($facet['source'] === DirectoryConfig::FACET_GROUP_TYPE) {
                $selected = $params->facet_values($facet);
                break;
            }
        }

        if ($selected === []) {
            return $eligible;
        }

        if ($eligible === []) {
            return $selected;
        }

        // An empty list is blank to Ransack, which drops it and lists every eligible type.
        $types = array_values(array_intersect($eligible, $selected));

        return $types === [] ? null : $types;
    }
}
