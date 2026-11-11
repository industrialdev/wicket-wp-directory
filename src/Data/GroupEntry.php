<?php

declare(strict_types=1);

namespace Wicket\Directory\Data;

/**
 * A group directory card's data.
 *
 * Groups have no member ID, tiers, contact records, image, eyebrow or tags,
 * so those inherited values are always empty unless a filter sets them.
 * cards/card-group shows the name, status, group type, description, member
 * count and View Members button.
 *
 * @phpstan-import-type Phone from Entry
 */
final class GroupEntry extends Entry
{
    /**
     * Status of an active group.
     */
    public const STATUS_ACTIVE = 'active';

    /**
     * Status of an inactive group.
     */
    public const STATUS_INACTIVE = 'inactive';

    /**
     * @param string                  $id               Group UUID.
     * @param string                  $name             `name_{lang}`, else `name`, else `name_en`. Can be
     *                                                  empty: some groups have no name.
     * @param string                  $member_id        Unused; empty.
     * @param list<string>            $tiers            Unused; empty.
     * @param list<list<string>>      $addresses        Unused; empty.
     * @param list<string>            $emails           Unused; empty.
     * @param list<Phone>             $phones           Unused; empty.
     * @param list<string>            $websites         Unused; empty.
     * @param string                  $image_url        Unused; empty.
     * @param string                  $eyebrow          Unused; empty.
     * @param list<string>            $tags             Unused; empty.
     * @param string                  $status           STATUS_ACTIVE or STATUS_INACTIVE (`active`); '' when
     *                                                  the card hides it.
     * @param string                  $group_type       Group type slug (`type`).
     * @param string                  $group_type_label Group type name in the current language.
     * @param string                  $description      `description_{lang}`, else `description`, else
     *                                                  `description_en`. Plain text with line breaks.
     * @param int|null                $member_count     Distinct active members; null when the card hides it.
     * @param string                  $members_url      The group's member list (the View Members button);
     *                                                  '' hides the button. Set when the card shows the
     *                                                  button and the Default View has a member list;
     *                                                  a filter can change it.
     * @param array<array-key, mixed> $extra            Free-form data for custom templates.
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
        public readonly string $status,
        public readonly string $group_type,
        public readonly string $group_type_label,
        public readonly string $description,
        public readonly ?int $member_count,
        public readonly string $members_url = '',
        array $extra = [],
    ) {
        parent::__construct($id, $name, $member_id, $tiers, $addresses, $emails, $phones, $websites, $image_url, $eyebrow, $tags, $extra);
    }

    /**
     * @return array<string, string>
     */
    protected static function shapes(): array
    {
        return parent::shapes() + [
            'status'           => self::SHAPE_STRING,
            'group_type'       => self::SHAPE_STRING,
            'group_type_label' => self::SHAPE_STRING,
            'description'      => self::SHAPE_STRING,
            'member_count'     => self::SHAPE_COUNT,
            'members_url'      => self::SHAPE_STRING,
        ];
    }
}
