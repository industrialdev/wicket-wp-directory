<?php

declare(strict_types=1);

namespace Wicket\Directory\Template;

use Wicket\Directory\Config\DirectoryType;

/**
 * The merge tags a card template can use, per directory type.
 *
 * Each field has a picker label, a group (Profile, Addresses, Email, Phone,
 * Website, Membership, Connections, Group, Data Fields) and the card toggles
 * it needs. DirectoryConfig::for_render() turns those toggles on for the
 * fields an Advance template uses (and off for the rest), so the page's
 * batches and EntryMapper fill exactly what the template shows.
 *
 * The list backs the Advance editor's field picker (P2.8). It also holds a
 * few typed contact tags as examples; any `addresses|emails|phones|websites[…].field`
 * tag and any `data_fields.{schema_key}.{field}` tag works, listed or not.
 *
 * @phpstan-type Field array{key: string, label: string, group: string, toggles: list<string>}
 */
final class FieldCatalog
{
    public const GROUP_PROFILE = 'profile';
    public const GROUP_ADDRESSES = 'addresses';
    public const GROUP_EMAIL = 'email';
    public const GROUP_PHONE = 'phone';
    public const GROUP_WEBSITE = 'website';
    public const GROUP_MEMBERSHIP = 'membership';
    public const GROUP_CONNECTIONS = 'connections';
    public const GROUP_GROUP = 'group';
    public const GROUP_DATA_FIELDS = 'data_fields';

    /**
     * Name parts: options of `{{name}}` as much as fields of their own, so
     * DirectoryConfig::for_render() turns them on for their tag but never off.
     */
    public const OPTION_TOGGLES = ['salutation', 'middle_name', 'suffix'];

    /**
     * Older tag names => the catalog key they stand for (resolver step 4).
     *
     * They are the names child-theme member directories and the prototype
     * used. A key that a type doesn't have resolves empty for that type.
     */
    public const ALIASES = [
        'first_name'        => 'given_name',
        'last_name'         => 'family_name',
        'full_name'         => 'name',
        'legal_name'        => 'name',
        'organization_name' => 'name',
        'group_name'        => 'name',
        'title'             => 'job_title',
        'designation'       => 'post_nominal',
        'prefix'            => 'salutation',
        'membership'        => 'tiers',
        'membership_tier'   => 'tiers',
        'organization_type' => 'org_type',
        'org_type_label'    => 'org_type',
        'group_type_label'  => 'group_type',
        'employer_name'     => 'employer',
        'company'           => 'employer',
        'email_address'     => 'email',
        'phone_number'      => 'phone',
        'website_url'       => 'website',
        'logo'              => 'image',
        'profile_image'     => 'image',
        'member_count'      => 'group_active_member_count',
        'role'              => 'group_member_role',
        'start_date'        => 'group_member_start_date',
        'end_date'          => 'group_member_end_date',
    ];

    /**
     * The picker's group labels, in display order.
     *
     * @return array<string, string> Group => label.
     */
    public static function groups(): array
    {
        return [
            self::GROUP_PROFILE     => __('Profile', 'wicket-directory'),
            self::GROUP_GROUP       => __('Group', 'wicket-directory'),
            self::GROUP_ADDRESSES   => __('Addresses', 'wicket-directory'),
            self::GROUP_EMAIL       => __('Email', 'wicket-directory'),
            self::GROUP_PHONE       => __('Phone', 'wicket-directory'),
            self::GROUP_WEBSITE     => __('Website', 'wicket-directory'),
            self::GROUP_MEMBERSHIP  => __('Membership', 'wicket-directory'),
            self::GROUP_CONNECTIONS => __('Connections', 'wicket-directory'),
            self::GROUP_DATA_FIELDS => __('Data Fields', 'wicket-directory'),
        ];
    }

    /**
     * The fields of a card, keyed by tag.
     *
     * @param DirectoryType $type    Directory type.
     * @param bool          $members The Group Member card (a group directory's member lists).
     * @param list<array{schema_key: string, field: string, label: string}> $data_fields
     *        Data fields to list under Data Fields, e.g. from Admin\SchemaFields. Any
     *        `data_fields.*` tag resolves whether it's listed or not.
     *
     * @return array<string, Field> In picker order.
     */
    public static function fields(DirectoryType $type, bool $members = false, array $data_fields = []): array
    {
        $person = $members || $type === DirectoryType::Individual;

        $list = match (true) {
            $person                                     => self::person($members),
            $type === DirectoryType::Organization       => self::organization(),
            default                                     => self::group(),
        };

        if ($type !== DirectoryType::Group || $members) {
            foreach ($data_fields as $data_field) {
                $key = 'data_fields.' . $data_field['schema_key'] . '.' . $data_field['field'];
                $list[] = self::field($key, $data_field['label'], self::GROUP_DATA_FIELDS);
            }
        }

        $fields = [];

        foreach ($list as $field) {
            $fields[$field['key']] = $field;
        }

        return $fields;
    }

    /**
     * The fields grouped for the picker.
     *
     * @param DirectoryType $type        Directory type.
     * @param bool          $members     The Group Member card.
     * @param list<array{schema_key: string, field: string, label: string}> $data_fields See fields().
     *
     * @return array<string, array{label: string, fields: array<string, string>}> Group => its label and
     *                                                                            its fields (tag => label);
     *                                                                            empty groups are left out.
     */
    public static function grouped(DirectoryType $type, bool $members = false, array $data_fields = []): array
    {
        $groups = [];

        foreach (self::groups() as $group => $label) {
            $groups[$group] = ['label' => $label, 'fields' => []];
        }

        foreach (self::fields($type, $members, $data_fields) as $key => $field) {
            $groups[$field['group']]['fields'][$key] = $field['label'];
        }

        return array_filter($groups, static fn (array $group): bool => $group['fields'] !== []);
    }

    /**
     * The card toggles a set of tags needs.
     *
     * Aliases count as the field they stand for, and `org_contact[n].field`
     * counts as its listed form. Unknown tags, typed contact tags and data
     * field tags need no toggle.
     *
     * @param list<string>  $tags    Tags, as CardTemplate::tags() returns them.
     * @param DirectoryType $type    Directory type.
     * @param bool          $members The Group Member card.
     *
     * @return list<string> Toggle paths (`job_title`, `email.show`, `org_contact.title`…), distinct.
     */
    public static function toggles(array $tags, DirectoryType $type, bool $members = false): array
    {
        $fields = self::fields($type, $members);
        $toggles = [];

        foreach ($tags as $tag) {
            $key = self::ALIASES[$tag] ?? $tag;
            $key = preg_replace('/^org_contact\[\d+\]\./', 'org_contact[1].', $key) ?? $key;

            foreach ($fields[$key]['toggles'] ?? [] as $toggle) {
                $toggles[$toggle] = true;
            }
        }

        return array_keys($toggles);
    }

    /**
     * The toggles that decide whether a field shows. DirectoryConfig::for_render()
     * turns the ones an Advance template doesn't use off.
     *
     * Options of another field aren't in it, so a template that uses only
     * that field keeps them as saved: the name parts (`salutation`,
     * `middle_name`, `suffix`) say what `{{name}}` holds, and Organization
     * Contact's options (`org_contact.name`, `org_contact.email.show`…) what
     * `{{org_contact}}` lists. A tag of their own still turns them on.
     *
     * @param DirectoryType $type    Directory type.
     * @param bool          $members The Group Member card.
     *
     * @return list<string>
     */
    public static function all_toggles(DirectoryType $type, bool $members = false): array
    {
        $toggles = [];

        foreach (self::fields($type, $members) as $field) {
            foreach ($field['toggles'] as $toggle) {
                if (!in_array($toggle, self::OPTION_TOGGLES, true) && (!str_starts_with($toggle, 'org_contact.') || $toggle === 'org_contact.show')) {
                    $toggles[$toggle] = true;
                }
            }
        }

        return array_keys($toggles);
    }

    /**
     * A person's fields: the individual card, or the Group Member card.
     *
     * @param bool $members Add the member's row in the group.
     *
     * @return list<Field>
     */
    private static function person(bool $members): array
    {
        $fields = [
            self::field('name', __('Name', 'wicket-directory'), self::GROUP_PROFILE),
            self::field('salutation', __('Salutation', 'wicket-directory'), self::GROUP_PROFILE, ['salutation']),
            self::field('given_name', __('Given name', 'wicket-directory'), self::GROUP_PROFILE),
            self::field('middle_name', __('Middle name', 'wicket-directory'), self::GROUP_PROFILE, ['middle_name']),
            self::field('family_name', __('Family name', 'wicket-directory'), self::GROUP_PROFILE),
            self::field('suffix', __('Suffix', 'wicket-directory'), self::GROUP_PROFILE, ['suffix']),
            self::field('post_nominal', __('Designation', 'wicket-directory'), self::GROUP_PROFILE),
            self::field('job_title', __('Job title', 'wicket-directory'), self::GROUP_PROFILE, ['job_title']),
            self::field('member_id', __('Member ID', 'wicket-directory'), self::GROUP_PROFILE, ['member_id']),
            self::field('eyebrow', __('Eyebrow', 'wicket-directory'), self::GROUP_PROFILE),
            self::field('image', __('Profile image', 'wicket-directory'), self::GROUP_PROFILE),
            self::field('image_url', __('Profile image URL', 'wicket-directory'), self::GROUP_PROFILE),
            ...self::contact_fields(),
            self::field('tiers', __('Membership tier', 'wicket-directory'), self::GROUP_MEMBERSHIP, ['membership_tier']),
        ];

        if ($members) {
            array_push(
                $fields,
                self::field('group_member_role', __('Role in the group', 'wicket-directory'), self::GROUP_MEMBERSHIP, ['role']),
                self::field('group_member_status', __('Status in the group', 'wicket-directory'), self::GROUP_MEMBERSHIP, ['status']),
                self::field('group_member_status_pill', __('Status in the group (pill)', 'wicket-directory'), self::GROUP_MEMBERSHIP, ['status']),
                self::field('group_member_start_date', __('Start date', 'wicket-directory'), self::GROUP_MEMBERSHIP, ['start_date']),
                self::field('group_member_end_date', __('End date', 'wicket-directory'), self::GROUP_MEMBERSHIP, ['end_date']),
            );
        }

        $fields[] = self::field('employer', __('Employer', 'wicket-directory'), self::GROUP_CONNECTIONS, ['employer.show']);
        $fields[] = self::field('tag_chips', __('Tag chips', 'wicket-directory'), self::GROUP_DATA_FIELDS);

        return $fields;
    }

    /**
     * An organization's fields.
     *
     * @return list<Field>
     */
    private static function organization(): array
    {
        $contact = ['org_contact.show'];

        return [
            self::field('name', __('Name', 'wicket-directory'), self::GROUP_PROFILE),
            self::field('org_type', __('Organization type', 'wicket-directory'), self::GROUP_PROFILE, ['org_type']),
            self::field('description', __('Description', 'wicket-directory'), self::GROUP_PROFILE, ['description']),
            self::field('member_id', __('Member ID', 'wicket-directory'), self::GROUP_PROFILE, ['member_id']),
            self::field('eyebrow', __('Eyebrow', 'wicket-directory'), self::GROUP_PROFILE),
            self::field('image', __('Logo', 'wicket-directory'), self::GROUP_PROFILE),
            self::field('image_url', __('Logo URL', 'wicket-directory'), self::GROUP_PROFILE),
            ...self::contact_fields(),
            self::field('tiers', __('Membership tier', 'wicket-directory'), self::GROUP_MEMBERSHIP, ['membership_tier']),
            self::field('org_contact', __('Organization Contacts', 'wicket-directory'), self::GROUP_CONNECTIONS, $contact),
            self::field('org_contact[1].name', __('First Organization Contact: name', 'wicket-directory'), self::GROUP_CONNECTIONS, [...$contact, 'org_contact.name']),
            self::field('org_contact[1].title', __('First Organization Contact: job title', 'wicket-directory'), self::GROUP_CONNECTIONS, [...$contact, 'org_contact.title']),
            self::field('org_contact[1].relationship', __('First Organization Contact: relationship type', 'wicket-directory'), self::GROUP_CONNECTIONS, [...$contact, 'org_contact.relationship']),
            self::field('org_contact[1].email', __('First Organization Contact: email', 'wicket-directory'), self::GROUP_CONNECTIONS, [...$contact, 'org_contact.email.show']),
            self::field('org_contact[1].phone', __('First Organization Contact: phone', 'wicket-directory'), self::GROUP_CONNECTIONS, [...$contact, 'org_contact.phone.show']),
            self::field('tag_chips', __('Tag chips', 'wicket-directory'), self::GROUP_DATA_FIELDS),
        ];
    }

    /**
     * A group's fields.
     *
     * @return list<Field>
     */
    private static function group(): array
    {
        return [
            self::field('name', __('Name', 'wicket-directory'), self::GROUP_PROFILE),
            self::field('group_type', __('Group type', 'wicket-directory'), self::GROUP_PROFILE, ['group_type']),
            self::field('description', __('Description', 'wicket-directory'), self::GROUP_PROFILE, ['description']),
            self::field('group_status', __('Status', 'wicket-directory'), self::GROUP_GROUP, ['status']),
            self::field('group_status_pill', __('Status (pill)', 'wicket-directory'), self::GROUP_GROUP, ['status']),
            self::field('group_active_member_count', __('Active member count (number)', 'wicket-directory'), self::GROUP_GROUP, ['member_count']),
            self::field('group_active_members', __('Active member count ("12 active members")', 'wicket-directory'), self::GROUP_GROUP, ['member_count']),
            self::field('view_members_button', __('View Members button', 'wicket-directory'), self::GROUP_GROUP, ['view_members']),
        ];
    }

    /**
     * The contact fields people and organizations share: the card's own
     * contact rules, and examples of typed contact tags.
     *
     * @return list<Field>
     */
    private static function contact_fields(): array
    {
        return [
            self::field('address', __('Address', 'wicket-directory'), self::GROUP_ADDRESSES, ['address.show']),
            self::field('addresses[primary].address1', __('Primary address: street', 'wicket-directory'), self::GROUP_ADDRESSES),
            self::field('addresses[primary].city', __('Primary address: city', 'wicket-directory'), self::GROUP_ADDRESSES),
            self::field('addresses[primary].state_name', __('Primary address: province or state', 'wicket-directory'), self::GROUP_ADDRESSES),
            self::field('addresses[primary].zip_code', __('Primary address: postal code', 'wicket-directory'), self::GROUP_ADDRESSES),
            self::field('addresses[primary].country_name', __('Primary address: country', 'wicket-directory'), self::GROUP_ADDRESSES),
            self::field('email', __('Email', 'wicket-directory'), self::GROUP_EMAIL, ['email.show']),
            self::field('emails[primary].address', __('Primary email address (text)', 'wicket-directory'), self::GROUP_EMAIL),
            self::field('phone', __('Phone', 'wicket-directory'), self::GROUP_PHONE, ['phone.show']),
            self::field('phones[primary].number_international_format', __('Primary phone number (text)', 'wicket-directory'), self::GROUP_PHONE),
            self::field('website', __('Website URL', 'wicket-directory'), self::GROUP_WEBSITE, ['website.show']),
            self::field('website_button', __('Website button', 'wicket-directory'), self::GROUP_WEBSITE, ['website.show']),
        ];
    }

    /**
     * One field.
     *
     * @param string       $key     Tag.
     * @param string       $label   Picker label.
     * @param string       $group   One of the GROUP_* constants.
     * @param list<string> $toggles Card toggle paths the field needs.
     *
     * @return Field
     */
    private static function field(string $key, string $label, string $group, array $toggles = []): array
    {
        return ['key' => $key, 'label' => $label, 'group' => $group, 'toggles' => $toggles];
    }
}
