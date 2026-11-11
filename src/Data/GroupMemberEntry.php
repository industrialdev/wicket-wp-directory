<?php

declare(strict_types=1);

namespace Wicket\Directory\Data;

/**
 * A group member list card's data: a person, plus their row in the group.
 *
 * The person's values are PersonEntry's, built from the Group Member card
 * config. The group values come from the person's `group_members` row (see
 * DirectoryRepository::fetch_roster()): their role, whether that row is
 * active, and its term. They are empty when the card hides them, or when the
 * person has no row on the page (the batch failed or didn't fit).
 *
 * cards/card-group-member shows them.
 *
 * @phpstan-import-type Phone from Entry
 */
final class GroupMemberEntry extends PersonEntry
{
    /**
     * Status of a member whose row in the group is active.
     */
    public const STATUS_ACTIVE = 'active';

    /**
     * Status of a member whose row in the group has ended.
     */
    public const STATUS_INACTIVE = 'inactive';

    /**
     * `end_date` of an open-ended term: the card shows "Present".
     */
    public const END_PRESENT = 'present';

    /**
     * @param string                  $id           Person UUID.
     * @param string                  $name         Display name.
     * @param string                  $member_id    Member ID.
     * @param list<string>            $tiers        Active membership tier names.
     * @param list<list<string>>      $addresses    Address lines.
     * @param list<string>            $emails       Email addresses.
     * @param list<Phone>             $phones       Phone numbers.
     * @param list<string>            $websites     Website URLs.
     * @param string                  $image_url    Profile image URL.
     * @param string                  $eyebrow      Eyebrow label(s).
     * @param list<string>            $tags         Tag chip labels.
     * @param string                  $salutation   `honorific_prefix`.
     * @param string                  $given_name   `given_name`.
     * @param string                  $middle_name  `additional_name`.
     * @param string                  $family_name  `family_name`.
     * @param string                  $suffix       `suffix`.
     * @param string                  $post_nominal Post-nominal data-field label(s).
     * @param string                  $job_title    `job_title`.
     * @param string                  $role         Role slug in the group (the row's `type`).
     * @param string                  $role_label   Role name in the current language (the
     *                                              `group_members` resource type); the slug when
     *                                              it has none.
     * @param string                  $status       STATUS_ACTIVE or STATUS_INACTIVE, from the row's
     *                                              `active` flag, not the person's account status.
     * @param string                  $start_date   The row's `start_date`, `Y-m-d`.
     * @param string                  $end_date     The row's `end_date`, `Y-m-d`; END_PRESENT when
     *                                              the term is open-ended.
     * @param array<array-key, mixed> $extra        Free-form data for custom templates.
     */
    public function __construct(
        string $id,
        string $name,
        string $member_id,
        array $tiers,
        array $addresses,
        array $emails,
        array $phones,
        array $websites,
        string $image_url,
        string $eyebrow,
        array $tags,
        string $salutation,
        string $given_name,
        string $middle_name,
        string $family_name,
        string $suffix,
        string $post_nominal,
        string $job_title,
        public readonly string $role = '',
        public readonly string $role_label = '',
        public readonly string $status = '',
        public readonly string $start_date = '',
        public readonly string $end_date = '',
        array $extra = [],
    ) {
        parent::__construct(
            $id,
            $name,
            $member_id,
            $tiers,
            $addresses,
            $emails,
            $phones,
            $websites,
            $image_url,
            $eyebrow,
            $tags,
            $salutation,
            $given_name,
            $middle_name,
            $family_name,
            $suffix,
            $post_nominal,
            $job_title,
            $extra,
        );
    }

    /**
     * @return array<string, string>
     */
    protected static function shapes(): array
    {
        return parent::shapes() + [
            'role'       => self::SHAPE_STRING,
            'role_label' => self::SHAPE_STRING,
            'status'     => self::SHAPE_STRING,
            'start_date' => self::SHAPE_STRING,
            'end_date'   => self::SHAPE_STRING,
        ];
    }
}
