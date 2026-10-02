<?php

declare(strict_types=1);

namespace Wicket\Directory\Data;

use Wicket\Directory\Api\ResultPage;
use Wicket\Directory\Config\DirectoryConfig;
use Wicket\Directory\Config\DirectoryType;
use Wicket\Directory\Query\QueryBuilder;
use Wicket\ResponseHelper;

/**
 * Turns a ResultPage's raw resources into card-ready entries.
 *
 * - Contact records are matched to their owner with ResponseHelper and picked
 *   by ContactResolver.
 * - Data-field enum keys and org type slugs become labels through EnumLabels:
 *   one schema request and one resource-type request per page view at most,
 *   and only when a label is needed. There are no per-row API calls.
 * - Names and descriptions use the current language's attribute.
 * - Each entry then goes through the wicket_directory/entry filters.
 *
 * @phpstan-import-type DataFieldRef from DirectoryConfig
 * @phpstan-import-type Phone from Entry
 */
final class EntryMapper
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
     * Map a page of results.
     *
     * @param ResultPage      $page   The page from DirectoryRepository.
     * @param DirectoryConfig $config The directory's config: its type picks the entry class, its card
     *                                the fields.
     * @param string          $slug   The directory post's slug, for the entry_{slug} filter.
     * @param string|null     $lang   Two-letter language code. Defaults to wicket_get_current_language().
     *
     * @return list<PersonEntry>|list<OrganizationEntry> One entry per item, in order.
     */
    public function map(ResultPage $page, DirectoryConfig $config, string $slug = '', ?string $lang = null): array
    {
        if ($page->is_empty()) {
            return [];
        }

        $lang = QueryBuilder::language($lang ?? wicket_get_current_language());
        $response = new ResponseHelper(['data' => $page->items, 'included' => $page->included]);
        $entries = [];

        foreach ($page->items as $item) {
            $entry = $config->type === DirectoryType::Individual
                ? $this->person($item, $response, $page, $config, $lang)
                : $this->organization($item, $response, $page, $config, $lang);

            $entries[] = $this->apply_filters($entry, $item, $config, $slug);
        }

        return $entries;
    }

    /**
     * Build an individual's entry.
     *
     * @param array<string, mixed> $item     `people` resource.
     * @param ResponseHelper       $response The page, for included records.
     * @param ResultPage           $page     The page, for tiers.
     * @param DirectoryConfig      $config   The directory's config.
     * @param string               $lang     Language code.
     *
     * @return PersonEntry
     */
    private function person(array $item, ResponseHelper $response, ResultPage $page, DirectoryConfig $config, string $lang): PersonEntry
    {
        $card = $config->card;
        $attributes = self::attributes($item);

        $salutation = $card['salutation'] ? self::text($attributes['honorific_prefix'] ?? null) : '';
        $given_name = self::text($attributes['given_name'] ?? null);
        $middle_name = $card['middle_name'] ? self::text($attributes['additional_name'] ?? null) : '';
        $family_name = self::text($attributes['family_name'] ?? null);
        $suffix = $card['suffix'] ? self::text($attributes['suffix'] ?? null) : '';

        $name = $given_name !== '' || $family_name !== ''
            ? self::join(' ', [$salutation, $given_name, $middle_name, $family_name, $suffix])
            : self::text($attributes['full_name'] ?? null);

        return new PersonEntry(
            ...$this->shared_values($item, $attributes, $response, $page, $config, $lang, $card['profile_image']),
            name: $name,
            salutation: $salutation,
            given_name: $given_name,
            middle_name: $middle_name,
            family_name: $family_name,
            suffix: $suffix,
            post_nominal: implode(', ', $this->data_field_labels($attributes, $card['post_nominal'], $lang)),
            job_title: $card['job_title'] ? self::text($attributes['job_title'] ?? null) : '',
        );
    }

    /**
     * Build an organization's entry.
     *
     * @param array<string, mixed> $item     `organizations` resource.
     * @param ResponseHelper       $response The page, for included records.
     * @param ResultPage           $page     The page, for tiers.
     * @param DirectoryConfig      $config   The directory's config.
     * @param string               $lang     Language code.
     *
     * @return OrganizationEntry
     */
    private function organization(array $item, ResponseHelper $response, ResultPage $page, DirectoryConfig $config, string $lang): OrganizationEntry
    {
        $card = $config->card;
        $attributes = self::attributes($item);
        $org_type = $card['org_type'] ? self::text($attributes['type'] ?? null) : '';

        return new OrganizationEntry(
            ...$this->shared_values($item, $attributes, $response, $page, $config, $lang, $card['logo']),
            name: self::localized($attributes, 'legal_name', $lang),
            org_type: $org_type,
            org_type_label: $org_type !== '' ? ($this->labels->org_types($lang)[$org_type] ?? $org_type) : '',
            description: $card['description'] ? self::localized($attributes, 'description', $lang) : '',
        );
    }

    /**
     * The values both entry types share, except the name.
     *
     * @param array<string, mixed> $item       Resource.
     * @param array<string, mixed> $attributes Its attributes.
     * @param ResponseHelper       $response   The page, for included records.
     * @param ResultPage           $page       The page, for tiers.
     * @param DirectoryConfig      $config     The directory's config.
     * @param string               $lang       Language code.
     * @param DataFieldRef         $image_ref  The data field holding the profile image or logo URL.
     *
     * @return array<string, mixed> Constructor arguments, keyed by name.
     */
    private function shared_values(
        array $item,
        array $attributes,
        ResponseHelper $response,
        ResultPage $page,
        DirectoryConfig $config,
        string $lang,
        array $image_ref,
    ): array {
        $card = $config->card;
        $id = self::text($item['id'] ?? null);
        $contacts = [];

        foreach (ContactResolver::RELATIONSHIPS as $field => $relationship) {
            $records = $response->getIncludedRelationship($item, $relationship);

            // A to-one relationship gives a single resource; contact relationships are to-many.
            if (is_array($records) && isset($records['type'])) {
                $records = [$records];
            }

            $contacts[$field] = ContactResolver::resolve($field, is_array($records) ? $records : [], $card[$field]);
        }

        $tags = [];

        foreach ($card['tag_chips'] as $ref) {
            array_push($tags, ...$this->data_field_labels($attributes, $ref, $lang));
        }

        return [
            'id'        => $id,
            'member_id' => $card['member_id'] ? self::member_id($attributes) : '',
            'tiers'     => $card['membership_tier'] ? self::tier_names($page->tiers_for($id)) : [],
            'addresses' => self::addresses($contacts['address'], $card['address_format']),
            'emails'    => self::emails($contacts['email']),
            'phones'    => self::phones($contacts['phone']),
            'websites'  => self::websites($contacts['website']),
            'image_url' => self::url(self::data_field_value($attributes, $image_ref)),
            'eyebrow'   => implode(', ', $this->data_field_labels($attributes, $card['eyebrow'], $lang)),
            'tags'      => array_values(array_unique($tags)),
        ];
    }

    /**
     * Run the entry filters and rebuild the entry from their result.
     *
     * A filter that returns something other than an array is ignored.
     *
     * @param PersonEntry|OrganizationEntry $entry  The mapped entry.
     * @param array<string, mixed>          $item   The raw API resource.
     * @param DirectoryConfig               $config The directory's config.
     * @param string                        $slug   The directory post's slug.
     *
     * @return PersonEntry|OrganizationEntry
     */
    private function apply_filters(Entry $entry, array $item, DirectoryConfig $config, string $slug): Entry
    {
        $hooks = ['wicket_directory/entry'];

        if ($slug !== '') {
            $hooks[] = 'wicket_directory/entry_' . $slug;
        }

        foreach ($hooks as $hook) {
            /**
             * Modify one entry's data before its card renders.
             *
             * Values are raw; templates escape them. Change a key to change the card,
             * empty it to hide that row, or put custom data under `extra` for a theme
             * template override. Unknown keys are dropped, and a value of the wrong
             * type keeps the original.
             *
             * @param array           $data   PersonEntry or OrganizationEntry::to_array().
             * @param array           $item   The raw `people` / `organizations` resource, with
             *                                `attributes` (including `data_fields`).
             * @param DirectoryConfig $config The directory's config.
             */
            $filtered = apply_filters($hook, $entry->to_array(), $item, $config);

            if (is_array($filtered)) {
                $entry = $entry->with($filtered);
            }
        }

        return $entry;
    }

    /**
     * Labels for a data field's value(s).
     *
     * A string or number is one value; a list gives one value per item.
     * Booleans, objects and empty values give nothing. Enum keys become
     * labels; other values (free text) are kept as they are.
     *
     * @param array<string, mixed> $attributes Entity attributes.
     * @param DataFieldRef         $ref        Data-field reference; empty means off.
     * @param string               $lang       Language code.
     *
     * @return list<string> Distinct labels, in value order.
     */
    private function data_field_labels(array $attributes, array $ref, string $lang): array
    {
        $value = self::data_field_value($attributes, $ref);
        $values = is_array($value) ? (array_is_list($value) ? $value : []) : [$value];
        $values = array_values(array_filter(array_map(self::scalar_text(...), $values), static fn (string $text): bool => $text !== ''));

        if ($values === []) {
            return [];
        }

        $labels = $this->labels->data_field($ref['schema_key'], $ref['field'], $lang);

        return array_values(array_unique(array_map(static fn (string $key): string => $labels[$key] ?? $key, $values)));
    }

    /**
     * A data field's raw value.
     *
     * @param array<string, mixed> $attributes Entity attributes.
     * @param DataFieldRef         $ref        Data-field reference; empty means off.
     *
     * @return mixed Null when the reference is off or the entity has no value.
     */
    private static function data_field_value(array $attributes, array $ref): mixed
    {
        if ($ref['schema_key'] === '' || !is_array($attributes['data_fields'] ?? null)) {
            return null;
        }

        foreach ($attributes['data_fields'] as $data_field) {
            if (is_array($data_field) && ($data_field['key'] ?? null) === $ref['schema_key']) {
                return is_array($data_field['value'] ?? null) ? ($data_field['value'][$ref['field']] ?? null) : null;
            }
        }

        return null;
    }

    /**
     * Address display lines.
     *
     * - `full`: street lines, "City, Province Postcode", country.
     * - `city_province_country`: "City, Province, Country".
     * - `city_province`: "City, Province".
     *
     * @param list<array<string, mixed>> $records Address records from ContactResolver.
     * @param string                     $format  One of DirectoryConfig::ADDRESS_FORMATS.
     *
     * @return list<list<string>> One list of lines per address; addresses with no lines are dropped.
     */
    private static function addresses(array $records, string $format): array
    {
        $addresses = [];

        foreach ($records as $record) {
            $attributes = self::attributes($record);
            $city = self::text($attributes['city'] ?? null);
            $state = self::text($attributes['state_name'] ?? null);
            $country = self::text($attributes['country_name'] ?? null);

            $lines = match ($format) {
                'city_province_country' => [self::join(', ', [$city, $state, $country])],
                'city_province'         => [self::join(', ', [$city, $state])],
                default                 => [
                    self::text($attributes['address1'] ?? null),
                    self::text($attributes['address2'] ?? null),
                    self::join(' ', [self::join(', ', [$city, $state]), self::text($attributes['zip_code'] ?? null)]),
                    $country,
                ],
            };

            $lines = array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));

            if ($lines !== []) {
                $addresses[] = $lines;
            }
        }

        return $addresses;
    }

    /**
     * Valid email addresses.
     *
     * @param list<array<string, mixed>> $records Email records from ContactResolver.
     *
     * @return list<string>
     */
    private static function emails(array $records): array
    {
        $emails = [];

        foreach ($records as $record) {
            $email = self::text(self::attributes($record)['address'] ?? null);

            if ($email !== '' && is_email($email) !== false) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * Phone numbers with a tel: URI.
     *
     * @param list<array<string, mixed>> $records Phone records from ContactResolver.
     *
     * @return list<Phone>
     */
    private static function phones(array $records): array
    {
        $phones = [];

        foreach ($records as $record) {
            $attributes = self::attributes($record);
            $raw = self::text($attributes['number'] ?? null);
            $number = self::text($attributes['number_international_format'] ?? null)
                ?: self::text($attributes['number_national_format'] ?? null)
                ?: $raw;
            $dial = preg_replace('/[^0-9+]/', '', $raw !== '' ? $raw : $number) ?? '';

            if ($number === '' || $dial === '') {
                continue;
            }

            $extension = self::scalar_text($attributes['extension'] ?? null);
            $extension_digits = preg_replace('/\D/', '', $extension) ?? '';

            $phones[] = [
                'number'    => $number,
                'extension' => $extension,
                'uri'       => 'tel:' . $dial . ($extension_digits !== '' ? ';ext=' . $extension_digits : ''),
            ];
        }

        return $phones;
    }

    /**
     * Website URLs. A URL stored without a scheme gets `https://`.
     *
     * @param list<array<string, mixed>> $records Web address records from ContactResolver.
     *
     * @return list<string>
     */
    private static function websites(array $records): array
    {
        $urls = [];

        foreach ($records as $record) {
            $url = self::text(self::attributes($record)['address'] ?? null);

            if ($url !== '' && preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) !== 1) {
                $url = 'https://' . ltrim($url, '/');
            }

            $url = self::url($url);

            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Tier names, distinct and non-empty.
     *
     * @param list<array{id: string, slug: string, name: string}> $tiers From ResultPage::tiers_for().
     *
     * @return list<string>
     */
    private static function tier_names(array $tiers): array
    {
        $names = array_map(static fn (array $tier): string => self::text($tier['name'] ?? null), $tiers);

        return array_values(array_unique(array_filter($names, static fn (string $name): bool => $name !== '')));
    }

    /**
     * The member ID as the MDP displays it.
     *
     * @param array<string, mixed> $attributes Entity attributes.
     *
     * @return string
     */
    private static function member_id(array $attributes): string
    {
        return self::scalar_text($attributes['display_identifying_number'] ?? null)
            ?: self::scalar_text($attributes['identifying_number'] ?? null);
    }

    /**
     * A translatable attribute in the current language.
     *
     * @param array<string, mixed> $attributes Entity attributes.
     * @param string               $name       Base attribute name, e.g. `legal_name`.
     * @param string               $lang       Language code.
     *
     * @return string `{name}_{lang}`, else `{name}`, else `{name}_en`, else ''.
     */
    private static function localized(array $attributes, string $name, string $lang): string
    {
        foreach ([$name . '_' . $lang, $name, $name . '_en'] as $key) {
            $value = self::text($attributes[$key] ?? null);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * An http(s) URL, sanitized for storage.
     *
     * @param mixed $value Raw value.
     *
     * @return string '' when it isn't a usable http(s) URL.
     */
    private static function url(mixed $value): string
    {
        $url = self::text($value);

        return $url === '' ? '' : esc_url_raw($url, ['http', 'https']);
    }

    /**
     * Join the non-empty parts.
     *
     * @param string       $separator Separator.
     * @param list<string> $parts     Parts.
     *
     * @return string
     */
    private static function join(string $separator, array $parts): string
    {
        return implode($separator, array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * A resource's attributes.
     *
     * @param array<string, mixed> $resource JSON:API resource.
     *
     * @return array<string, mixed>
     */
    private static function attributes(array $resource): array
    {
        return is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];
    }

    /**
     * A trimmed string attribute.
     *
     * @param mixed $value Raw value.
     *
     * @return string '' for anything that isn't a string.
     */
    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * A trimmed string or number.
     *
     * @param mixed $value Raw value.
     *
     * @return string '' for booleans, arrays and null.
     */
    private static function scalar_text(mixed $value): string
    {
        return is_int($value) || is_float($value) ? (string) $value : self::text($value);
    }
}
