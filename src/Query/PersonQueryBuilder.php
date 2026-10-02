<?php

declare(strict_types=1);

namespace Wicket\Directory\Query;

use Wicket\Directory\Config\DirectoryConfig;
use Wicket\Directory\Config\DirectoryType;

/**
 * Builds `people/query` for individual directories.
 *
 * The keys are the verified ones in api-queries.md, "People".
 */
final class PersonQueryBuilder extends QueryBuilder
{
    /**
     * @return DirectoryType
     */
    protected function type(): DirectoryType
    {
        return DirectoryType::Individual;
    }

    /**
     * @return string
     */
    protected function endpoint(): string
    {
        return 'people/query';
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

        // Status and tier on the same association match the same membership row.
        if ($eligibility['require_active_membership']) {
            $filter['membership_people_status_eq'] = 'Active';
        }

        if ($eligibility['membership_ids'] !== []) {
            $filter['membership_people_membership_uuid_in'] = $eligibility['membership_ids'];
        }

        // Keyword and location are separate OR groups, ANDed with each other.
        $groups = array_values(array_filter([
            $this->keyword_group($params->keyword),
            $this->location_group($params->location),
        ]));

        if ($groups !== []) {
            $filter['g'] = $groups;
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
     * @param string $token One of DirectoryType::Individual->sort_options().
     * @param string $lang  Two-letter language code (unused: person names aren't translated).
     *
     * @return string
     */
    protected function sort_key(string $token, string $lang): string
    {
        return match ($token) {
            'first_name_desc' => '-given_name',
            'last_name_asc'   => 'family_name',
            'last_name_desc'  => '-family_name',
            default           => 'given_name',
        };
    }

    /**
     * The keyword OR group, for the filter's `g` list.
     *
     * `identifying_number_eq` lets a visitor find a member by member ID; it's
     * the reason this is a `g` group rather than a Ransack attribute combinator.
     *
     * @param string $keyword Keyword search, or ''.
     *
     * @return array<string, string>|null Null when there is no keyword.
     */
    private function keyword_group(string $keyword): ?array
    {
        if ($keyword === '') {
            return null;
        }

        return [
            'm'                     => 'or',
            'given_name_cont'       => $keyword,
            'family_name_cont'      => $keyword,
            'full_name_cont'        => $keyword,
            'identifying_number_eq' => $keyword,
        ];
    }
}
