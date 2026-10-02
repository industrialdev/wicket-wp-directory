<?php

declare(strict_types=1);

namespace Wicket\Directory\Data;

use InvalidArgumentException;
use Wicket\Directory\Config\DirectoryConfig;

/**
 * Picks which contact records (address, email, phone, website) a card shows.
 *
 * One implementation of the prototype's truth table, used for all four record
 * types. Don't pick contact records ad hoc anywhere else.
 *
 * 1. Keep records of the rule's type (`any` keeps all).
 * 2. Neither `only_directory` nor `only_primary` set: the first record flagged
 *    show-in-directory, else the first primary record, else nothing.
 * 3. Either flag set: every record matching the set flags (AND when both are
 *    set). No fallback.
 * 4. An empty result hides the row on the card.
 *
 * Show-in-directory is the `consent_directory` attribute, not `consent`.
 * Web addresses have no `primary` attribute, so for websites step 2 falls back
 * to the first record of the matching type instead, and `only_primary` is
 * ignored. See api-queries.md, "Contact records".
 *
 * @phpstan-import-type ContactRule from DirectoryConfig
 */
final class ContactResolver
{
    /**
     * Card contact field => the person/organization relationship that holds its records.
     */
    public const RELATIONSHIPS = [
        'address' => 'addresses',
        'email'   => 'emails',
        'phone'   => 'phones',
        'website' => 'web_addresses',
    ];

    /**
     * Resolve a card contact field's records.
     *
     * @param string            $field   One of DirectoryConfig::CONTACT_FIELDS.
     * @param array<int, mixed> $records The entity's records for that field, as JSON:API
     *                                   resources in relationship order. Non-array entries
     *                                   (e.g. the nulls getIncludedRelationship() returns for
     *                                   records missing from `included`) are skipped.
     * @param ContactRule       $rule    The field's rule from the card config.
     *
     * @return list<array<string, mixed>> The records to show, in order. At most one when neither
     *                                    flag is set; empty when the rule is off or nothing matches.
     *
     * @throws InvalidArgumentException When $field isn't a contact field.
     */
    public static function resolve(string $field, array $records, array $rule): array
    {
        if (!isset(self::RELATIONSHIPS[$field])) {
            throw new InvalidArgumentException(sprintf('Unknown contact field "%s".', $field));
        }

        if (($rule['show'] ?? false) !== true) {
            return [];
        }

        $has_primary = $field !== 'website';
        $type = $rule['type'] ?? DirectoryConfig::CONTACT_ANY;
        $only_directory = ($rule['only_directory'] ?? false) === true;
        $only_primary = $has_primary && ($rule['only_primary'] ?? false) === true;

        $records = array_values(array_filter(
            $records,
            static fn (mixed $record): bool => is_array($record)
                && ($type === DirectoryConfig::CONTACT_ANY || self::attribute($record, 'type') === $type),
        ));

        if ($only_directory || $only_primary) {
            return array_values(array_filter(
                $records,
                static fn (array $record): bool => (!$only_directory || self::flag($record, 'consent_directory'))
                    && (!$only_primary || self::flag($record, 'primary')),
            ));
        }

        foreach ($records as $record) {
            if (self::flag($record, 'consent_directory')) {
                return [$record];
            }
        }

        if (!$has_primary) {
            return array_slice($records, 0, 1);
        }

        foreach ($records as $record) {
            if (self::flag($record, 'primary')) {
                return [$record];
            }
        }

        return [];
    }

    /**
     * Whether a record's boolean attribute is set. Only a real `true` counts.
     *
     * @param array<string, mixed> $record JSON:API resource.
     * @param string               $name   Attribute name.
     *
     * @return bool
     */
    private static function flag(array $record, string $name): bool
    {
        return self::attribute($record, $name) === true;
    }

    /**
     * A record's attribute, or null.
     *
     * @param array<string, mixed> $record JSON:API resource.
     * @param string               $name   Attribute name.
     *
     * @return mixed
     */
    private static function attribute(array $record, string $name): mixed
    {
        return is_array($record['attributes'] ?? null) ? ($record['attributes'][$name] ?? null) : null;
    }
}
