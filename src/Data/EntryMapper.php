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
 * - Data-field enum keys and org and group type slugs become labels through EnumLabels:
 *   one schema request and one resource-type request per page view at most,
 *   and only when a label is needed. There are no per-row API calls.
 * - Names and descriptions use the current language's attribute.
 * - Each entry then goes through the wicket_directory/entry filters.
 *
 * @phpstan-import-type DataFieldRef from DirectoryConfig
 * @phpstan-import-type Phone from Entry
 * @phpstan-import-type Contact from Entry
 * @phpstan-import-type Employer from Entry
 * @phpstan-import-type EmployerSettings from DirectoryConfig
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
     * @param (callable(string): string)|null $members_url Builds a group's member list URL from its UUID,
     *                                                     for the View Members button. Null leaves the
     *                                                     button off (no member list to reach).
     *
     * @return list<PersonEntry>|list<OrganizationEntry>|list<GroupEntry> One entry per item, in order.
     */
    public function map(ResultPage $page, DirectoryConfig $config, string $slug = '', ?string $lang = null, ?callable $members_url = null): array
    {
        if ($page->is_empty()) {
            return [];
        }

        $lang = QueryBuilder::language($lang ?? wicket_get_current_language());
        $response = new ResponseHelper(['data' => $page->items, 'included' => $page->included]);
        $entries = [];

        foreach ($page->items as $item) {
            $entry = match ($config->type) {
                DirectoryType::Individual   => $this->person($item, $response, $page, $config->card, $lang),
                DirectoryType::Organization => $this->organization($item, $response, $page, $config, $lang),
                DirectoryType::Group        => $this->group($item, $page, $config, $lang, $members_url),
            };

            $entries[] = $this->apply_filters($entry, $item, $config, $slug);
        }

        return $entries;
    }

    /**
     * Map a page of a group's member list.
     *
     * Members are people with a row in the group, so they map to
     * GroupMemberEntry with the directory's Group Member card
     * (DirectoryConfig::$member_card): the person's fields as on an individual
     * card, plus the role, in-group status and term from the page's
     * `group_members` rows. The entry filters get the group directory's
     * config, as for its group cards.
     *
     * @param ResultPage      $page   A page from DirectoryRepository::fetch_roster().
     * @param DirectoryConfig $config The group directory's config.
     * @param string          $slug   The directory post's slug, for the entry_{slug} filter.
     * @param string|null     $lang   Two-letter language code. Defaults to wicket_get_current_language().
     *
     * @return list<GroupMemberEntry> One entry per person, in order.
     */
    public function map_members(ResultPage $page, DirectoryConfig $config, string $slug = '', ?string $lang = null): array
    {
        if ($page->is_empty()) {
            return [];
        }

        $lang = QueryBuilder::language($lang ?? wicket_get_current_language());
        $response = new ResponseHelper(['data' => $page->items, 'included' => $page->included]);
        $entries = [];

        foreach ($page->items as $item) {
            $entries[] = $this->apply_filters($this->member($item, $response, $page, $config->member_card, $lang), $item, $config, $slug);
        }

        return $entries;
    }

    /**
     * The group a member list belongs to, for its header.
     *
     * The header always shows the name, the type and the description, so the
     * card toggles don't apply, and the entry filters don't run: this isn't a
     * card. Status and member count are left empty.
     *
     * @param array<string, mixed> $group `groups` resource, from DirectoryRepository::group().
     * @param string|null          $lang  Two-letter language code. Defaults to wicket_get_current_language().
     *
     * @return GroupEntry
     */
    public function roster_group(array $group, ?string $lang = null): GroupEntry
    {
        $lang = QueryBuilder::language($lang ?? wicket_get_current_language());
        $attributes = self::attributes($group);
        $group_type = self::text($attributes['type'] ?? null);

        return new GroupEntry(
            id: self::text($group['id'] ?? null),
            name: self::localized($attributes, 'name', $lang),
            member_id: '',
            tiers: [],
            addresses: [],
            emails: [],
            phones: [],
            websites: [],
            image_url: '',
            eyebrow: '',
            tags: [],
            status: '',
            group_type: $group_type,
            group_type_label: $group_type !== '' ? ($this->labels->group_types($lang)[$group_type] ?? $group_type) : '',
            description: self::localized($attributes, 'description', $lang),
            member_count: null,
        );
    }

    /**
     * Build an individual's entry.
     *
     * @param array<string, mixed> $item     `people` resource.
     * @param ResponseHelper       $response The page, for included records.
     * @param ResultPage           $page     The page, for tiers.
     * @param array<string, mixed> $card     The individual card's toggles.
     * @param string               $lang     Language code.
     *
     * @return PersonEntry
     */
    private function person(array $item, ResponseHelper $response, ResultPage $page, array $card, string $lang): PersonEntry
    {
        return new PersonEntry(...$this->person_values($item, $response, $page, $card, $lang));
    }

    /**
     * Build a group member's entry: the person, plus their row in the group.
     *
     * Each group field is empty when the card hides it or the page has no row
     * for the person. An open-ended term's end date is END_PRESENT.
     *
     * @param array<string, mixed> $item     `people` resource.
     * @param ResponseHelper       $response The page, for included records.
     * @param ResultPage           $page     The page, for tiers and group member rows.
     * @param array<string, mixed> $card     The Group Member card's toggles.
     * @param string               $lang     Language code.
     *
     * @return GroupMemberEntry
     */
    private function member(array $item, ResponseHelper $response, ResultPage $page, array $card, string $lang): GroupMemberEntry
    {
        $values = $this->person_values($item, $response, $page, $card, $lang);
        $row = $page->group_member_for($values['id']);

        $role = $card['role'] && $row !== null ? self::text($row['role'] ?? null) : '';
        $status = '';
        $end_date = '';

        if ($card['status'] && is_bool($row['active'] ?? null)) {
            $status = $row['active'] ? GroupMemberEntry::STATUS_ACTIVE : GroupMemberEntry::STATUS_INACTIVE;
        }

        if ($card['end_date'] && $row !== null) {
            $end_date = ($row['end_date'] ?? null) === null ? GroupMemberEntry::END_PRESENT : self::date($row['end_date']);
        }

        return new GroupMemberEntry(
            ...$values,
            role: $role,
            role_label: $role !== '' ? ($this->labels->group_member_roles($lang)[$role] ?? $role) : '',
            status: $status,
            start_date: $card['start_date'] && $row !== null ? self::date($row['start_date'] ?? null) : '',
            end_date: $end_date,
        );
    }

    /**
     * A person's entry values, for PersonEntry and GroupMemberEntry.
     *
     * @param array<string, mixed> $item     `people` resource.
     * @param ResponseHelper       $response The page, for included records.
     * @param ResultPage           $page     The page, for tiers and employers.
     * @param array<string, mixed> $card     The card's toggles, in the individual card's shape.
     * @param string               $lang     Language code.
     *
     * @return array<string, mixed> PersonEntry constructor arguments, keyed by name.
     */
    private function person_values(array $item, ResponseHelper $response, ResultPage $page, array $card, string $lang): array
    {
        $attributes = self::attributes($item);

        $salutation = $card['salutation'] ? self::text($attributes['honorific_prefix'] ?? null) : '';
        $given_name = self::text($attributes['given_name'] ?? null);
        $middle_name = $card['middle_name'] ? self::text($attributes['additional_name'] ?? null) : '';
        $family_name = self::text($attributes['family_name'] ?? null);
        $suffix = $card['suffix'] ? self::text($attributes['suffix'] ?? null) : '';

        $name = $given_name !== '' || $family_name !== ''
            ? self::join(' ', [$salutation, $given_name, $middle_name, $family_name, $suffix])
            : self::text($attributes['full_name'] ?? null);

        $values = $this->shared_values($item, $attributes, $response, $page, $card, $lang, $card['profile_image']);

        return $values + [
            'name'         => $name,
            'salutation'   => $salutation,
            'given_name'   => $given_name,
            'middle_name'  => $middle_name,
            'family_name'  => $family_name,
            'suffix'       => $suffix,
            'post_nominal' => implode(', ', $this->data_field_labels($attributes, $card['post_nominal'], $lang)),
            'job_title'    => $card['job_title'] ? self::text($attributes['job_title'] ?? null) : '',
            'employers'    => $this->employers($values['id'], $page, $card['employer'], $lang),
        ];
    }

    /**
     * A person's employers, from the page's batch.
     *
     * Each organization gets its legal name in the current language and, when
     * the card shows it, the labels of the relationship types that qualified
     * it. An organization with no name, or missing from the batch, is left out.
     *
     * @param string           $id       Person UUID.
     * @param ResultPage       $page     The page, with the employer batch.
     * @param EmployerSettings $settings The card's Employer settings.
     * @param string           $lang     Language code.
     *
     * @return list<Employer>
     */
    private function employers(string $id, ResultPage $page, array $settings, string $lang): array
    {
        $refs = $settings['show'] && $id !== '' ? $page->employers_for($id) : [];

        if ($refs === []) {
            return [];
        }

        $organizations = [];

        foreach ($page->employer_organizations as $organization) {
            if (is_string($organization['id'] ?? null)) {
                $organizations[$organization['id']] ??= $organization;
            }
        }

        $labels = $settings['relationship'] ? $this->labels->relationship_types($lang) : [];
        $employers = [];

        foreach ($refs as $ref) {
            $organization = $organizations[$ref['organization'] ?? ''] ?? null;
            $name = $organization !== null ? self::localized(self::attributes($organization), 'legal_name', $lang) : '';

            if ($name === '') {
                continue;
            }

            $types = $settings['relationship'] && is_array($ref['types'] ?? null) ? array_filter($ref['types'], 'is_string') : [];

            $employers[] = [
                'id'           => self::text($organization['id'] ?? null),
                'name'         => $name,
                'relationship' => implode(', ', array_unique(array_map(static fn (string $type): string => $labels[$type] ?? $type, $types))),
            ];
        }

        return $employers;
    }

    /**
     * Build an organization's entry.
     *
     * @param array<string, mixed> $item     `organizations` resource.
     * @param ResponseHelper       $response The page, for included records.
     * @param ResultPage           $page     The page, for tiers and Organization Contacts.
     * @param DirectoryConfig      $config   The organization directory's config.
     * @param string               $lang     Language code.
     *
     * @return OrganizationEntry
     */
    private function organization(array $item, ResponseHelper $response, ResultPage $page, DirectoryConfig $config, string $lang): OrganizationEntry
    {
        $card = $config->card;
        $attributes = self::attributes($item);
        $org_type = $card['org_type'] ? self::text($attributes['type'] ?? null) : '';
        $values = $this->shared_values($item, $attributes, $response, $page, $card, $lang, $card['logo']);

        return new OrganizationEntry(
            ...$values,
            name: self::localized($attributes, 'legal_name', $lang),
            org_type: $org_type,
            org_type_label: $org_type !== '' ? ($this->labels->org_types($lang)[$org_type] ?? $org_type) : '',
            description: $card['description'] ? self::localized($attributes, 'description', $lang) : '',
            contacts: $this->org_contacts($values['id'], $page, $config->org_contact(), $lang),
        );
    }

    /**
     * An organization's Organization Contacts, from the page's batch.
     *
     * Each person gets only what the section shows: the name, the job title,
     * the relationship type labels (when types qualified them), and their
     * email and phone through ContactResolver with the section's rules. A
     * person with nothing to show, or missing from the batch, is left out.
     *
     * @param string                    $id       Organization UUID.
     * @param ResultPage                $page     The page, with the contacts batch.
     * @param array<string, mixed>|null $settings DirectoryConfig::org_contact(); null when the section is off.
     * @param string                    $lang     Language code.
     *
     * @return list<Contact>
     */
    private function org_contacts(string $id, ResultPage $page, ?array $settings, string $lang): array
    {
        $refs = $settings !== null && $id !== '' ? $page->org_contacts_for($id) : [];

        if ($refs === []) {
            return [];
        }

        $people = [];

        foreach ($page->contact_people as $person) {
            if (is_string($person['id'] ?? null)) {
                $people[$person['id']] ??= $person;
            }
        }

        $records = new ResponseHelper(['data' => array_values($people), 'included' => $page->contact_included]);
        $labels = $settings['relationship'] ? $this->labels->relationship_types($lang) : [];
        $contacts = [];

        foreach ($refs as $ref) {
            $person = $people[$ref['person'] ?? ''] ?? null;

            if ($person === null) {
                continue;
            }

            $attributes = self::attributes($person);
            $name = self::join(' ', [self::text($attributes['given_name'] ?? null), self::text($attributes['family_name'] ?? null)])
                ?: self::text($attributes['full_name'] ?? null);
            $found = [];

            foreach (DirectoryConfig::ORG_CONTACT_RECORDS as $field) {
                $list = $settings[$field]['show'] ? $records->getIncludedRelationship($person, ContactResolver::RELATIONSHIPS[$field]) : [];
                $list = is_array($list) && isset($list['type']) ? [$list] : $list;
                $found[$field] = ContactResolver::resolve($field, is_array($list) ? $list : [], $settings[$field]);
            }

            $types = is_array($ref['types'] ?? null) ? array_filter($ref['types'], 'is_string') : [];

            $contact = [
                'id'           => self::text($person['id'] ?? null),
                'name'         => $settings['name'] ? $name : '',
                'title'        => $settings['title'] ? self::text($attributes['job_title'] ?? null) : '',
                'relationship' => implode(', ', array_unique(array_map(static fn (string $type): string => $labels[$type] ?? $type, $settings['relationship'] ? $types : []))),
                'emails'       => self::emails($found['email']),
                'phones'       => self::phones($found['phone']),
            ];

            if ($contact['name'] !== '' || $contact['title'] !== '' || $contact['emails'] !== [] || $contact['phones'] !== []) {
                $contacts[] = $contact;
            }
        }

        return $contacts;
    }

    /**
     * Build a group's entry.
     *
     * The member count is the page's distinct-people count when it has one,
     * else the group's `active_member_count` (which counts active rows).
     * The View Members link is set when the card shows the button and the
     * directory has a member list to reach (a $members_url builder).
     *
     * @param array<string, mixed>            $item        `groups` resource.
     * @param ResultPage                      $page        The page, for member counts.
     * @param DirectoryConfig                 $config      The directory's config.
     * @param string                          $lang        Language code.
     * @param (callable(string): string)|null $members_url Builds a member list URL from a group UUID.
     *
     * @return GroupEntry
     */
    private function group(array $item, ResultPage $page, DirectoryConfig $config, string $lang, ?callable $members_url): GroupEntry
    {
        $card = $config->card;
        $attributes = self::attributes($item);
        $id = self::text($item['id'] ?? null);
        $group_type = $card['group_type'] ? self::text($attributes['type'] ?? null) : '';
        $member_count = null;

        if ($card['member_count']) {
            $fallback = $attributes['active_member_count'] ?? null;
            $member_count = $page->member_count_for($id) ?? (is_int($fallback) ? max(0, $fallback) : null);
        }

        $status = '';

        if ($card['status'] && is_bool($attributes['active'] ?? null)) {
            $status = $attributes['active'] ? GroupEntry::STATUS_ACTIVE : GroupEntry::STATUS_INACTIVE;
        }

        return new GroupEntry(
            id: $id,
            name: self::localized($attributes, 'name', $lang),
            member_id: '',
            tiers: [],
            addresses: [],
            emails: [],
            phones: [],
            websites: [],
            image_url: '',
            eyebrow: '',
            tags: [],
            status: $status,
            group_type: $group_type,
            group_type_label: $group_type !== '' ? ($this->labels->group_types($lang)[$group_type] ?? $group_type) : '',
            description: $card['description'] ? self::localized($attributes, 'description', $lang) : '',
            member_count: $member_count,
            members_url: $members_url !== null && $card['view_members'] && $id !== '' ? (string) $members_url($id) : '',
        );
    }

    /**
     * The values both entry types share, except the name.
     *
     * @param array<string, mixed> $item       Resource.
     * @param array<string, mixed> $attributes Its attributes.
     * @param ResponseHelper       $response   The page, for included records.
     * @param ResultPage           $page       The page, for tiers.
     * @param array<string, mixed> $card       The card's toggles.
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
        array $card,
        string $lang,
        array $image_ref,
    ): array {
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
     * @param PersonEntry|OrganizationEntry|GroupEntry $entry  The mapped entry (a GroupMemberEntry is a PersonEntry).
     * @param array<string, mixed>                     $item   The raw API resource.
     * @param DirectoryConfig                          $config The directory's config.
     * @param string                                   $slug   The directory post's slug.
     *
     * @return PersonEntry|OrganizationEntry|GroupEntry
     */
    private function apply_filters(Entry $entry, array $item, DirectoryConfig $config, string $slug): Entry
    {
        // Literal hook names, so `grep` and Atlas (ATLAS003) find both hooks.

        /**
         * Modify one entry's data before its card renders.
         *
         * Values are raw; templates escape them. Change a key to change the card,
         * empty it to hide that row, or put custom data under `extra` for a theme
         * template override. Unknown keys are dropped, and a value of the wrong
         * type keeps the original.
         *
         * @param array           $data   PersonEntry, GroupMemberEntry, OrganizationEntry or GroupEntry::to_array().
         * @param array           $item   The raw `people` / `organizations` / `groups` resource,
         *                                with `attributes` (including `data_fields`).
         * @param DirectoryConfig $config The directory's config.
         */
        $filtered = apply_filters('wicket_directory/entry', $entry->to_array(), $item, $config);

        if (is_array($filtered)) {
            $entry = $entry->with($filtered);
        }

        if ($slug === '') {
            return $entry;
        }

        /**
         * Modify one directory's entry data; runs after wicket_directory/entry.
         *
         * `{slug}` is the directory post's slug. Same arguments.
         */
        $filtered = apply_filters("wicket_directory/entry_{$slug}", $entry->to_array(), $item, $config);

        return is_array($filtered) ? $entry->with($filtered) : $entry;
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
     * The date part of an MDP date or date-time.
     *
     * @param mixed $value Raw value, e.g. `2020-01-01` or `2020-01-01T00:00:00.000-05:00`.
     *
     * @return string `Y-m-d`; '' when the value doesn't start with a valid date.
     */
    private static function date(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($value), $parts) !== 1) {
            return '';
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $parts[0] : '';
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
