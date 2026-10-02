<?php

declare(strict_types=1);

namespace Wicket\Directory\Query;

use InvalidArgumentException;
use Wicket\Directory\Config\DirectoryConfig;
use Wicket\Directory\Config\DirectoryType;

/**
 * Builds the Ransack payload for one directory type's `{people|organizations}/query`.
 *
 * Holds what both builders share: the location group, data-field conditions,
 * sort whitelisting, paging, includes and the query_args filters.
 *
 * The MDP silently ignores unknown predicates and sort keys, so a typo
 * returns unfiltered results (for eligibility: the whole tenant). Emit only
 * the keys verified in docs/engineering/api-queries.md.
 *
 * @phpstan-import-type Facet from DirectoryConfig
 */
abstract class QueryBuilder
{
    /**
     * Contact records every card may need.
     */
    public const INCLUDE = 'emails,phones,addresses,web_addresses';

    /**
     * Smallest page size. The MDP errors on 0.
     */
    public const MIN_PER_PAGE = 1;

    /**
     * Largest page size, matching the block's resultsPerPage clamp.
     */
    public const MAX_PER_PAGE = 50;

    /**
     * Language used when the current one isn't a two-letter code.
     */
    public const DEFAULT_LANGUAGE = 'en';

    /**
     * Build the query for a directory request.
     *
     * @param DirectoryConfig $config   The directory's config. Must be of this builder's type.
     * @param RequestParams   $params   The visitor's sanitized choices.
     * @param int             $per_page Page size; clamped to MIN_PER_PAGE–MAX_PER_PAGE.
     * @param string          $slug     The directory post's slug, for the query_args_{slug} filter.
     * @param string|null     $lang     Two-letter language code. Defaults to wicket_get_current_language().
     *
     * @throws InvalidArgumentException When the config is of another directory type.
     *
     * @return DirectoryQuery
     */
    public function build(
        DirectoryConfig $config,
        RequestParams $params,
        int $per_page,
        string $slug = '',
        ?string $lang = null,
    ): DirectoryQuery {
        if ($config->type !== $this->type()) {
            throw new InvalidArgumentException(sprintf(
                '%s needs a %s directory, got %s.',
                static::class,
                $this->type()->value,
                $config->type->value
            ));
        }

        $lang = self::language($lang ?? wicket_get_current_language());
        $filter = $this->filter($config, $params, $lang);

        $args = [
            'filter'  => $filter ?? [],
            'page'    => [
                'size'   => max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, $per_page)),
                'number' => max(1, $params->page),
            ],
            'sort'    => $this->sort_key($config->type->sanitize_sort($params->sort), $lang),
            'include' => self::INCLUDE,
        ];

        if ($filter === null) {
            return new DirectoryQuery($this->endpoint(), $args, true);
        }

        return new DirectoryQuery($this->endpoint(), $this->apply_filters($args, $config, $params, $slug));
    }

    /**
     * The directory type this builder handles.
     *
     * @return DirectoryType
     */
    abstract protected function type(): DirectoryType;

    /**
     * The query endpoint, e.g. `organizations/query`.
     *
     * @return string
     */
    abstract protected function endpoint(): string;

    /**
     * The Ransack filter: eligibility, keyword, location and facets.
     *
     * @param DirectoryConfig $config The directory's config.
     * @param RequestParams   $params The visitor's choices.
     * @param string          $lang   Two-letter language code.
     *
     * @return array<string, mixed>|null Null when the conditions can't match any record.
     */
    abstract protected function filter(DirectoryConfig $config, RequestParams $params, string $lang): ?array;

    /**
     * The MDP sort key for a valid sort token.
     *
     * @param string $token One of DirectoryType::sort_options().
     * @param string $lang  Two-letter language code.
     *
     * @return string E.g. `legal_name_en`, or `-legal_name_en` for descending.
     */
    abstract protected function sort_key(string $token, string $lang): string;

    /**
     * The location OR group, for the filter's `g` list.
     *
     * Matches any of the record's addresses, not only the one the card shows.
     *
     * @param string $location Location search, or ''.
     *
     * @return array<string, string>|null Null when there is no location.
     */
    protected function location_group(string $location): ?array
    {
        if ($location === '') {
            return null;
        }

        return [
            'm'                           => 'or',
            'addresses_city_i_cont'       => $location,
            'addresses_state_name_i_cont' => $location,
        ];
    }

    /**
     * The `search_query` conditions: the opt-in and each data-field facet.
     *
     * Each key is ANDed; an array value means any-of. Facets with nothing
     * selected are left out, never sent as `''` (which the MDP reads as "no
     * condition").
     *
     * If a facet is on the opt-in field, the opt-in wins: the selection only
     * narrows it, so the record set never grows past eligibility.
     *
     * @param DirectoryConfig $config The directory's config.
     * @param RequestParams   $params The visitor's choices.
     *
     * @return array<string, mixed>|null Empty when there are no conditions; null when they can't match.
     */
    protected function search_query(DirectoryConfig $config, RequestParams $params): ?array
    {
        $search = [];
        $opt_in = $config->eligibility['opt_in'];
        $opt_in_path = null;

        if ($opt_in !== null) {
            $opt_in_path = self::data_field_path($opt_in['schema_key'], $opt_in['field']);
            $search[$opt_in_path] = $opt_in['value'];
        }

        foreach ($config->facets as $facet) {
            if ($facet['source'] !== DirectoryConfig::FACET_DATA_FIELD) {
                continue;
            }

            $values = $params->facet_values($facet);

            if ($values === []) {
                continue;
            }

            $path = self::data_field_path($facet['schema_key'], $facet['field']);

            if ($path === $opt_in_path) {
                if (!in_array(self::scalar_string($opt_in['value']), $values, true)) {
                    return null;
                }

                continue;
            }

            $search[$path] = $values;
        }

        return $search;
    }

    /**
     * The `search_query` key for a data field.
     *
     * @param string $schema_key Schema key.
     * @param string $field      Field name within the schema's value.
     *
     * @return string E.g. `data_fields.orgmemberdir.value.optin`.
     */
    protected static function data_field_path(string $schema_key, string $field): string
    {
        return 'data_fields.' . $schema_key . '.value.' . $field;
    }

    /**
     * Run the query_args filters.
     *
     * A filter that returns something other than an array is ignored.
     *
     * @param array<string, mixed> $args   Query args.
     * @param DirectoryConfig      $config The directory's config.
     * @param RequestParams        $params The visitor's choices.
     * @param string               $slug   The directory post's slug.
     *
     * @return array<string, mixed>
     */
    private function apply_filters(array $args, DirectoryConfig $config, RequestParams $params, string $slug): array
    {
        $hooks = ['wicket_directory/query_args'];

        if ($slug !== '') {
            $hooks[] = 'wicket_directory/query_args_' . $slug;
        }

        foreach ($hooks as $hook) {
            /**
             * Modify a directory's MDP query before it runs.
             *
             * @param array           $args   `filter` (JSON body), `sort`, `page` and `include` (query string).
             * @param DirectoryConfig $config The directory's config.
             * @param RequestParams   $params The visitor's sanitized choices.
             */
            $filtered = apply_filters($hook, $args, $config, $params);

            if (is_array($filtered)) {
                $args = $filtered;
            }
        }

        return $args;
    }

    /**
     * A two-letter language code; it becomes part of MDP keys like `legal_name_en`.
     *
     * @param mixed $lang Raw code.
     *
     * @return string
     */
    private static function language(mixed $lang): string
    {
        $lang = is_string($lang) ? strtolower($lang) : '';

        return preg_match('/^[a-z]{2}$/', $lang) === 1 ? $lang : self::DEFAULT_LANGUAGE;
    }

    /**
     * A config value as it appears in a GET param.
     *
     * @param string|bool $value Value.
     *
     * @return string
     */
    private static function scalar_string(string|bool $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return $value;
    }
}
