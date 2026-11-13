<?php

declare(strict_types=1);

namespace Wicket\Directory\Template;

use Wicket\Directory\Config\DirectoryType;

/**
 * Writes the card template a Standard card config stands for.
 *
 * The template lists what the PHP card template (`cards/card-{type}.php`)
 * shows, in the same order and grouping, and only the fields the config
 * turns on. So CardTemplate renders the same card content from it, and
 * FieldCatalog::toggles() of its tags gives back the same toggles. The
 * Advance editor (P2.8) starts from it.
 *
 * Labels and headings are written in the current language.
 */
final class TemplateGenerator
{
    /**
     * The template for a Standard card.
     *
     * @param DirectoryType        $type    Directory type.
     * @param array<string, mixed> $card    The card's settings (DirectoryConfig::$card, or $member_card).
     * @param bool                 $members The Group Member card.
     *
     * @return string
     */
    public static function generate(DirectoryType $type, array $card, bool $members = false): string
    {
        $lines = match (true) {
            $members                              => self::person($card, true),
            $type === DirectoryType::Individual   => self::person($card, false),
            $type === DirectoryType::Organization => self::organization($card),
            default                               => self::group($card),
        };

        return implode("\n", $lines) . "\n";
    }

    /**
     * An individual's card, or a group member's.
     *
     * @param array<string, mixed> $card    The card's settings.
     * @param bool                 $members The Group Member card.
     *
     * @return list<string>
     */
    private static function person(array $card, bool $members): array
    {
        $lines = self::top($card, 'profile_image');
        $lines[] = '# {{name}}' . (self::ref_on($card['post_nominal'] ?? null) ? ', {{post_nominal}}' : '');

        if ($members && self::on($card, 'status')) {
            $lines[] = '{{group_member_status_pill}}';
        }

        if ($members && self::on($card, 'role')) {
            $lines[] = '{{group_member_role}}';
        }

        $lines = [...$lines, ...self::meta($card, [])];

        $details = [];

        if (self::on($card, 'job_title')) {
            $details[] = '{{job_title}}';
        }

        if (self::on($card, 'employer.show')) {
            $details[] = '{{employer}}';
        }

        if ($members && self::on($card, 'start_date')) {
            /* translators: label before a group member's start date. */
            $details[] = __('Start date:', 'wicket-directory') . ' {{group_member_start_date}}';
        }

        if ($members && self::on($card, 'end_date')) {
            $details[] = __('End date:', 'wicket-directory') . ' {{group_member_end_date}}';
        }

        $columns = [];

        if ($details !== []) {
            $columns[] = ['## ' . __('Details', 'wicket-directory'), ...$details];
        }

        return [...$lines, ...self::columns([...$columns, ...self::contact_columns($card)]), ...self::bottom($card)];
    }

    /**
     * An organization's card.
     *
     * @param array<string, mixed> $card The card's settings.
     *
     * @return list<string>
     */
    private static function organization(array $card): array
    {
        $lines = [...self::top($card, 'logo'), '# {{name}}', ...self::meta($card, self::on($card, 'org_type') ? ['{{org_type}}'] : [])];

        if (self::on($card, 'description')) {
            $lines[] = '{{description}}';
        }

        $columns = self::contact_columns($card);

        if (self::on($card, 'org_contact.show')) {
            $columns[] = ['## ' . __('Organization Contacts', 'wicket-directory'), '{{org_contact}}'];
        }

        return [...$lines, ...self::columns($columns), ...self::bottom($card)];
    }

    /**
     * A group's card.
     *
     * @param array<string, mixed> $card The card's settings.
     *
     * @return list<string>
     */
    private static function group(array $card): array
    {
        $lines = ['# {{name}}'];
        $fields = [
            'status'       => '{{group_status_pill}}',
            'group_type'   => '{{group_type}}',
            'member_count' => '{{group_active_members}}',
            'view_members' => '{{view_members_button}}',
            'description'  => '{{description}}',
        ];

        foreach ($fields as $toggle => $line) {
            if (self::on($card, $toggle)) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * The image and eyebrow lines, before the title.
     *
     * @param array<string, mixed> $card      The card's settings.
     * @param string               $image_key The image's data-field key (`profile_image`, `logo`).
     *
     * @return list<string>
     */
    private static function top(array $card, string $image_key): array
    {
        $lines = [];

        if (self::ref_on($card[$image_key] ?? null)) {
            $lines[] = '{{image}}';
        }

        if (self::ref_on($card['eyebrow'] ?? null)) {
            $lines[] = '{{eyebrow}}';
        }

        return $lines;
    }

    /**
     * The meta line: member ID | (org type) | tiers.
     *
     * @param array<string, mixed> $card   The card's settings.
     * @param list<string>         $middle Segments between the ID and the tiers.
     *
     * @return list<string> One line, or none.
     */
    private static function meta(array $card, array $middle): array
    {
        $segments = [];

        if (self::on($card, 'member_id')) {
            /* translators: %s: the member ID merge tag. */
            $segments[] = sprintf(__('ID: %s', 'wicket-directory'), '{{member_id}}');
        }

        array_push($segments, ...$middle);

        if (self::on($card, 'membership_tier')) {
            $segments[] = '{{tiers}}';
        }

        return $segments === [] ? [] : [implode(' | ', $segments)];
    }

    /**
     * The Address and Contact columns.
     *
     * @param array<string, mixed> $card The card's settings.
     *
     * @return list<list<string>>
     */
    private static function contact_columns(array $card): array
    {
        $columns = [];

        if (self::on($card, 'address.show')) {
            $columns[] = ['## ' . __('Address', 'wicket-directory'), '{{address}}'];
        }

        $contact = [];

        if (self::on($card, 'email.show')) {
            $contact[] = __('Email:', 'wicket-directory') . ' {{email}}';
        }

        if (self::on($card, 'phone.show')) {
            $contact[] = __('Phone:', 'wicket-directory') . ' {{phone}}';
        }

        if ($contact !== []) {
            $columns[] = ['## ' . __('Contact', 'wicket-directory'), ...$contact];
        }

        return $columns;
    }

    /**
     * The website buttons and tag chips, after the columns.
     *
     * @param array<string, mixed> $card The card's settings.
     *
     * @return list<string>
     */
    private static function bottom(array $card): array
    {
        $lines = [];

        if (self::on($card, 'website.show')) {
            $lines[] = '{{website_button}}';
        }

        if (($card['tag_chips'] ?? []) !== []) {
            $lines[] = '{{tag_chips}}';
        }

        return $lines;
    }

    /**
     * Columns as template lines.
     *
     * @param list<list<string>> $columns Each column's lines.
     *
     * @return list<string>
     */
    private static function columns(array $columns): array
    {
        $lines = [];

        foreach ($columns as $column) {
            array_push($lines, '[col]', ...$column);
            $lines[] = '[/col]';
        }

        return $lines;
    }

    /**
     * Whether a toggle path is on.
     *
     * @param array<string, mixed> $card The card's settings.
     * @param string               $path `job_title`, or `email.show`.
     *
     * @return bool
     */
    private static function on(array $card, string $path): bool
    {
        $value = $card;

        foreach (explode('.', $path) as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return $value === true;
    }

    /**
     * Whether a data-field reference is set.
     *
     * @param mixed $ref Reference.
     *
     * @return bool
     */
    private static function ref_on(mixed $ref): bool
    {
        return is_array($ref) && is_string($ref['schema_key'] ?? null) && $ref['schema_key'] !== '';
    }
}
