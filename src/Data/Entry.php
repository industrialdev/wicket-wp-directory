<?php

declare(strict_types=1);

namespace Wicket\Directory\Data;

/**
 * One card's data: flat, display-ready values built by EntryMapper.
 *
 * Values are raw (unescaped) text and URLs; templates escape them on output.
 * A field the card config turns off, or that has no value, is empty (`''` or
 * `[]`), so a template shows a row only when its value is non-empty.
 *
 * Entries are immutable. The wicket_directory/entry filters get to_array()
 * and EntryMapper rebuilds the entry with with(), which keeps every value in
 * its declared shape.
 *
 * @phpstan-type Phone array{number: string, extension: string, uri: string}
 */
abstract class Entry
{
    /**
     * Shape of a plain string value.
     */
    protected const SHAPE_STRING = 'string';

    /**
     * Shape of a list of non-empty strings.
     */
    protected const SHAPE_STRINGS = 'strings';

    /**
     * Shape of a list of addresses, each a list of non-empty lines.
     */
    protected const SHAPE_LINES = 'lines';

    /**
     * Shape of a list of Phone arrays.
     */
    protected const SHAPE_PHONES = 'phones';

    /**
     * Shape of a free-form array.
     */
    protected const SHAPE_MAP = 'map';

    /**
     * @param string                   $id        Person or organization UUID.
     * @param string                   $name      Display name, the card title.
     * @param string                   $member_id Member ID (`display_identifying_number`).
     * @param list<string>             $tiers     Active membership tier names.
     * @param list<list<string>>       $addresses Addresses picked by ContactResolver, each as display
     *                                            lines in the card's address_format.
     * @param list<string>             $emails    Email addresses.
     * @param list<Phone>              $phones    Phone numbers: display number, extension and a tel: URI.
     * @param list<string>             $websites  Website URLs (http or https).
     * @param string                   $image_url Profile image or logo URL (http or https).
     * @param string                   $eyebrow   Eyebrow data-field label(s), comma-separated.
     * @param list<string>             $tags      Tag chip labels, one per data-field value.
     * @param array<array-key, mixed>  $extra     Free-form data for custom templates; empty by default.
     *                                            Filters can put anything here.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $member_id,
        public readonly array $tiers,
        public readonly array $addresses,
        public readonly array $emails,
        public readonly array $phones,
        public readonly array $websites,
        public readonly string $image_url,
        public readonly string $eyebrow,
        public readonly array $tags,
        public readonly array $extra = [],
    ) {}

    /**
     * The entry as an array: every property, keyed by name.
     *
     * @return array<string, mixed>
     */
    public function to_array(): array
    {
        return get_object_vars($this);
    }

    /**
     * A copy with some values changed.
     *
     * Unknown keys are ignored. A value of the wrong shape keeps the current
     * value; list items of the wrong type are dropped.
     *
     * @param array<mixed> $changes New values, keyed by property name.
     *
     * @return static
     */
    public function with(array $changes): static
    {
        $values = $this->to_array();

        foreach (static::shapes() as $key => $shape) {
            if (array_key_exists($key, $changes)) {
                $values[$key] = self::coerce($shape, $changes[$key], $values[$key]);
            }
        }

        return new static(...$values);
    }

    /**
     * The shape of each property, for with().
     *
     * Subclasses add their own properties.
     *
     * @return array<string, string> Property name => one of the SHAPE_* constants.
     */
    protected static function shapes(): array
    {
        return [
            'id'        => self::SHAPE_STRING,
            'name'      => self::SHAPE_STRING,
            'member_id' => self::SHAPE_STRING,
            'tiers'     => self::SHAPE_STRINGS,
            'addresses' => self::SHAPE_LINES,
            'emails'    => self::SHAPE_STRINGS,
            'phones'    => self::SHAPE_PHONES,
            'websites'  => self::SHAPE_STRINGS,
            'image_url' => self::SHAPE_STRING,
            'eyebrow'   => self::SHAPE_STRING,
            'tags'      => self::SHAPE_STRINGS,
            'extra'     => self::SHAPE_MAP,
        ];
    }

    /**
     * Coerce a filtered value to a property's shape.
     *
     * @param string $shape   One of the SHAPE_* constants.
     * @param mixed  $value   New value.
     * @param mixed  $current Current value, kept when $value has the wrong shape.
     *
     * @return mixed
     */
    private static function coerce(string $shape, mixed $value, mixed $current): mixed
    {
        if ($shape === self::SHAPE_STRING) {
            return self::string($value) ?? $current;
        }

        if (!is_array($value)) {
            return $current;
        }

        return match ($shape) {
            self::SHAPE_STRINGS => self::strings($value),
            self::SHAPE_LINES   => array_values(array_filter(
                array_map(static fn (mixed $lines): array => is_array($lines) ? self::strings($lines) : [], $value),
                static fn (array $lines): bool => $lines !== [],
            )),
            self::SHAPE_PHONES  => array_values(array_filter(array_map(self::phone(...), $value))),
            default             => $value,
        };
    }

    /**
     * A phone in the Phone shape.
     *
     * @param mixed $value Raw phone.
     *
     * @return Phone|null Null when it has no number.
     */
    private static function phone(mixed $value): ?array
    {
        $number = is_array($value) ? self::string($value['number'] ?? null) : null;

        if ($number === null || $number === '') {
            return null;
        }

        return [
            'number'    => $number,
            'extension' => self::string($value['extension'] ?? null) ?? '',
            'uri'       => self::string($value['uri'] ?? null) ?? '',
        ];
    }

    /**
     * The non-empty strings in a list, re-indexed. Numbers are cast.
     *
     * @param array<mixed> $values Raw list.
     *
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        $strings = array_map(self::string(...), $values);

        return array_values(array_filter($strings, static fn (?string $value): bool => $value !== null && $value !== ''));
    }

    /**
     * A string, or a number cast to one.
     *
     * @param mixed $value Raw value.
     *
     * @return string|null Null for other types.
     */
    private static function string(mixed $value): ?string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : null;
    }
}
