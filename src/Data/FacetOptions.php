<?php

declare(strict_types=1);

namespace Wicket\Directory\Data;

use Wicket\Directory\Config\DirectoryConfig;
use Wicket\Directory\Config\DirectoryType;
use Wicket\Directory\Query\QueryBuilder;
use Wicket\Directory\Query\RequestParams;

/**
 * The choices each of a directory's facets offers.
 *
 * Choices come from the MDP's enums, never from the current results:
 * - a data-field facet offers the field's schema enum, labelled through
 *   EnumLabels (`ui:i18n` in the visitor's language first);
 * - the org-type facet offers the organization resource types, narrowed to
 *   `eligibility.org_types` when that is set.
 *
 * Labels come from EnumLabels, so the facets and the cards share its
 * per-language transient.
 *
 * @phpstan-import-type Facet from DirectoryConfig
 *
 * @phpstan-type FacetOption array{value: string, label: string}
 */
final class FacetOptions
{
    /**
     * Label lookup.
     */
    private EnumLabels $labels;

    /**
     * @param EnumLabels|null $labels Label lookup. Defaults to the per-request shared one.
     */
    public function __construct(?EnumLabels $labels = null)
    {
        $this->labels = $labels ?? EnumLabels::shared();
    }

    /**
     * The choices for every facet a directory offers.
     *
     * A facet with no choices (not an enum, or its schema failed to load) maps
     * to `[]`; the filters template hides it.
     *
     * @param DirectoryConfig $config The directory's config.
     * @param string|null     $lang   Two-letter language code. Defaults to wicket_get_current_language().
     *
     * @return array<string, list<FacetOption>> Facet key (RequestParams::facet_key()) => choices,
     *                                          in config order, each facet's in enum order.
     */
    public function for_config(DirectoryConfig $config, ?string $lang = null): array
    {
        $lang = QueryBuilder::language($lang ?? wicket_get_current_language());
        $options = [];

        foreach ($config->facets as $facet) {
            $options[RequestParams::facet_key($facet)] = $this->for_facet($facet, $config, $lang);
        }

        return $options;
    }

    /**
     * The choices for one facet.
     *
     * @param Facet           $facet  A facet from DirectoryConfig::$facets.
     * @param DirectoryConfig $config The directory's config (type and eligible org types).
     * @param string|null     $lang   Two-letter language code. Defaults to wicket_get_current_language().
     *
     * @return list<FacetOption>
     */
    public function for_facet(array $facet, DirectoryConfig $config, ?string $lang = null): array
    {
        $lang = QueryBuilder::language($lang ?? wicket_get_current_language());

        if ($facet['source'] === DirectoryConfig::FACET_ORG_TYPE) {
            $labels = $config->type === DirectoryType::Organization ? $this->labels->org_types($lang) : [];
            $eligible = $config->eligibility['org_types'];

            // Offer only org types the directory can list; the query builder intersects the same way.
            if ($eligible !== []) {
                $labels = array_intersect_key($labels, array_flip($eligible));
            }
        } else {
            $labels = $this->labels->data_field($facet['schema_key'], $facet['field'], $lang);
        }

        $options = [];

        foreach ($labels as $value => $label) {
            // Numeric enum keys come back as int array keys; values are always strings.
            $options[] = ['value' => (string) $value, 'label' => $label];
        }

        return $options;
    }

    /**
     * The allowed values per facet, as RequestParams::from_request() takes them.
     *
     * @param array<string, list<FacetOption>> $options for_config()'s result.
     *
     * @return array<string, list<string>> Facet key => allowed values.
     */
    public static function values(array $options): array
    {
        return array_map(
            static fn (array $choices): array => array_column($choices, 'value'),
            $options,
        );
    }
}
