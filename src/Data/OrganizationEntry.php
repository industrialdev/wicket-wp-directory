<?php

declare(strict_types=1);

namespace Wicket\Directory\Data;

/**
 * An organization directory card's data.
 *
 * @phpstan-import-type Phone from Entry
 * @phpstan-import-type Contact from Entry
 */
final class OrganizationEntry extends Entry
{
    /**
     * @param string                  $id             Organization UUID.
     * @param string                  $name           `legal_name_{lang}`, else `legal_name`, else `legal_name_en`.
     * @param string                  $member_id      Member ID.
     * @param list<string>            $tiers          Active membership tier names.
     * @param list<list<string>>      $addresses      Address lines.
     * @param list<string>            $emails         Email addresses.
     * @param list<Phone>             $phones         Phone numbers.
     * @param list<string>            $websites       Website URLs.
     * @param string                  $image_url      Logo URL.
     * @param string                  $eyebrow        Eyebrow label(s).
     * @param list<string>            $tags           Tag chip labels.
     * @param string                  $org_type       Organization type slug (`type`).
     * @param string                  $org_type_label Organization type name in the current language.
     * @param string                  $description    `description_{lang}`, else `description`, else
     *                                                `description_en`. Plain text with line breaks.
     * @param array<array-key, mixed> $extra          Free-form data for custom templates.
     * @param list<Contact>           $contacts       Organization Contact: the people to contact, in
     *                                                order, each with only what the card shows. Empty
     *                                                when the section is off or nobody qualifies.
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
        public readonly string $org_type,
        public readonly string $org_type_label,
        public readonly string $description,
        array $extra = [],
        public readonly array $contacts = [],
    ) {
        parent::__construct($id, $name, $member_id, $tiers, $addresses, $emails, $phones, $websites, $image_url, $eyebrow, $tags, $extra);
    }

    /**
     * @return array<string, string>
     */
    protected static function shapes(): array
    {
        return parent::shapes() + [
            'org_type'       => self::SHAPE_STRING,
            'org_type_label' => self::SHAPE_STRING,
            'description'    => self::SHAPE_STRING,
            'contacts'       => self::SHAPE_CONTACTS,
        ];
    }
}
