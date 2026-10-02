<?php

declare(strict_types=1);

namespace Wicket\Directory\Query;

use Wicket\Directory\Config\DirectoryConfig;
use Wicket\Directory\Config\DirectoryType;

/**
 * Builds `organizations/query` for organization directories.
 *
 * The keys are the verified ones in api-queries.md, "Organizations".
 */
final class OrganizationQueryBuilder extends QueryBuilder
{
    /**
     * @return DirectoryType
     */
    protected function type(): DirectoryType
    {
        return DirectoryType::Organization;
    }

    /**
     * @return string
     */
    protected function endpoint(): string
    {
        return 'organizations/query';
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
        $eligibility = $config->eligibility;
        $filter = [];

        if ($eligibility['require_active_membership']) {
            $filter['membership_entries_status_eq'] = 'Active';
        }

        if ($eligibility['membership_ids'] !== []) {
            $filter['membership_entries_membership_uuid_in'] = $eligibility['membership_ids'];
        }

        $types = $this->org_types($config, $params);

        if ($types === null) {
            return null;
        }

        if ($types !== []) {
            $filter['type_in'] = $types;
        }

        if ($params->keyword !== '') {
            $filter['legal_name_' . $lang . '_cont'] = $params->keyword;
        }

        $location = $this->location_group($params->location);

        if ($location !== null) {
            $filter['g'] = [$location];
        }

        $search = $this->search_query($config, $params);

        if ($search === null) {
            return null;
        }

        if ($search !== []) {
            $filter['search_query'] = $search;
        }

        return $filter;
    }

    /**
     * @param string $token One of DirectoryType::Organization->sort_options().
     * @param string $lang  Two-letter language code.
     *
     * @return string
     */
    protected function sort_key(string $token, string $lang): string
    {
        // Bare `legal_name` is ignored as a sort key; only the language column sorts.
        return ($token === 'name_desc' ? '-' : '') . 'legal_name_' . $lang;
    }

    /**
     * The org-type slugs for one `type_in`: the eligible types, narrowed by the visitor's selection.
     *
     * One hash can't hold two `type_in` keys, so eligibility and the facet are
     * intersected here.
     *
     * @param DirectoryConfig $config The directory's config.
     * @param RequestParams   $params The visitor's choices.
     *
     * @return list<string>|null Empty for no condition; null when the selection has no eligible type.
     */
    private function org_types(DirectoryConfig $config, RequestParams $params): ?array
    {
        $eligible = $config->eligibility['org_types'];
        $selected = [];

        foreach ($config->facets as $facet) {
            if ($facet['source'] === DirectoryConfig::FACET_ORG_TYPE) {
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

        // An empty type_in is blank to Ransack, which drops it and lists every eligible type.
        $types = array_values(array_intersect($eligible, $selected));

        return $types === [] ? null : $types;
    }
}
