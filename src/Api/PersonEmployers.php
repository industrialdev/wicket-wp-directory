<?php

declare(strict_types=1);

namespace Wicket\Directory\Api;

use RuntimeException;
use Wicket\Directory\Config\DirectoryConfig;

/**
 * Each person's Employer on a page of person cards: one batch request per
 * page, never one per person (api-queries.md, "Employer on the individual
 * card (P2.6)").
 *
 * Employer is the organizations a person has an active person ⟷ organization
 * relationship with, of the chosen types (any type when none is chosen). One
 * `connections/query` with `person_uuid_in` and `include=organization` gives
 * both the relationships and the organizations' names.
 *
 * The person's `primary_organization` isn't used: it's null for every person
 * on staging, so it can't be verified (api-queries.md, Phase 2 corrections,
 * item 7).
 *
 * It only collects references and raw resources; EntryMapper turns them into
 * the card's employers.
 *
 * @phpstan-import-type EmployerSettings from DirectoryConfig
 * @phpstan-import-type EmployerRef from ResultPage
 */
final class PersonEmployers
{
    /**
     * Page size of the batch: the MDP's cap. A page of people with more
     * relationships than this loses the rows past it.
     */
    public const PAGE_SIZE = 2000;

    /**
     * The most organizations one card lists. A person with many active
     * relationships (one staging person has 17 connections) would otherwise
     * fill the card.
     */
    public const MAX_PER_PERSON = 5;

    /**
     * @param object $client API client (wicket_api_client()), with post().
     */
    public function __construct(private readonly object $client) {}

    /**
     * Load the employers of a page of people.
     *
     * A person's organizations come in the order of their first matching type
     * in the admin's order (when types are chosen), then by name in the
     * current language; an organization with several matching relationships
     * is listed once, with every type. At most MAX_PER_PERSON per person.
     *
     * @param list<string>     $people   The page's person UUIDs.
     * @param EmployerSettings $settings The card's Employer settings.
     * @param string           $lang     Two-letter language code, for the name order.
     *
     * @throws RuntimeException When the request fails or its response isn't a JSON:API list.
     *
     * @return array{employers: array<string, list<EmployerRef>>, organizations: list<array<string, mixed>>}
     *         Employers keyed by person UUID (people with none are left out), and the
     *         `organizations` resources they name.
     */
    public function load(array $people, array $settings, string $lang): array
    {
        $people = array_values(array_unique(array_filter($people, static fn (mixed $id): bool => is_string($id) && $id !== '')));

        if ($people === []) {
            return ['employers' => [], 'organizations' => []];
        }

        $types = $settings['relationship_types'];
        $filter = [
            'connection_type_eq' => 'person_to_organization',
            'person_uuid_in'     => $people,
            'active_eq'          => true,
        ];

        if ($types !== []) {
            $filter['resource_type_slug_in'] = $types;
        }

        $path = 'connections/query?' . http_build_query(['page' => ['size' => self::PAGE_SIZE], 'include' => 'organization']);
        $response = $this->client->post($path, ['json' => ['filter' => $filter]]);

        if (!is_array($response) || !is_array($response['data'] ?? null)) {
            throw new RuntimeException('Unexpected response from connections/query.');
        }

        $organizations = self::organizations(is_array($response['included'] ?? null) ? $response['included'] : []);
        $employers = [];

        foreach (self::employers($response['data'], $people, $types) as $person => $refs) {
            // An organization the response didn't include has no name to show.
            $refs = array_values(array_filter($refs, static fn (array $ref): bool => isset($organizations[$ref['organization']])));

            if ($refs !== []) {
                $employers[$person] = array_slice(self::sorted($refs, $organizations, $types, $lang), 0, self::MAX_PER_PERSON);
            }
        }

        $wanted = [];

        foreach ($employers as $refs) {
            foreach ($refs as $ref) {
                $wanted[$ref['organization']] = true;
            }
        }

        return [
            'employers'     => $employers,
            'organizations' => array_values(array_intersect_key($organizations, $wanted)),
        ];
    }

    /**
     * The organizations each person has a qualifying relationship with.
     *
     * @param array<mixed> $rows   `connections` rows.
     * @param list<string> $people The page's person UUIDs.
     * @param list<string> $types  The chosen types; empty for any. Rows of other types, and
     *                             inactive rows, are ignored, in case the filter wasn't applied.
     *
     * @return array<string, list<EmployerRef>> Keyed by person UUID.
     */
    private static function employers(array $rows, array $people, array $types): array
    {
        $wanted = array_flip($people);
        $allowed = array_flip($types);
        $employers = [];

        foreach ($rows as $row) {
            if (!is_array($row) || ($row['attributes']['active'] ?? true) === false) {
                continue;
            }

            $person = $row['relationships']['person']['data']['id'] ?? null;
            $org = $row['relationships']['organization']['data']['id'] ?? null;
            $type = self::text($row['attributes']['type'] ?? null);

            if (!is_string($person) || !isset($wanted[$person]) || !is_string($org) || $org === '') {
                continue;
            }

            if ($allowed !== [] && !isset($allowed[$type])) {
                continue;
            }

            $employers[$person][$org] ??= ['organization' => $org, 'types' => []];

            if ($type !== '' && !in_array($type, $employers[$person][$org]['types'], true)) {
                $employers[$person][$org]['types'][] = $type;
            }
        }

        return array_map('array_values', $employers);
    }

    /**
     * One person's organizations in display order.
     *
     * By the first of their types in the admin's order, then by name. The
     * sort is stable, so ties keep the API's order.
     *
     * @param list<EmployerRef>                   $refs          Employers.
     * @param array<string, array<string, mixed>> $organizations `organizations` resources by UUID.
     * @param list<string>                        $types         The chosen types, in the admin's order.
     * @param string                              $lang          Language code.
     *
     * @return list<EmployerRef>
     */
    private static function sorted(array $refs, array $organizations, array $types, string $lang): array
    {
        $rank = array_flip($types);
        $key = static function (array $ref) use ($organizations, $rank, $lang): array {
            $ranks = array_map(static fn (string $type): int => $rank[$type] ?? PHP_INT_MAX, $ref['types']);

            return [
                $ranks === [] ? PHP_INT_MAX : min($ranks),
                strtolower(self::name($organizations[$ref['organization']], $lang)),
            ];
        };

        usort($refs, static fn (array $a, array $b): int => $key($a) <=> $key($b));

        return $refs;
    }

    /**
     * The `organizations` resources in a list, keyed by UUID.
     *
     * @param array<mixed> $records A response's `included`.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function organizations(array $records): array
    {
        $organizations = [];

        foreach ($records as $record) {
            if (is_array($record) && ($record['type'] ?? null) === 'organizations' && is_string($record['id'] ?? null)) {
                $organizations[$record['id']] ??= $record;
            }
        }

        return $organizations;
    }

    /**
     * An organization's legal name in a language, for sorting.
     *
     * The same fallback as the card: `legal_name_{lang}`, `legal_name`, `legal_name_en`.
     *
     * @param array<string, mixed> $organization `organizations` resource.
     * @param string               $lang         Language code.
     *
     * @return string
     */
    private static function name(array $organization, string $lang): string
    {
        $attributes = is_array($organization['attributes'] ?? null) ? $organization['attributes'] : [];

        foreach (['legal_name_' . $lang, 'legal_name', 'legal_name_en'] as $key) {
            $name = self::text($attributes[$key] ?? null);

            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }

    /**
     * A trimmed string attribute.
     *
     * @param mixed $value Raw value.
     *
     * @return string '' for anything that isn't a string.
     */
    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
