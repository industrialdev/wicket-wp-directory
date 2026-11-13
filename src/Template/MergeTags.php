<?php

declare(strict_types=1);

namespace Wicket\Directory\Template;

use Wicket\Directory\Config\DirectoryConfig;
use Wicket\Directory\Data\ContactResolver;
use Wicket\Directory\Data\Entry;
use Wicket\Directory\Data\EnumLabels;
use Wicket\Directory\Data\GroupEntry;
use Wicket\Directory\Data\GroupMemberEntry;
use Wicket\Directory\Data\OrganizationEntry;
use Wicket\Directory\Data\PersonEntry;

/**
 * Resolves one `{{tag}}` for one card. The first step that knows the tag wins:
 *
 * 1. Computed fields, which may print markup: the image, contact links,
 *    website buttons, address blocks, employers, tag chips, the description,
 *    group fields (`group_active_member_count`, `group_status_pill`,
 *    `view_members_button`…), a member's row in the group (`group_member_*`)
 *    and Organization Contacts (`org_contact`, `org_contact[n].field`).
 * 2. Typed contact tags: `addresses|emails|phones|websites[options].attribute`.
 *    The options pick records through ContactResolver: `primary`
 *    (primaryOnly=true), `consent` (show-in-directory only), `type=work`,
 *    `consent=true|false`, `primaryOnly=true|false`, comma-separated. With no
 *    option, ContactResolver's default applies (the first show-in-directory
 *    record, else the first primary one). They read the raw records, whatever
 *    the card's contact toggles say.
 * 3. The catalog's plain fields, from the entry (so the wicket_directory/entry
 *    filters apply), plus `data_fields.{schema_key}.{field}` (labels for enum
 *    keys) and `extra.{key}` (what a filter put under `extra`).
 * 4. Legacy aliases (FieldCatalog::ALIASES), resolved through steps 1–3.
 * 5. Anything else is empty: never an error, never the raw tag.
 *
 * Every value is escaped here. Only computed fields print markup, built from
 * escaped values and the theme's components.
 */
final class MergeTags
{
    /**
     * Typed contact tag prefix => ContactResolver field.
     */
    public const CONTACT_TAGS = [
        'addresses' => 'address',
        'emails'    => 'email',
        'phones'    => 'phone',
        'websites'  => 'website',
    ];

    /**
     * Words in a collapsed description, as on the PHP cards.
     */
    private const EXCERPT_WORDS = 40;

    /**
     * Label lookup for data fields.
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
     * Resolve a tag.
     *
     * @param string      $tag     The text between `{{` and `}}`, trimmed.
     * @param CardContext $context The card.
     *
     * @return TagValue Empty when nothing matches or there is no value.
     */
    public function resolve(string $tag, CardContext $context): TagValue
    {
        $tag = trim($tag);
        $value = $this->field($tag, $context);

        if ($value === null && isset(FieldCatalog::ALIASES[$tag])) {
            $value = $this->field(FieldCatalog::ALIASES[$tag], $context);
        }

        return $value ?? TagValue::empty();
    }

    /**
     * Steps 1–3 for a tag.
     *
     * @param string      $tag     Tag.
     * @param CardContext $context The card.
     *
     * @return TagValue|null Null when no step knows the tag.
     */
    private function field(string $tag, CardContext $context): ?TagValue
    {
        return $this->computed($tag, $context)
            ?? $this->contact($tag, $context)
            ?? $this->catalog($tag, $context);
    }

    /**
     * Step 1: computed fields.
     *
     * @param string      $tag     Tag.
     * @param CardContext $context The card.
     *
     * @return TagValue|null
     */
    private function computed(string $tag, CardContext $context): ?TagValue
    {
        $entry = $context->entry;

        if ($entry instanceof GroupEntry) {
            $value = $this->group_field($tag, $entry);

            if ($value !== null) {
                return $value;
            }
        }

        if ($entry instanceof GroupMemberEntry) {
            $value = $this->member_field($tag, $entry);

            if ($value !== null) {
                return $value;
            }
        }

        if ($entry instanceof OrganizationEntry && ($tag === 'org_contact' || str_starts_with($tag, 'org_contact['))) {
            return $this->org_contact($tag, $entry);
        }

        return match ($tag) {
            'image'          => $this->image($entry),
            'email'          => $this->emails($entry->emails),
            'phone'          => $this->phones($entry->phones),
            'address'        => $this->addresses($entry->addresses),
            'website_button' => $this->website_buttons($entry->websites, $context->card),
            'tag_chips'      => $this->tag_chips($entry->tags),
            'employer'       => $entry instanceof PersonEntry ? $this->employers($entry->employers) : null,
            'description'    => $entry instanceof OrganizationEntry || $entry instanceof GroupEntry
                ? $this->description($entry->description, $context->html_id . '-' . sanitize_key($entry->id) . '-description')
                : null,
            default          => null,
        };
    }

    /**
     * A group's computed fields.
     *
     * @param string     $tag   Tag.
     * @param GroupEntry $entry The group.
     *
     * @return TagValue|null
     */
    private function group_field(string $tag, GroupEntry $entry): ?TagValue
    {
        return match ($tag) {
            'group_active_member_count' => $entry->member_count !== null
                ? new TagValue([esc_html(number_format_i18n($entry->member_count))])
                : TagValue::empty(),
            'group_active_members'      => $entry->member_count !== null
                ? TagValue::text(sprintf(
                    /* translators: %s: number of active members in a group. */
                    _n('%s active member', '%s active members', $entry->member_count, 'wicket-directory'),
                    number_format_i18n($entry->member_count),
                ))
                : TagValue::empty(),
            'group_status'              => TagValue::text(self::status_label($entry->status)),
            'group_status_pill'         => $this->status_pill($entry->status),
            'view_members_button'       => $this->view_members($entry),
            default                     => null,
        };
    }

    /**
     * A group member's computed fields: their row in the group.
     *
     * @param string           $tag   Tag.
     * @param GroupMemberEntry $entry The member.
     *
     * @return TagValue|null
     */
    private function member_field(string $tag, GroupMemberEntry $entry): ?TagValue
    {
        return match ($tag) {
            'group_member_role'        => TagValue::text($entry->role_label),
            'group_member_status'      => TagValue::text(self::status_label($entry->status)),
            'group_member_status_pill' => $this->status_pill($entry->status),
            'group_member_start_date'  => TagValue::text(self::date($entry->start_date)),
            'group_member_end_date'    => TagValue::text($entry->end_date === GroupMemberEntry::END_PRESENT
                ? __('Present', 'wicket-directory')
                : self::date($entry->end_date)),
            default                    => null,
        };
    }

    /**
     * Organization Contacts: `org_contact` (the list, as on the Standard
     * card) or `org_contact[n].name|title|relationship|email|phone` (the
     * n-th contact, from 1).
     *
     * @param string            $tag   Tag.
     * @param OrganizationEntry $entry The organization.
     *
     * @return TagValue
     */
    private function org_contact(string $tag, OrganizationEntry $entry): TagValue
    {
        if ($tag === 'org_contact') {
            if ($entry->contacts === []) {
                return TagValue::empty();
            }

            $names = array_map(static fn (array $contact): string => $contact['name'], $entry->contacts);

            return new TagValue(
                TagValue::texts($names)->items,
                true,
                '<ul class="wicket-directory-card__people-list">' . implode('', array_map($this->person(...), $entry->contacts)) . '</ul>',
            );
        }

        if (preg_match('/^org_contact\[(\d+)\]\.(name|title|relationship|email|phone)$/', $tag, $match) !== 1) {
            return TagValue::empty();
        }

        $contact = $entry->contacts[(int) $match[1] - 1] ?? null;

        if ($contact === null) {
            return TagValue::empty();
        }

        return match ($match[2]) {
            'email' => $this->emails($contact['emails']),
            'phone' => $this->phones($contact['phones']),
            default => TagValue::text($contact[$match[2]]),
        };
    }

    /**
     * Step 2: typed contact tags.
     *
     * @param string      $tag     Tag.
     * @param CardContext $context The card.
     *
     * @return TagValue|null
     */
    private function contact(string $tag, CardContext $context): ?TagValue
    {
        if (preg_match('/^(addresses|emails|phones|websites)(?:\[([^\]]*)\])?\.([A-Za-z0-9_]+)$/', $tag, $match) !== 1) {
            return null;
        }

        $field = self::CONTACT_TAGS[$match[1]];

        if ($context->resource === null || $context->entry instanceof GroupEntry) {
            return TagValue::empty();
        }

        $records = ContactResolver::resolve($field, $this->records($context, ContactResolver::RELATIONSHIPS[$field]), self::rule($match[2]));
        $values = [];

        foreach ($records as $record) {
            $value = is_array($record['attributes'] ?? null) ? ($record['attributes'][$match[3]] ?? null) : null;

            if (is_string($value) || is_int($value) || is_float($value)) {
                $values[] = trim((string) $value);
            }
        }

        return TagValue::texts($values);
    }

    /**
     * Step 3: plain fields from the entry, data fields and `extra`.
     *
     * @param string      $tag     Tag.
     * @param CardContext $context The card.
     *
     * @return TagValue|null
     */
    private function catalog(string $tag, CardContext $context): ?TagValue
    {
        $entry = $context->entry;

        if (str_starts_with($tag, 'data_fields.')) {
            return $this->data_field(substr($tag, 12), $context);
        }

        if (str_starts_with($tag, 'extra.')) {
            return self::extra($entry->extra[substr($tag, 6)] ?? null);
        }

        $property = match ($tag) {
            'org_type'   => 'org_type_label',
            'group_type' => 'group_type_label',
            'website'    => 'websites',
            default      => $tag,
        };

        $plain = [
            'name', 'salutation', 'given_name', 'middle_name', 'family_name', 'suffix', 'post_nominal', 'job_title',
            'member_id', 'eyebrow', 'image_url', 'tiers', 'org_type_label', 'group_type_label', 'websites',
        ];

        if (!in_array($property, $plain, true) || !property_exists($entry, $property)) {
            return null;
        }

        $value = $entry->{$property};

        if ($property === 'name' && $entry instanceof GroupEntry && $value === '') {
            // Some MDP groups have no name; the card still needs a title, as on the Standard card.
            $value = __('Unnamed group', 'wicket-directory');
        }

        return match (true) {
            $property === 'tiers'    => TagValue::text(implode(', ', $value)),
            $property === 'websites' => TagValue::texts($value),
            default                  => TagValue::text((string) $value),
        };
    }

    /**
     * A data field's value: enum keys as labels, several values comma-joined.
     *
     * @param string      $path    `{schema_key}.{field}`.
     * @param CardContext $context The card.
     *
     * @return TagValue
     */
    private function data_field(string $path, CardContext $context): TagValue
    {
        $parts = explode('.', $path);

        if (count($parts) !== 2 || $context->resource === null) {
            return TagValue::empty();
        }

        [$schema_key, $field] = $parts;
        $value = null;
        $data_fields = $context->resource['attributes']['data_fields'] ?? null;

        foreach (is_array($data_fields) ? $data_fields : [] as $data_field) {
            if (is_array($data_field) && ($data_field['key'] ?? null) === $schema_key) {
                $value = is_array($data_field['value'] ?? null) ? ($data_field['value'][$field] ?? null) : null;
                break;
            }
        }

        $values = is_array($value) ? (array_is_list($value) ? $value : []) : [$value];
        $values = array_values(array_filter(array_map(self::scalar(...), $values), static fn (string $text): bool => $text !== ''));

        if ($values === []) {
            return TagValue::empty();
        }

        $labels = $this->labels->data_field($schema_key, $field, $context->lang);

        return TagValue::text(implode(', ', array_unique(array_map(static fn (string $key): string => $labels[$key] ?? $key, $values))));
    }

    /**
     * A value from the entry's `extra`: a scalar, or a list of scalars (comma-joined).
     *
     * @param mixed $value Raw value.
     *
     * @return TagValue
     */
    private static function extra(mixed $value): TagValue
    {
        if (is_array($value)) {
            $value = array_is_list($value) ? implode(', ', array_filter(array_map(self::scalar(...), $value), static fn (string $text): bool => $text !== '')) : '';
        }

        return TagValue::text(self::scalar($value));
    }

    /**
     * The profile image or logo.
     *
     * @param Entry $entry The card's entry.
     *
     * @return TagValue
     */
    private function image(Entry $entry): TagValue
    {
        if ($entry->image_url === '') {
            return TagValue::empty();
        }

        $classes = 'wicket-directory-card__image' . ($entry instanceof OrganizationEntry ? ' wicket-directory-card__image--logo' : '');
        // The name is on the card too, so the image is decorative.
        $image = '<img class="' . esc_attr($classes) . '" src="' . esc_url($entry->image_url) . '" alt="" loading="lazy" decoding="async">';

        return new TagValue([$image], false, '<div class="wicket-directory-card__media">' . $image . '</div>', true);
    }

    /**
     * Email links.
     *
     * @param list<string> $emails Addresses.
     *
     * @return TagValue
     */
    private function emails(array $emails): TagValue
    {
        $items = array_map(fn (string $email): string => $this->component('link', [
            'url'     => esc_url('mailto:' . $email),
            'text'    => esc_html($email),
            'classes' => ['wicket-directory-card__email'],
        ]), $emails);

        return new TagValue($items, true);
    }

    /**
     * Phone links, with the extension. A phone without a URI shows as text.
     *
     * @param list<array{number: string, extension: string, uri: string}> $phones Phones.
     *
     * @return TagValue
     */
    private function phones(array $phones): TagValue
    {
        $items = [];

        foreach ($phones as $phone) {
            $number = $phone['extension'] !== ''
                ? sprintf(
                    /* translators: 1: phone number, 2: phone extension. */
                    __('%1$s ext. %2$s', 'wicket-directory'),
                    $phone['number'],
                    $phone['extension'],
                )
                : $phone['number'];

            $items[] = $phone['uri'] !== ''
                ? $this->component('link', [
                    'url'     => esc_url($phone['uri']),
                    'text'    => esc_html($number),
                    'classes' => ['wicket-directory-card__phone'],
                ])
                : '<span class="wicket-directory-card__phone">' . esc_html($number) . '</span>';
        }

        return new TagValue($items, true);
    }

    /**
     * Addresses: each one's lines, joined by line breaks.
     *
     * @param list<list<string>> $addresses Address lines.
     *
     * @return TagValue
     */
    private function addresses(array $addresses): TagValue
    {
        if ($addresses === []) {
            return TagValue::empty();
        }

        $items = array_map(static fn (array $lines): string => implode('<br>', array_map('esc_html', $lines)), $addresses);
        $block = implode('', array_map(static fn (string $lines): string => '<address class="wicket-directory-card__address">' . $lines . '</address>', $items));

        return new TagValue($items, true, $block);
    }

    /**
     * Website buttons, labelled as the card's settings say.
     *
     * @param list<string>         $websites URLs.
     * @param array<string, mixed> $card     The card's settings.
     *
     * @return TagValue
     */
    private function website_buttons(array $websites, array $card): TagValue
    {
        if ($websites === []) {
            return TagValue::empty();
        }

        $label = is_string($card['website_label'] ?? null) && $card['website_label'] !== ''
            ? $card['website_label']
            : __('Visit Website', 'wicket-directory');

        $items = array_map(fn (string $url): string => $this->component('button', [
            'variant'     => 'primary',
            'label'       => esc_html($label),
            'suffix_icon' => 'fa-solid fa-arrow-up-right-from-square',
            'a_tag'       => true,
            'link'        => esc_url($url),
            'link_target' => '_blank',
            'classes'     => ['wicket-directory-card__website'],
            'atts'        => ['rel' => 'noopener'],
        ]), $websites);

        return new TagValue($items, true, '<div class="wicket-directory-card__actions">' . implode('', $items) . '</div>', true);
    }

    /**
     * Tag chips: the labels inline, the chips alone on a line.
     *
     * @param list<string> $tags Labels.
     *
     * @return TagValue
     */
    private function tag_chips(array $tags): TagValue
    {
        if ($tags === []) {
            return TagValue::empty();
        }

        // tag escapes its own label.
        $chips = array_map(fn (string $tag): string => $this->component('tag', ['label' => $tag, 'link' => '']), $tags);

        return new TagValue(TagValue::texts($tags)->items, true, '<div class="card__topics wicket-directory-card__tags">' . implode('', $chips) . '</div>', true);
    }

    /**
     * Employers: each organization's name and, when set, its relationship type.
     *
     * @param list<array{id: string, name: string, relationship: string}> $employers Employers.
     *
     * @return TagValue
     */
    private function employers(array $employers): TagValue
    {
        if ($employers === []) {
            return TagValue::empty();
        }

        $items = array_map(static fn (array $employer): string => '<span class="wicket-directory-card__employer-name">' . esc_html($employer['name']) . '</span>'
            . ($employer['relationship'] !== '' ? ' <span class="wicket-directory-card__employer-relationship">' . esc_html($employer['relationship']) . '</span>' : ''), $employers);
        $block = implode('', array_map(static fn (string $item): string => '<p class="wicket-directory-card__employer">' . $item . '</p>', $items));

        return new TagValue($items, true, $block);
    }

    /**
     * A description: line breaks kept; alone on a line, the Standard card's
     * block with Show more / Show less (Alpine) past the excerpt.
     *
     * @param string $description Plain text.
     * @param string $id          The text's element ID.
     *
     * @return TagValue
     */
    private function description(string $description, string $id): TagValue
    {
        if ($description === '') {
            return TagValue::empty();
        }

        $text = nl2br(esc_html($description), false);
        // wp_trim_words() counts characters for languages that don't split words with spaces.
        $excerpt = wp_trim_words($description, self::EXCERPT_WORDS, '');
        $is_long = $excerpt !== '' && $excerpt !== wp_trim_words($description, self::EXCERPT_WORDS + 1, '');

        $block = '<div class="component-card-listing__excerpt wicket-directory-card__description"' . ($is_long ? ' x-data="{ expanded: false }"' : '') . '>'
            . '<div id="' . esc_attr($id) . '">';

        if ($is_long) {
            $block .= '<p class="wicket-directory-card__description-excerpt" x-show="!expanded" x-cloak>'
                /* translators: %s: the first words of a description. */
                . esc_html(sprintf(__('%s…', 'wicket-directory'), $excerpt))
                . '</p>';
        }

        $block .= '<p class="wicket-directory-card__description-text"' . ($is_long ? ' x-show="expanded"' : '') . '>' . $text . '</p></div>';

        if ($is_long) {
            $block .= '<button type="button" class="wicket-directory-card__description-toggle" x-cloak aria-controls="' . esc_attr($id) . '" :aria-expanded="expanded.toString()" @click="expanded = !expanded">'
                . '<span x-show="!expanded">' . esc_html__('Show more', 'wicket-directory') . '</span>'
                . '<span x-show="expanded">' . esc_html__('Show less', 'wicket-directory') . '</span>'
                . '</button>';
        }

        return new TagValue([$text], false, $block . '</div>', true);
    }

    /**
     * A status pill (the `tag` component); a status a filter set shows as given.
     *
     * @param string $status Status.
     *
     * @return TagValue
     */
    private function status_pill(string $status): TagValue
    {
        $label = self::status_label($status);

        if ($label === '') {
            return TagValue::empty();
        }

        $classes = ['wicket-directory-card__status'];

        if (in_array($status, [GroupEntry::STATUS_ACTIVE, GroupEntry::STATUS_INACTIVE], true)) {
            $classes[] = 'wicket-directory-card__status--' . $status;
        }

        // tag escapes its own label.
        return new TagValue([$this->component('tag', ['label' => $label, 'link' => '', 'classes' => $classes])]);
    }

    /**
     * The View Members button.
     *
     * @param GroupEntry $entry The group.
     *
     * @return TagValue
     */
    private function view_members(GroupEntry $entry): TagValue
    {
        if ($entry->members_url === '') {
            return TagValue::empty();
        }

        $name = $entry->name !== '' ? $entry->name : __('Unnamed group', 'wicket-directory');

        return new TagValue([$this->component('button', [
            'variant' => 'primary',
            'label'   => esc_html__('View Members', 'wicket-directory'),
            'a_tag'   => true,
            'link'    => esc_url($entry->members_url),
            'classes' => ['wicket-directory-card__view-members'],
            // Every card has this button, so its name says whose members; button escapes atts.
            'atts'    => [
                /* translators: %s: group name. */
                'aria-label' => sprintf(__('View Members of %s', 'wicket-directory'), $name),
            ],
        ])]);
    }

    /**
     * One Organization Contact, as on the Standard card.
     *
     * @param array{id: string, name: string, title: string, relationship: string, emails: list<string>, phones: list<array{number: string, extension: string, uri: string}>} $contact Contact.
     *
     * @return string
     */
    private function person(array $contact): string
    {
        $html = '<li class="wicket-directory-card__person">';

        if ($contact['name'] !== '' || $contact['relationship'] !== '') {
            $html .= '<p class="wicket-directory-card__person-heading">'
                . ($contact['name'] !== '' ? '<span class="wicket-directory-card__person-name">' . esc_html($contact['name']) . '</span>' : '')
                . ($contact['relationship'] !== '' ? '<span class="wicket-directory-card__person-relationship">' . esc_html($contact['relationship']) . '</span>' : '')
                . '</p>';
        }

        if ($contact['title'] !== '') {
            $html .= '<p class="wicket-directory-card__person-title">' . esc_html($contact['title']) . '</p>';
        }

        $label = static fn (string $text): string => '<span class="wicket-directory-card__contact-label">' . esc_html($text) . '</span> ';

        foreach ($this->emails($contact['emails'])->items as $email) {
            $html .= '<p class="wicket-directory-card__person-contact">' . $label(__('Email:', 'wicket-directory')) . $email . '</p>';
        }

        foreach ($this->phones($contact['phones'])->items as $phone) {
            $html .= '<p class="wicket-directory-card__person-contact">' . $label(__('Phone:', 'wicket-directory')) . $phone . '</p>';
        }

        return $html . '</li>';
    }

    /**
     * The resource's records of a relationship, from the page's included records.
     *
     * @param CardContext $context      The card.
     * @param string      $relationship Relationship name, e.g. `emails`.
     *
     * @return list<array<string, mixed>> In relationship order; missing records are skipped.
     */
    private function records(CardContext $context, string $relationship): array
    {
        $refs = $context->resource['relationships'][$relationship]['data'] ?? null;

        if (!is_array($refs)) {
            return [];
        }

        // A to-one relationship gives a single reference; contact relationships are to-many.
        $refs = isset($refs['type']) ? [$refs] : $refs;
        $index = [];

        foreach ($context->included as $record) {
            if (is_array($record) && is_string($record['type'] ?? null) && is_string($record['id'] ?? null)) {
                $index[$record['type'] . ':' . $record['id']] ??= $record;
            }
        }

        $records = [];

        foreach ($refs as $ref) {
            $key = is_array($ref) ? ($ref['type'] ?? '') . ':' . ($ref['id'] ?? '') : '';

            if (isset($index[$key])) {
                $records[] = $index[$key];
            }
        }

        return $records;
    }

    /**
     * A contact rule from a typed tag's options.
     *
     * @param string $options Comma-separated: `primary`, `consent`, `type=…`, `consent=…`, `primaryOnly=…`.
     *
     * @return array{show: bool, type: string, only_directory: bool, only_primary: bool}
     */
    private static function rule(string $options): array
    {
        $rule = ['show' => true, 'type' => DirectoryConfig::CONTACT_ANY, 'only_directory' => false, 'only_primary' => false];

        foreach (explode(',', $options) as $option) {
            // A bare option (`primary`, `consent`) means `=true`.
            [$name, $value] = array_map('trim', explode('=', $option, 2)) + [1 => 'true'];
            $flag = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($name === 'type' && preg_match('/^[A-Za-z0-9_-]{1,100}$/', $value) === 1) {
                $rule['type'] = $value;
            } elseif (($name === 'primary' || $name === 'primaryOnly') && $flag !== null) {
                $rule['only_primary'] = $flag;
            } elseif ($name === 'consent' && $flag !== null) {
                $rule['only_directory'] = $flag;
            }
        }

        return $rule;
    }

    /**
     * The label of a known status; another status a filter set, as given.
     *
     * @param string $status Status.
     *
     * @return string
     */
    private static function status_label(string $status): string
    {
        return match ($status) {
            GroupEntry::STATUS_ACTIVE   => __('Active', 'wicket-directory'),
            GroupEntry::STATUS_INACTIVE => __('Inactive', 'wicket-directory'),
            default                     => $status,
        };
    }

    /**
     * A `Y-m-d` date in the site's date format; a value that isn't a date shows as given.
     *
     * @param string $date Date.
     *
     * @return string
     */
    private static function date(string $date): string
    {
        if ($date === '') {
            return '';
        }

        $formatted = mysql2date((string) get_option('date_format'), $date);

        return is_string($formatted) && $formatted !== '' ? $formatted : $date;
    }

    /**
     * A trimmed string or number.
     *
     * @param mixed $value Raw value.
     *
     * @return string '' for booleans, arrays and null.
     */
    private static function scalar(mixed $value): string
    {
        return match (true) {
            is_string($value)                  => trim($value),
            is_int($value) || is_float($value) => (string) $value,
            default                            => '',
        };
    }

    /**
     * A theme component's output.
     *
     * @param string               $name Component name.
     * @param array<string, mixed> $args Its args, escaped as it needs.
     *
     * @return string
     */
    private function component(string $name, array $args): string
    {
        if (!function_exists('get_component')) {
            return '';
        }

        ob_start();

        try {
            get_component($name, $args);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }
}
