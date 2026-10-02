<?php

declare(strict_types=1);

namespace Wicket\Directory\Data;

/**
 * An individual directory card's data.
 *
 * The name parts are what `name` was built from; a template that wants its
 * own name format can use them instead. Parts the card config turns off are
 * empty.
 *
 * @phpstan-import-type Phone from Entry
 */
final class PersonEntry extends Entry
{
    /**
     * @param string                  $id           Person UUID.
     * @param string                  $name         Salutation, given, middle and family name and suffix,
     *                                              as the card config allows. `full_name` when they're empty.
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
     * @param string                  $post_nominal Post-nominal data-field label(s), comma-separated.
     * @param string                  $job_title    `job_title`.
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
        public readonly string $salutation,
        public readonly string $given_name,
        public readonly string $middle_name,
        public readonly string $family_name,
        public readonly string $suffix,
        public readonly string $post_nominal,
        public readonly string $job_title,
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
            'salutation'   => self::SHAPE_STRING,
            'given_name'   => self::SHAPE_STRING,
            'middle_name'  => self::SHAPE_STRING,
            'family_name'  => self::SHAPE_STRING,
            'suffix'       => self::SHAPE_STRING,
            'post_nominal' => self::SHAPE_STRING,
            'job_title'    => self::SHAPE_STRING,
        ];
    }
}
