<?php

declare(strict_types=1);

namespace Wicket\Directory\Render;

use Wicket\Directory\Api\ResultPage;
use Wicket\Directory\Config\DirectoryConfig;
use Wicket\Directory\Config\DirectoryType;
use Wicket\Directory\Data\ContactResolver;
use Wicket\Directory\Data\EnumLabels;

/**
 * The made-up record the admin card preview shows when no real one is there.
 *
 * DirectoryRenderer::render_preview() falls back to it when the directory's
 * settings match no record, the API fails, or a group directory has no group
 * whose members to show. It is a page as DirectoryRepository returns it: one
 * raw MDP resource with its included contact records, plus the batches the
 * card asks for (tiers, employers, Organization Contacts, the member's row
 * in the group). So it goes through the same EntryMapper, entry filters and
 * card template as a real record, and the card shows what each of its
 * settings would print.
 *
 * Every contact record matches its card rule (the rule's type, primary and
 * flagged "Show in Directory"), so a contact field that is on always shows.
 * Data fields the card shows (designation, eyebrow, tag chips) get the
 * field's first enum label, else its name. Images stay empty: there is no
 * picture to show. Types and roles are the configured ones, else the first
 * the MDP lists, so their labels are real.
 */
final class PreviewSample
{
    /**
     * UUID of the sample person, organization or group.
     */
    public const ID = '00000000-0000-4000-8000-000000000001';

    /**
     * UUID of the sample employer (a person's) or Organization Contact (an organization's).
     */
    public const RELATED_ID = '00000000-0000-4000-8000-000000000002';

    /**
     * Fallback type of a contact record whose rule takes any type.
     */
    private const CONTACT_TYPE = 'work';

    /**
     * @param EnumLabels $labels Type, role and data-field labels.
     */
    public function __construct(private readonly EnumLabels $labels) {}

    /**
     * A one-record page for a directory's card.
     *
     * @param DirectoryConfig $config  The directory's render config (DirectoryConfig::for_render()).
     * @param bool            $members The Group Member card of a group directory, else the main card.
     * @param string          $lang    Two-letter language code.
     *
     * @return ResultPage
     */
    public function page(DirectoryConfig $config, bool $members, string $lang): ResultPage
    {
        if ($members) {
            return $this->member_page($config->member_card, $lang);
        }

        return match ($config->type) {
            DirectoryType::Individual   => $this->person_page($config->card, $lang),
            DirectoryType::Organization => $this->organization_page($config, $lang),
            DirectoryType::Group        => $this->group_page($config, $lang),
        };
    }

    /**
     * A person, with their tier and employer when the card shows them.
     *
     * @param array<string, mixed> $card The individual card's toggles.
     * @param string               $lang Language code.
     *
     * @return ResultPage
     */
    private function person_page(array $card, string $lang): ResultPage
    {
        [$person, $included] = $this->person($card, $lang);
        $page = self::single($person, $included);

        if ($card['membership_tier'] === true) {
            $page = $page->with_tiers([self::ID => [[
                'id'   => self::RELATED_ID,
                'slug' => 'sample',
                'name' => __('Sample Membership Tier', 'wicket-directory'),
            ]]]);
        }

        if ($card['employer']['show'] === true) {
            $page = $page->with_employers(
                [self::ID => [[
                    'organization' => self::RELATED_ID,
                    'types'        => [$this->first($card['employer']['relationship_types'], $this->labels->relationship_types($lang))],
                ]]],
                [[
                    'type'       => 'organizations',
                    'id'         => self::RELATED_ID,
                    'attributes' => ['legal_name' => __('Example Organization', 'wicket-directory')],
                ]],
            );
        }

        return $page;
    }

    /**
     * A group member: the person, and their row in the group.
     *
     * @param array<string, mixed> $card The Group Member card's toggles.
     * @param string               $lang Language code.
     *
     * @return ResultPage
     */
    private function member_page(array $card, string $lang): ResultPage
    {
        return $this->person_page($card, $lang)->with_group_members([self::ID => [
            'role'       => $this->first([], $this->labels->group_member_roles($lang), 'member'),
            'active'     => true,
            'start_date' => gmdate('Y') . '-01-15',
            'end_date'   => null,
        ]]);
    }

    /**
     * An organization, with one Organization Contact when the card shows the section.
     *
     * @param DirectoryConfig $config The organization directory's render config.
     * @param string          $lang   Language code.
     *
     * @return ResultPage
     */
    private function organization_page(DirectoryConfig $config, string $lang): ResultPage
    {
        $card = $config->card;
        $included = self::contacts($card, 'organization');

        $organization = self::resource('organizations', self::ID, [
            'legal_name'         => __('Example Association', 'wicket-directory'),
            'type'               => $this->first($config->eligibility['org_types'], $this->labels->org_types($lang)),
            'description'        => __('A sample organization: no record in Wicket matches this directory\'s settings yet. Its card shows what each setting prints.', 'wicket-directory'),
            'identifying_number' => '1001',
            'data_fields'        => $this->data_fields($card, $lang),
        ], $included);

        $page = self::single($organization, $included);

        if ($card['membership_tier'] === true) {
            $page = $page->with_tiers([self::ID => [[
                'id'   => self::RELATED_ID,
                'slug' => 'sample',
                'name' => __('Sample Membership Tier', 'wicket-directory'),
            ]]]);
        }

        $contact = $config->org_contact();

        if ($contact !== null) {
            $records = [];

            foreach (DirectoryConfig::ORG_CONTACT_RECORDS as $field) {
                $records[] = self::contact_record($field, self::RELATED_ID, $contact[$field]);
            }

            $person = self::resource('people', self::RELATED_ID, [
                'given_name'  => __('Alex', 'wicket-directory'),
                'family_name' => __('Morgan', 'wicket-directory'),
                'job_title'   => __('Executive Director', 'wicket-directory'),
            ], $records);

            $page = $page->with_org_contacts(
                [self::ID => [[
                    'person' => self::RELATED_ID,
                    'types'  => [$this->first($contact['relationship_types'], $this->labels->relationship_types($lang))],
                ]]],
                [$person],
                $records,
            );
        }

        return $page;
    }

    /**
     * An active group.
     *
     * @param DirectoryConfig $config The group directory's render config.
     * @param string          $lang   Language code.
     *
     * @return ResultPage
     */
    private function group_page(DirectoryConfig $config, string $lang): ResultPage
    {
        return self::single($this->group($config, $lang), []);
    }

    /**
     * The sample group resource: the Group card's record, and the group of a
     * sample member list.
     *
     * @param DirectoryConfig $config The group directory's config.
     * @param string          $lang   Language code.
     *
     * @return array<string, mixed> A `groups` resource.
     */
    public function group(DirectoryConfig $config, string $lang): array
    {
        return self::resource('groups', self::ID, [
            'name'                => __('Example Committee', 'wicket-directory'),
            'type'                => $this->first($config->eligibility['group_types'], $this->labels->group_types($lang)),
            'description'         => __('A sample group: no group in Wicket matches this directory\'s settings yet. Its card shows what each setting prints.', 'wicket-directory'),
            'active'              => true,
            'active_member_count' => 12,
        ]);
    }

    /**
     * A person resource and their included contact records.
     *
     * @param array<string, mixed> $card The card's toggles, in the individual card's shape.
     * @param string               $lang Language code.
     *
     * @return array{array<string, mixed>, list<array<string, mixed>>}
     */
    private function person(array $card, string $lang): array
    {
        $included = self::contacts($card, 'person');

        $person = self::resource('people', self::ID, [
            'honorific_prefix'   => __('Dr.', 'wicket-directory'),
            'given_name'         => __('Jordan', 'wicket-directory'),
            'additional_name'    => __('Lee', 'wicket-directory'),
            'family_name'        => __('Taylor', 'wicket-directory'),
            'suffix'             => __('Jr.', 'wicket-directory'),
            'job_title'          => __('Program Director', 'wicket-directory'),
            'identifying_number' => '1001',
            'data_fields'        => $this->data_fields($card, $lang),
        ], $included);

        return [$person, $included];
    }

    /**
     * One included record per contact field, matching the card's rule.
     *
     * @param array<string, mixed> $card  The card's toggles.
     * @param string               $owner `person` or `organization`: picks the sample values.
     *
     * @return list<array<string, mixed>>
     */
    private static function contacts(array $card, string $owner): array
    {
        $records = [];

        foreach (array_keys(ContactResolver::RELATIONSHIPS) as $field) {
            $records[] = self::contact_record($field, self::ID, $card[$field] ?? [], $owner);
        }

        return $records;
    }

    /**
     * One contact record that the rule picks.
     *
     * @param string               $field `address`, `email`, `phone` or `website`.
     * @param string               $id    Whose record it is, for the record's ID.
     * @param array<string, mixed> $rule  The card's contact rule.
     * @param string               $owner `person` or `organization`.
     *
     * @return array<string, mixed> An `addresses`, `emails`, `phones` or `web_addresses` record.
     */
    private static function contact_record(string $field, string $id, array $rule, string $owner = 'person'): array
    {
        $type = is_string($rule['type'] ?? null) && $rule['type'] !== DirectoryConfig::CONTACT_ANY ? $rule['type'] : self::CONTACT_TYPE;
        $email = $owner === 'organization' ? 'info@example.org' : 'name@example.org';

        $attributes = match ($field) {
            'address' => [
                'address1'     => __('100 Sample Street', 'wicket-directory'),
                'city'         => __('Ottawa', 'wicket-directory'),
                'state_name'   => __('Ontario', 'wicket-directory'),
                'zip_code'     => 'K1A 0A1',
                'country_name' => __('Canada', 'wicket-directory'),
            ],
            'email'   => ['address' => $id === self::ID ? $email : 'contact@example.org'],
            'phone'   => [
                'number'                      => '+16135550100',
                'number_international_format' => '+1 613-555-0100',
            ],
            default   => ['address' => 'https://example.org'],
        };

        return [
            'type'       => ContactResolver::RELATIONSHIPS[$field],
            'id'         => $id . '-' . $field,
            'attributes' => $attributes + [
                'type'              => $type,
                'primary'           => true,
                'consent_directory' => true,
            ],
        ];
    }

    /**
     * The `data_fields` attribute: a value for each text data field the card shows.
     *
     * @param array<string, mixed> $card The card's toggles.
     * @param string               $lang Language code.
     *
     * @return list<array{key: string, value: array<string, string>}>
     */
    private function data_fields(array $card, string $lang): array
    {
        $refs = [];

        foreach (['post_nominal', 'eyebrow'] as $key) {
            if (isset($card[$key]['schema_key'])) {
                $refs[] = $card[$key];
            }
        }

        foreach ($card['tag_chips'] ?? [] as $ref) {
            $refs[] = $ref;
        }

        $values = [];

        foreach ($refs as $ref) {
            $schema_key = (string) ($ref['schema_key'] ?? '');
            $field = (string) ($ref['field'] ?? '');

            if ($schema_key === '' || $field === '') {
                continue;
            }

            $values[$schema_key][$field] = $this->first([], $this->labels->data_field($schema_key, $field, $lang), ucfirst(str_replace('_', ' ', $field)));
        }

        $data_fields = [];

        foreach ($values as $schema_key => $fields) {
            $data_fields[] = ['key' => (string) $schema_key, 'value' => $fields];
        }

        return $data_fields;
    }

    /**
     * The first configured value, else the first key the MDP lists, else a fallback.
     *
     * @param list<string>          $configured Values chosen in the directory's settings.
     * @param array<string, string> $known      Key => label, from EnumLabels.
     * @param string                $fallback   Used when both are empty.
     *
     * @return string
     */
    private function first(array $configured, array $known, string $fallback = ''): string
    {
        if ($configured !== []) {
            return (string) $configured[0];
        }

        return $known !== [] ? (string) array_key_first($known) : $fallback;
    }

    /**
     * A JSON:API resource with to-many relationships to its included records.
     *
     * @param string                     $type       Resource type.
     * @param string                     $id         UUID.
     * @param array<string, mixed>       $attributes Attributes.
     * @param list<array<string, mixed>> $records    Included records it links to.
     *
     * @return array<string, mixed>
     */
    private static function resource(string $type, string $id, array $attributes, array $records = []): array
    {
        $relationships = [];

        foreach ($records as $record) {
            $relationships[$record['type']]['data'][] = ['type' => $record['type'], 'id' => $record['id']];
        }

        return [
            'type'          => $type,
            'id'            => $id,
            'attributes'    => $attributes,
            'relationships' => $relationships,
        ];
    }

    /**
     * A page holding one resource.
     *
     * @param array<string, mixed>       $item     The resource.
     * @param list<array<string, mixed>> $included Its included records.
     *
     * @return ResultPage
     */
    private static function single(array $item, array $included): ResultPage
    {
        return new ResultPage([$item], $included, 1, 1, 1, 1);
    }
}
