<?php

declare(strict_types=1);

namespace Wicket\Directory\Config;

use Wicket\Directory\PostType\DirectoryPostType;
use WP_Post;

/**
 * A directory's configuration, stored as one array in the config post meta.
 *
 * Instances only come from defaults(), from_post() or sanitize(), so every
 * property always has the full, validated shape below. Consumers can read any
 * key without isset() checks. The shape is described in the MVP plan,
 * "Directory config".
 *
 * A data-field reference is either set on both keys or empty on both; an empty
 * reference means "off".
 *
 * @phpstan-type DataFieldRef array{schema_key: string, field: string}
 * @phpstan-type ContactRule array{show: bool, type: string, only_directory: bool, only_primary: bool}
 * @phpstan-type OptIn array{schema_key: string, field: string, value: string|bool}
 * @phpstan-type Eligibility array{require_active_membership: bool, membership_ids: list<string>, org_types: list<string>, opt_in: OptIn|null}
 * @phpstan-type Facet array{source: string, schema_key: string, field: string, label: string}
 */
final class DirectoryConfig
{
    /**
     * Contact rule type that matches records of any type.
     */
    public const CONTACT_ANY = 'any';

    /**
     * Card keys that hold a contact rule. Every one goes through ContactResolver.
     */
    public const CONTACT_FIELDS = ['address', 'email', 'phone', 'website'];

    /**
     * Allowed values of card.address_format.
     */
    public const ADDRESS_FORMATS = ['full', 'city_province_country', 'city_province'];

    /**
     * Facet sourced from a data-field enum.
     */
    public const FACET_DATA_FIELD = 'data_field';

    /**
     * Facet over organization types (organization directories only).
     */
    public const FACET_ORG_TYPE = 'org_type';

    /**
     * Use defaults(), from_post() or sanitize().
     *
     * @param DirectoryType        $type          What the directory lists.
     * @param Eligibility          $eligibility   Conditions built into the base API query.
     * @param array<string, mixed> $card          Card toggles for $type; see card_defaults().
     * @param list<Facet>          $facets        Filters the directory offers, in display order.
     * @param int                  $cache_version Bumped on every save to invalidate cached results.
     */
    private function __construct(
        public readonly DirectoryType $type,
        public readonly array $eligibility,
        public readonly array $card,
        public readonly array $facets,
        public readonly int $cache_version,
    ) {}

    /**
     * Default config for a directory type.
     *
     * @param DirectoryType $type Directory type.
     *
     * @return self
     */
    public static function defaults(DirectoryType $type = DirectoryType::Individual): self
    {
        return new self($type, self::eligibility_defaults(), self::card_defaults($type), [], 0);
    }

    /**
     * Load a directory's config.
     *
     * The stored value is sanitized again on read, so configs saved by an older
     * version of the plugin come back in the current shape.
     *
     * @param WP_Post|int $post Directory post or ID.
     *
     * @return self|null Null when the post doesn't exist or isn't a directory.
     */
    public static function from_post(WP_Post|int $post): ?self
    {
        $post = get_post($post);

        if (!$post instanceof WP_Post || $post->post_type !== DirectoryPostType::POST_TYPE) {
            return null;
        }

        $stored = get_post_meta($post->ID, DirectoryPostType::META_KEY, true);

        return self::sanitize(is_array($stored) ? $stored : []);
    }

    /**
     * Build a config from untrusted input.
     *
     * Unknown keys are dropped, values are coerced to their types, enums are
     * validated, and anything missing or invalid falls back to its default.
     *
     * Pass the stored config as $previous when saving an edit. Then:
     * - a type change resets the card, the facets and the membership tiers to
     *   the new type's defaults (tiers are typed, so old ones would be invisible
     *   in the admin yet still filter the directory);
     * - cache_version is the previous one plus one.
     *
     * Without $previous the result depends only on $input, so sanitize() is
     * idempotent. That is how the meta sanitize_callback uses it.
     *
     * @param array<mixed> $input    Raw config, e.g. from a form or REST.
     * @param self|null    $previous The stored config being replaced, if any.
     *
     * @return self
     */
    public static function sanitize(array $input, ?self $previous = null): self
    {
        $type = (is_string($input['type'] ?? null) ? DirectoryType::tryFrom($input['type']) : null)
            ?? $previous?->type
            ?? DirectoryType::Individual;

        $type_changed = $previous !== null && $previous->type !== $type;

        $eligibility = self::sanitize_eligibility(
            is_array($input['eligibility'] ?? null) ? $input['eligibility'] : [],
            $type
        );

        if ($type_changed) {
            $eligibility['membership_ids'] = [];
            $card = self::card_defaults($type);
            $facets = [];
        } else {
            $card = self::sanitize_card(is_array($input['card'] ?? null) ? $input['card'] : [], $type);
            $facets = self::sanitize_facets($input['facets'] ?? null, $type);
        }

        $cache_version = $previous !== null
            ? $previous->cache_version + 1
            : self::non_negative_int($input['cache_version'] ?? null);

        return new self($type, $eligibility, $card, $facets, $cache_version);
    }

    /**
     * The config as stored in post meta.
     *
     * @return array{type: string, eligibility: Eligibility, card: array<string, mixed>, facets: list<Facet>, cache_version: int}
     */
    public function to_array(): array
    {
        return [
            'type'          => $this->type->value,
            'eligibility'   => $this->eligibility,
            'card'          => $this->card,
            'facets'        => $this->facets,
            'cache_version' => $this->cache_version,
        ];
    }

    /**
     * Default eligibility: active members only, no other conditions.
     *
     * @return Eligibility
     */
    private static function eligibility_defaults(): array
    {
        return [
            'require_active_membership' => true,
            'membership_ids'            => [],
            'org_types'                 => [],
            'opt_in'                    => null,
        ];
    }

    /**
     * Default card for a directory type.
     *
     * Personal contact details are off by default for individuals.
     *
     * @param DirectoryType $type Directory type.
     *
     * @return array<string, mixed>
     */
    private static function card_defaults(DirectoryType $type): array
    {
        $shared = [
            'member_id'       => false,
            'membership_tier' => false,
            'address_format'  => 'full',
            'website_label'   => '',
            'eyebrow'         => self::empty_ref(),
            'tag_chips'       => [],
        ];

        return match ($type) {
            DirectoryType::Individual => [
                'salutation'    => false,
                'middle_name'   => false,
                'suffix'        => false,
                'post_nominal'  => self::empty_ref(),
                'job_title'     => true,
                'profile_image' => self::empty_ref(),
                'address'       => self::contact_rule(true),
                'email'         => self::contact_rule(false),
                'phone'         => self::contact_rule(false),
                'website'       => self::contact_rule(false),
            ] + $shared,
            DirectoryType::Organization => [
                'org_type'    => true,
                'description' => true,
                'logo'        => self::empty_ref(),
                'address'     => self::contact_rule(true),
                'email'       => self::contact_rule(true),
                'phone'       => self::contact_rule(true),
                'website'     => self::contact_rule(true),
            ] + $shared,
        };
    }

    /**
     * A contact rule with the prototype's default flags (no type, no flags).
     *
     * @param bool $show Whether the field is shown on the card.
     *
     * @return ContactRule
     */
    private static function contact_rule(bool $show): array
    {
        return [
            'show'           => $show,
            'type'           => self::CONTACT_ANY,
            'only_directory' => false,
            'only_primary'   => false,
        ];
    }

    /**
     * An unset data-field reference.
     *
     * @return DataFieldRef
     */
    private static function empty_ref(): array
    {
        return ['schema_key' => '', 'field' => ''];
    }

    /**
     * @param array<mixed>  $input Raw eligibility.
     * @param DirectoryType $type  Directory type.
     *
     * @return Eligibility
     */
    private static function sanitize_eligibility(array $input, DirectoryType $type): array
    {
        $defaults = self::eligibility_defaults();

        return [
            'require_active_membership' => self::bool($input['require_active_membership'] ?? null, $defaults['require_active_membership']),
            'membership_ids'            => self::uuid_list($input['membership_ids'] ?? null),
            'org_types'                 => $type === DirectoryType::Organization ? self::identifier_list($input['org_types'] ?? null) : [],
            'opt_in'                    => self::sanitize_opt_in($input['opt_in'] ?? null),
        ];
    }

    /**
     * The opt-in condition, or null when it isn't fully set.
     *
     * An empty value would make the MDP skip the condition, so it disables the
     * opt-in rather than being stored.
     *
     * @param mixed $value Raw opt-in.
     *
     * @return OptIn|null
     */
    private static function sanitize_opt_in(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $ref = self::sanitize_data_field_ref($value);
        $match = self::opt_in_value($value['value'] ?? null);

        if ($ref['schema_key'] === '' || $match === '') {
            return null;
        }

        return $ref + ['value' => $match];
    }

    /**
     * The value an opt-in data field must have.
     *
     * "true" and "false" become booleans, which is how the MDP stores
     * checkbox-style data fields.
     *
     * @param mixed $value Raw value.
     *
     * @return string|bool Empty string when invalid.
     */
    private static function opt_in_value(mixed $value): string|bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (!is_string($value) && !is_int($value)) {
            return '';
        }

        $value = sanitize_text_field((string) $value);

        return match (strtolower($value)) {
            'true'  => true,
            'false' => false,
            default => $value,
        };
    }

    /**
     * Sanitize the card against the type's default keys.
     *
     * @param array<mixed>  $input Raw card.
     * @param DirectoryType $type  Directory type.
     *
     * @return array<string, mixed>
     */
    private static function sanitize_card(array $input, DirectoryType $type): array
    {
        $card = [];

        foreach (self::card_defaults($type) as $key => $default) {
            $value = $input[$key] ?? null;

            $card[$key] = match (true) {
                in_array($key, self::CONTACT_FIELDS, true) => self::sanitize_contact_rule($value, $default, $key !== 'website'),
                $key === 'address_format'                  => in_array($value, self::ADDRESS_FORMATS, true) ? $value : $default,
                $key === 'website_label'                   => is_string($value) ? sanitize_text_field($value) : $default,
                $key === 'tag_chips'                       => self::data_field_list($value),
                is_bool($default)                          => self::bool($value, $default),
                // The remaining keys are single data-field references.
                default                                    => self::sanitize_data_field_ref($value),
            };
        }

        return $card;
    }

    /**
     * Sanitize one contact rule.
     *
     * @param mixed       $value       Raw rule.
     * @param ContactRule $default     The rule's default.
     * @param bool        $has_primary Whether the record type has a primary flag. Web addresses don't.
     *
     * @return ContactRule
     */
    private static function sanitize_contact_rule(mixed $value, array $default, bool $has_primary): array
    {
        $value = is_array($value) ? $value : [];
        $type = self::identifier($value['type'] ?? null);

        return [
            'show'           => self::bool($value['show'] ?? null, $default['show']),
            'type'           => $type === '' ? self::CONTACT_ANY : $type,
            'only_directory' => self::bool($value['only_directory'] ?? null, $default['only_directory']),
            'only_primary'   => $has_primary && self::bool($value['only_primary'] ?? null, $default['only_primary']),
        ];
    }

    /**
     * Sanitize the facet list: drop invalid and duplicate facets, keep order.
     *
     * @param mixed         $value Raw facets.
     * @param DirectoryType $type  Directory type.
     *
     * @return list<Facet>
     */
    private static function sanitize_facets(mixed $value, DirectoryType $type): array
    {
        if (!is_array($value)) {
            return [];
        }

        $facets = [];

        foreach ($value as $facet) {
            if (!is_array($facet)) {
                continue;
            }

            $source = $facet['source'] ?? null;

            if ($source === self::FACET_ORG_TYPE && $type === DirectoryType::Organization) {
                $ref = self::empty_ref();
            } elseif ($source === self::FACET_DATA_FIELD) {
                $ref = self::sanitize_data_field_ref($facet);

                if ($ref['schema_key'] === '') {
                    continue;
                }
            } else {
                continue;
            }

            $key = $source . ':' . $ref['schema_key'] . '.' . $ref['field'];

            $facets[$key] ??= [
                'source'     => $source,
                'schema_key' => $ref['schema_key'],
                'field'      => $ref['field'],
                'label'      => is_string($facet['label'] ?? null) ? sanitize_text_field($facet['label']) : '',
            ];
        }

        return array_values($facets);
    }

    /**
     * Sanitize a data-field reference. Both parts must be valid, or both are cleared.
     *
     * @param mixed $value Raw reference.
     *
     * @return DataFieldRef
     */
    private static function sanitize_data_field_ref(mixed $value): array
    {
        if (!is_array($value)) {
            return self::empty_ref();
        }

        $schema_key = self::identifier($value['schema_key'] ?? null);
        $field = self::identifier($value['field'] ?? null);

        if ($schema_key === '' || $field === '') {
            return self::empty_ref();
        }

        return ['schema_key' => $schema_key, 'field' => $field];
    }

    /**
     * A list of set, distinct data-field references.
     *
     * @param mixed $value Raw list.
     *
     * @return list<DataFieldRef>
     */
    private static function data_field_list(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $refs = [];

        foreach ($value as $item) {
            $ref = self::sanitize_data_field_ref($item);

            if ($ref['schema_key'] !== '') {
                $refs[$ref['schema_key'] . '.' . $ref['field']] = $ref;
            }
        }

        return array_values($refs);
    }

    /**
     * A schema key, field name or resource-type slug.
     *
     * Case is kept, because data-field names are often camelCase. Dots are not
     * allowed: these values become segments of an MDP search_query path.
     *
     * @param mixed $value Raw value.
     *
     * @return string Empty string when invalid.
     */
    private static function identifier(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);

        return preg_match('/^[A-Za-z0-9_-]{1,100}$/', $value) === 1 ? $value : '';
    }

    /**
     * Distinct valid identifiers.
     *
     * @param mixed $value Raw list.
     *
     * @return list<string>
     */
    private static function identifier_list(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = array_filter(array_map(self::identifier(...), $value), static fn (string $item): bool => $item !== '');

        return array_values(array_unique($items));
    }

    /**
     * Distinct, lower-case UUIDs.
     *
     * @param mixed $value Raw list.
     *
     * @return list<string>
     */
    private static function uuid_list(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $id) {
            if (!is_string($id)) {
                continue;
            }

            $id = strtolower(trim($id));

            if (wp_is_uuid($id)) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Coerce a form, REST or stored value to a boolean.
     *
     * A missing value keeps the default, so forms must submit unchecked
     * checkboxes explicitly (e.g. a hidden "0" input).
     *
     * @param mixed $value   Raw value.
     * @param bool  $default Used when the value is missing or not boolean-like.
     *
     * @return bool
     */
    private static function bool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
        }

        return $default;
    }

    /**
     * Coerce to an integer of 0 or more.
     *
     * @param mixed $value Raw value.
     *
     * @return int
     */
    private static function non_negative_int(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : 0;
    }
}
