<?php

declare(strict_types=1);

namespace Wicket\Directory\Config;

use Wicket\Directory\PostType\DirectoryPostType;
use Wicket\Directory\Template\CardTemplate;
use Wicket\Directory\Template\FieldCatalog;
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
 * @phpstan-type Eligibility array{require_active_membership: bool, membership_ids: list<string>, org_types: list<string>, opt_in: OptIn|null, group_status: string, group_types: list<string>, excluded_groups: list<string>}
 * @phpstan-type Facet array{source: string, schema_key: string, field: string, label: string}
 * @phpstan-type Roster array{default_view: string, group: string, member_status: string}
 * @phpstan-type OrgContact array{show: bool, source: string, relationship_types: list<string>, name: bool, title: bool, relationship: bool, email: ContactRule, phone: ContactRule}
 * @phpstan-type EmployerSettings array{show: bool, relationship_types: list<string>, relationship: bool}
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
     * Facet over group types (group directories only).
     */
    public const FACET_GROUP_TYPE = 'group_type';

    /**
     * Group Status: list active groups only (the default).
     */
    public const GROUP_STATUS_ACTIVE = 'active';

    /**
     * Group Status: list inactive groups only.
     */
    public const GROUP_STATUS_INACTIVE = 'inactive';

    /**
     * Group Status: list groups whatever their status.
     */
    public const GROUP_STATUS_ALL = 'all';

    /**
     * Allowed values of eligibility.group_status.
     */
    public const GROUP_STATUSES = [self::GROUP_STATUS_ACTIVE, self::GROUP_STATUS_INACTIVE, self::GROUP_STATUS_ALL];

    /**
     * Default View: the group list only; there is no member list.
     */
    public const VIEW_GROUPS = 'groups';

    /**
     * Default View: one chosen group's member list (roster.group), and no group list.
     */
    public const VIEW_MEMBERS = 'members';

    /**
     * Default View: the group list, whose View Members buttons open a group's
     * member list on the same page, with a Back to Directory link (the default).
     */
    public const VIEW_GROUPS_MEMBERS = 'groups_members';

    /**
     * Allowed values of roster.default_view.
     */
    public const VIEWS = [self::VIEW_GROUPS_MEMBERS, self::VIEW_GROUPS, self::VIEW_MEMBERS];

    /**
     * Group Member Status: members with an active term in the group (the default).
     */
    public const MEMBER_STATUS_ACTIVE = 'active';

    /**
     * Group Member Status: people whose terms in the group have all ended.
     */
    public const MEMBER_STATUS_INACTIVE = 'inactive';

    /**
     * Group Member Status: everyone who has ever been in the group.
     */
    public const MEMBER_STATUS_ALL = 'all';

    /**
     * Allowed values of roster.member_status.
     */
    public const MEMBER_STATUSES = [self::MEMBER_STATUS_ACTIVE, self::MEMBER_STATUS_INACTIVE, self::MEMBER_STATUS_ALL];

    /**
     * Group Member card keys that come from the person's row in the group
     * (`group_members`), not from the person: role, in-group status and term
     * dates. Only a group's member list has them.
     */
    public const MEMBERSHIP_FIELDS = ['role', 'status', 'start_date', 'end_date'];

    /**
     * Organization Contact source: the owner of the organization's active
     * membership(s), optionally only when they have an active relationship of
     * the chosen types to the organization (the default).
     */
    public const ORG_CONTACT_OWNER = 'owner';

    /**
     * Organization Contact source: every person with an active relationship of
     * the chosen types (any type when none is chosen) to the organization.
     */
    public const ORG_CONTACT_RELATIONSHIP = 'relationship';

    /**
     * Allowed values of card.org_contact.source.
     */
    public const ORG_CONTACT_SOURCES = [self::ORG_CONTACT_OWNER, self::ORG_CONTACT_RELATIONSHIP];

    /**
     * Organization Contact keys that are plain toggles: what each contact shows.
     */
    public const ORG_CONTACT_TOGGLES = ['name', 'title', 'relationship'];

    /**
     * Organization Contact keys that hold a contact rule for the person's own
     * records. They go through ContactResolver, like the card's.
     */
    public const ORG_CONTACT_RECORDS = ['email', 'phone'];

    /**
     * Card mode: the card's toggles lay it out, through the PHP card
     * template (the default).
     */
    public const MODE_STANDARD = 'standard';

    /**
     * Card mode: the card's `[col]` / `{{field}}` template lays it out
     * (Template\CardTemplate). Only with a non-empty template.
     */
    public const MODE_ADVANCED = 'advanced';

    /**
     * Allowed values of a card's `mode`.
     */
    public const MODES = [self::MODE_STANDARD, self::MODE_ADVANCED];

    /**
     * Longest card template kept, in characters.
     */
    public const TEMPLATE_MAX_LENGTH = 20000;

    /**
     * Use defaults(), from_post() or sanitize().
     *
     * @param DirectoryType        $type          What the directory lists.
     * @param Eligibility          $eligibility   Conditions built into the base API query.
     * @param array<string, mixed> $card          Card toggles for $type; see card_defaults().
     * @param list<Facet>          $facets        Filters the directory offers, in display order.
     * @param int                  $cache_version Bumped on every save to invalidate cached results.
     * @param Roster               $roster        Group directories' member list: the Default View, the
     *                                            group it shows in the Group Members view, and the
     *                                            Group Member Status. Defaults for other types.
     * @param array<string, mixed> $member_card   Group directories' Group Member card, the card of a
     *                                            group's member list; see member_card_defaults().
     *                                            Defaults for other types.
     * @param list<Facet>          $member_facets Group directories' member list facets (data fields
     *                                            on people), in display order. Empty for other types.
     */
    private function __construct(
        public readonly DirectoryType $type,
        public readonly array $eligibility,
        public readonly array $card,
        public readonly array $facets,
        public readonly int $cache_version,
        public readonly array $roster,
        public readonly array $member_card,
        public readonly array $member_facets,
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
        return new self($type, self::eligibility_defaults(), self::card_defaults($type), [], 0, self::roster_defaults(), self::member_card_defaults(), []);
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
     * - a type change resets the card, the facets, the Group Member card and
     *   its facets, and the membership tiers to the new type's defaults (tiers
     *   are typed, so old ones would be invisible in the admin yet still
     *   filter the directory);
     * - eligibility keys that don't apply to the type are always reset (see
     *   sanitize_eligibility()), with or without $previous; so are the roster,
     *   the Group Member card and its facets of a type other than group;
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

        $member_card = self::member_card_defaults();
        $member_facets = [];

        if ($type_changed) {
            $eligibility['membership_ids'] = [];
            $card = self::card_defaults($type);
            $facets = [];
        } else {
            $card = self::sanitize_card(is_array($input['card'] ?? null) ? $input['card'] : [], self::card_defaults($type));
            $facets = self::sanitize_facets($input['facets'] ?? null, $type);

            // Members are people: their card is the individual one plus the group fields, their facets a person's.
            if ($type === DirectoryType::Group) {
                $member_card = self::sanitize_card(is_array($input['member_card'] ?? null) ? $input['member_card'] : [], $member_card);
                $member_facets = self::sanitize_facets($input['member_facets'] ?? null, DirectoryType::Individual);
            }
        }

        $cache_version = $previous !== null
            ? $previous->cache_version + 1
            : self::non_negative_int($input['cache_version'] ?? null);

        $roster = self::sanitize_roster(is_array($input['roster'] ?? null) ? $input['roster'] : [], $type);

        return new self($type, $eligibility, $card, $facets, $cache_version, $roster, $member_card, $member_facets);
    }

    /**
     * The config as stored in post meta.
     *
     * @return array{type: string, eligibility: Eligibility, card: array<string, mixed>, facets: list<Facet>, member_card: array<string, mixed>, member_facets: list<Facet>, roster: Roster, cache_version: int}
     */
    public function to_array(): array
    {
        return [
            'type'          => $this->type->value,
            'eligibility'   => $this->eligibility,
            'card'          => $this->card,
            'facets'        => $this->facets,
            'member_card'   => $this->member_card,
            'member_facets' => $this->member_facets,
            'roster'        => $this->roster,
            'cache_version' => $this->cache_version,
        ];
    }

    /**
     * Whether visitors can reach a group's member list from this directory.
     *
     * @return bool True for a group directory whose Default View has a member list.
     */
    public function has_roster(): bool
    {
        return $this->type === DirectoryType::Group && $this->roster['default_view'] !== self::VIEW_GROUPS;
    }

    /**
     * The card a group's member list shows: the Group Member card.
     *
     * The same as the `member_card` property; kept because the member list
     * read it through this method before the card had a config (P2.3).
     *
     * @return array<string, mixed> Card toggles: the individual card's keys plus MEMBERSHIP_FIELDS.
     */
    public function member_card(): array
    {
        return $this->member_card;
    }

    /**
     * Whether the Group Member card shows any field from the person's row in
     * the group, so the member list needs the `group_members` batch.
     *
     * @return bool
     */
    public function shows_membership(): bool
    {
        foreach (self::MEMBERSHIP_FIELDS as $key) {
            if ($this->member_card[$key] === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Organization Contact settings, when the card shows the section.
     *
     * @return OrgContact|null Null for other types, or when the section is off.
     */
    public function org_contact(): ?array
    {
        $contact = $this->type === DirectoryType::Organization ? ($this->card['org_contact'] ?? null) : null;

        return is_array($contact) && $contact['show'] === true ? $contact : null;
    }

    /**
     * The Employer settings of a person card, when it shows Employer.
     *
     * @param bool $members The Group Member card (a group directory's member
     *                      lists) instead of the individual card.
     *
     * @return EmployerSettings|null Null for a card without Employer (organizations, groups), or when it's off.
     */
    public function employer(bool $members = false): ?array
    {
        $card = match (true) {
            $members                                  => $this->type === DirectoryType::Group ? $this->member_card : [],
            $this->type === DirectoryType::Individual => $this->card,
            default                                   => [],
        };
        $employer = $card['employer'] ?? null;

        return is_array($employer) && $employer['show'] === true ? $employer : null;
    }

    /**
     * A card's Advance template, when the card is laid out by one.
     *
     * @param bool $members The Group Member card (group directories) instead of the main card.
     *
     * @return string|null The template; null when the card is Standard or has no template.
     */
    public function template(bool $members = false): ?string
    {
        $card = $members ? ($this->type === DirectoryType::Group ? $this->member_card : []) : $this->card;

        return self::advanced_template($card);
    }

    /**
     * The config the render path uses.
     *
     * A Standard card is as saved. An Advance card's template decides what
     * shows, so its toggles follow the tags it uses
     * (Template\FieldCatalog::toggles()): a used field's toggle is on, an
     * unused one off. The batches (tiers, owners, employers, member counts,
     * the group rows) and EntryMapper then fill exactly what the template
     * prints. Settings that aren't toggles (contact rule types and flags,
     * data-field references, the address format, the website label, Employer
     * and Organization Contact options) keep their saved values. Options of
     * another field (the name parts of `{{name}}`, the Organization Contact
     * options of `{{org_contact}}`; FieldCatalog::OPTION_TOGGLES) are turned
     * on when a tag needs them (`{{salutation}}`, `{{org_contact[1].email}}`)
     * but never off.
     *
     * The stored config is never changed: switching back to Standard finds
     * the toggles as they were.
     *
     * @return self
     */
    public function for_render(): self
    {
        $card = self::render_card($this->card, $this->type, false);
        $member_card = $this->type === DirectoryType::Group ? self::render_card($this->member_card, $this->type, true) : $this->member_card;

        if ($card === $this->card && $member_card === $this->member_card) {
            return $this;
        }

        return new self($this->type, $this->eligibility, $card, $this->facets, $this->cache_version, $this->roster, $member_card, $this->member_facets);
    }

    /**
     * A card's toggles for rendering; see for_render().
     *
     * @param array<string, mixed> $card    The card's settings.
     * @param DirectoryType        $type    Directory type.
     * @param bool                 $members The Group Member card.
     *
     * @return array<string, mixed>
     */
    private static function render_card(array $card, DirectoryType $type, bool $members): array
    {
        $template = self::advanced_template($card);

        if ($template === null) {
            return $card;
        }

        $used = FieldCatalog::toggles(CardTemplate::tags($template), $type, $members);

        foreach (FieldCatalog::all_toggles($type, $members) as $path) {
            $card = self::set_path($card, $path, in_array($path, $used, true));
        }

        foreach ($used as $path) {
            $card = self::set_path($card, $path, true);
        }

        return $card;
    }

    /**
     * Set a boolean card setting by path.
     *
     * @param array<string, mixed> $card  The card's settings.
     * @param string               $path `job_title`, `email.show` or `org_contact.email.show`.
     * @param bool                 $value Value.
     *
     * @return array<string, mixed>
     */
    private static function set_path(array $card, string $path, bool $value): array
    {
        $keys = explode('.', $path);
        $key = array_shift($keys);

        if (!array_key_exists($key, $card)) {
            return $card;
        }

        if ($keys === []) {
            if (is_bool($card[$key])) {
                $card[$key] = $value;
            }

            return $card;
        }

        if (is_array($card[$key])) {
            $card[$key] = self::set_path($card[$key], implode('.', $keys), $value);
        }

        return $card;
    }

    /**
     * A card's template, when its mode is Advance and the template isn't empty.
     *
     * @param array<string, mixed> $card The card's settings.
     *
     * @return string|null
     */
    private static function advanced_template(array $card): ?string
    {
        $template = $card['template'] ?? '';

        return ($card['mode'] ?? null) === self::MODE_ADVANCED && is_string($template) && trim($template) !== '' ? $template : null;
    }

    /**
     * Sanitize a card template.
     *
     * Plain text: tags are stripped (the template escapes its literal text
     * anyway), line breaks kept and normalized to `\n`, and the length capped
     * at TEMPLATE_MAX_LENGTH.
     *
     * @param mixed $value Raw template.
     *
     * @return string
     */
    private static function sanitize_template(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = sanitize_textarea_field(str_replace(["\r\n", "\r"], "\n", $value));

        return mb_substr($value, 0, self::TEMPLATE_MAX_LENGTH);
    }

    /**
     * Default eligibility: active members (or, for groups, active groups) only,
     * no other conditions.
     *
     * Every type stores every key, so the shape never depends on the type.
     * Keys that don't apply to a type keep these defaults.
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
            'group_status'              => self::GROUP_STATUS_ACTIVE,
            'group_types'               => [],
            'excluded_groups'           => [],
        ];
    }

    /**
     * Default roster: the group list with View Members, active members only.
     *
     * Every type stores it, so the shape never depends on the type.
     *
     * @return Roster
     */
    private static function roster_defaults(): array
    {
        return [
            'default_view'  => self::VIEW_GROUPS_MEMBERS,
            'group'         => '',
            'member_status' => self::MEMBER_STATUS_ACTIVE,
        ];
    }

    /**
     * Sanitize the roster for a type. Only group directories have one; other
     * types keep the defaults.
     *
     * The group is kept in every view, so switching back to Group Members
     * finds it again; only the Group Members view reads it.
     *
     * @param array<mixed>  $input Raw roster.
     * @param DirectoryType $type  Directory type.
     *
     * @return Roster
     */
    private static function sanitize_roster(array $input, DirectoryType $type): array
    {
        $defaults = self::roster_defaults();

        if ($type !== DirectoryType::Group) {
            return $defaults;
        }

        $view = $input['default_view'] ?? null;
        $status = $input['member_status'] ?? null;
        $group = self::uuid_list([$input['group'] ?? null]);

        return [
            'default_view'  => in_array($view, self::VIEWS, true) ? $view : $defaults['default_view'],
            'group'         => $group[0] ?? '',
            'member_status' => in_array($status, self::MEMBER_STATUSES, true) ? $status : $defaults['member_status'],
        ];
    }

    /**
     * Default card for a directory type.
     *
     * Personal contact details are off by default for individuals, and so is
     * Employer (`employer`, the person's organizations from their active
     * relationships). Groups
     * have no contact records, member ID, tiers or data fields, so their card
     * has only its own toggles.
     *
     * Every card has a `mode` (Standard, the default) and a `template` (the
     * Advance layout; empty until one is written).
     *
     * @param DirectoryType $type Directory type.
     *
     * @return array<string, mixed>
     */
    private static function card_defaults(DirectoryType $type): array
    {
        $layout = [
            'mode'     => self::MODE_STANDARD,
            'template' => '',
        ];

        $shared = [
            'member_id'       => false,
            'membership_tier' => false,
            'address_format'  => 'full',
            'website_label'   => '',
            'eyebrow'         => self::empty_ref(),
            'tag_chips'       => [],
        ] + $layout;

        return match ($type) {
            DirectoryType::Individual => [
                'salutation'    => false,
                'middle_name'   => false,
                'suffix'        => false,
                'post_nominal'  => self::empty_ref(),
                'job_title'     => true,
                'employer'      => self::employer_defaults(),
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
                'org_contact' => self::org_contact_defaults(),
            ] + $shared,
            DirectoryType::Group => [
                'status'       => true,
                'group_type'   => true,
                'description'  => true,
                'member_count' => true,
                'view_members' => true,
            ] + $layout,
        };
    }

    /**
     * Default Group Member card: the individual card plus the group fields.
     *
     * The person's fields keep the individual defaults (name and job title),
     * but every contact detail starts off: a member list is public, and its
     * admin chooses which of a member's contact records it may show. The
     * group fields (role, in-group status, start and end date) start on.
     *
     * @return array<string, mixed>
     */
    private static function member_card_defaults(): array
    {
        $card = self::card_defaults(DirectoryType::Individual);

        foreach (self::CONTACT_FIELDS as $field) {
            $card[$field]['show'] = false;
        }

        return array_fill_keys(self::MEMBERSHIP_FIELDS, true) + $card;
    }

    /**
     * Default Organization Contact: off, so organization cards saved before
     * it existed render without it.
     *
     * When turned on it shows the membership owner's name and job title (and
     * their relationship type, when types are chosen). The person's email and
     * phone start off: they are a person's own records on a public card.
     *
     * @return OrgContact
     */
    private static function org_contact_defaults(): array
    {
        return [
            'show'               => false,
            'source'             => self::ORG_CONTACT_OWNER,
            'relationship_types' => [],
            'name'               => true,
            'title'              => true,
            'relationship'       => true,
            'email'              => self::contact_rule(false),
            'phone'              => self::contact_rule(false),
        ];
    }

    /**
     * Default Employer: off, so person cards saved before it existed render
     * without it.
     *
     * When turned on, any active relationship to an organization counts until
     * types are chosen, and the relationship type isn't shown.
     *
     * @return EmployerSettings
     */
    private static function employer_defaults(): array
    {
        return [
            'show'               => false,
            'relationship_types' => [],
            'relationship'       => false,
        ];
    }

    /**
     * Sanitize the Employer settings.
     *
     * @param mixed            $value   Raw settings.
     * @param EmployerSettings $default Their defaults.
     *
     * @return EmployerSettings
     */
    private static function sanitize_employer(mixed $value, array $default): array
    {
        $value = is_array($value) ? $value : [];

        return [
            'show'               => self::bool($value['show'] ?? null, $default['show']),
            'relationship_types' => self::identifier_list($value['relationship_types'] ?? null),
            'relationship'       => self::bool($value['relationship'] ?? null, $default['relationship']),
        ];
    }

    /**
     * Sanitize the Organization Contact settings.
     *
     * @param mixed      $value   Raw settings.
     * @param OrgContact $default Their defaults.
     *
     * @return OrgContact
     */
    private static function sanitize_org_contact(mixed $value, array $default): array
    {
        $value = is_array($value) ? $value : [];
        $source = $value['source'] ?? null;

        $contact = [
            'show'               => self::bool($value['show'] ?? null, $default['show']),
            'source'             => in_array($source, self::ORG_CONTACT_SOURCES, true) ? $source : $default['source'],
            'relationship_types' => self::identifier_list($value['relationship_types'] ?? null),
        ];

        foreach (self::ORG_CONTACT_TOGGLES as $key) {
            $contact[$key] = self::bool($value[$key] ?? null, $default[$key]);
        }

        foreach (self::ORG_CONTACT_RECORDS as $key) {
            $contact[$key] = self::sanitize_contact_rule($value[$key] ?? null, $default[$key], true);
        }

        return $contact;
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
     * Sanitize eligibility for a type.
     *
     * Keys that don't apply to the type get their defaults, so a group
     * directory never carries tiers or an opt-in, and a person or organization
     * directory never carries group conditions.
     *
     * @param array<mixed>  $input Raw eligibility.
     * @param DirectoryType $type  Directory type.
     *
     * @return Eligibility
     */
    private static function sanitize_eligibility(array $input, DirectoryType $type): array
    {
        $defaults = self::eligibility_defaults();
        $is_group = $type === DirectoryType::Group;
        $group_status = $input['group_status'] ?? null;

        return [
            'require_active_membership' => self::bool($input['require_active_membership'] ?? null, $defaults['require_active_membership']),
            'membership_ids'            => $is_group ? [] : self::uuid_list($input['membership_ids'] ?? null),
            'org_types'                 => $type === DirectoryType::Organization ? self::identifier_list($input['org_types'] ?? null) : [],
            'opt_in'                    => $is_group ? null : self::sanitize_opt_in($input['opt_in'] ?? null),
            'group_status'              => $is_group && in_array($group_status, self::GROUP_STATUSES, true) ? $group_status : $defaults['group_status'],
            'group_types'               => $is_group ? self::identifier_list($input['group_types'] ?? null) : [],
            'excluded_groups'           => $is_group ? self::uuid_list($input['excluded_groups'] ?? null) : [],
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
     * Sanitize a card against its default keys.
     *
     * @param array<mixed>         $input    Raw card.
     * @param array<string, mixed> $defaults The card's defaults: card_defaults() or member_card_defaults().
     *
     * @return array<string, mixed>
     */
    private static function sanitize_card(array $input, array $defaults): array
    {
        $card = [];

        foreach ($defaults as $key => $default) {
            $value = $input[$key] ?? null;

            $card[$key] = match (true) {
                in_array($key, self::CONTACT_FIELDS, true) => self::sanitize_contact_rule($value, $default, $key !== 'website'),
                $key === 'address_format'                  => in_array($value, self::ADDRESS_FORMATS, true) ? $value : $default,
                $key === 'website_label'                   => is_string($value) ? sanitize_text_field($value) : $default,
                $key === 'tag_chips'                       => self::data_field_list($value),
                $key === 'org_contact'                     => self::sanitize_org_contact($value, $default),
                $key === 'employer'                        => self::sanitize_employer($value, $default),
                $key === 'mode'                            => in_array($value, self::MODES, true) ? $value : $default,
                $key === 'template'                        => self::sanitize_template($value),
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
     * The org-type facet is for organization directories and the group-type
     * facet for group directories; data-field facets are for the other two.
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

            if (
                ($source === self::FACET_ORG_TYPE && $type === DirectoryType::Organization)
                || ($source === self::FACET_GROUP_TYPE && $type === DirectoryType::Group)
            ) {
                $ref = self::empty_ref();
            } elseif ($source === self::FACET_DATA_FIELD && $type !== DirectoryType::Group) {
                // No JSON schema is bound to groups, so a group directory has no data-field facets.
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
     * A reference is `{schema_key, field}`, or the string `"schema_key.field"`
     * that the edit screen's pickers post. Neither part may contain a dot, so
     * the string splits unambiguously.
     *
     * @param mixed $value Raw reference.
     *
     * @return DataFieldRef
     */
    private static function sanitize_data_field_ref(mixed $value): array
    {
        if (is_string($value) && str_contains($value, '.')) {
            [$schema_key, $field] = explode('.', $value, 2);
            $value = ['schema_key' => $schema_key, 'field' => $field];
        }

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
