<?php

declare(strict_types=1);

namespace Wicket\Directory\Api;

use RuntimeException;
use Wicket\Directory\Config\DirectoryConfig;

/**
 * Who each organization on a page lists under Organization Contact: batch
 * requests per page, never one per organization (api-queries.md, "Membership
 * owner" and "Relationship Types contacts on the org card").
 *
 * - Membership Owner: the owners of the organizations' active memberships,
 *   from the `organization_memberships/query` response the caller already
 *   made with `include=owner`. With relationship types chosen, one
 *   `connections/query` keeps only the owners with an active relationship of
 *   those types to that organization.
 * - Relationship Types: one `connections/query` (`include=person`) for every
 *   active relationship of the chosen types (any type when none is chosen).
 * - Either way, one `people/query` (`uuid_in`, `include=emails,phones`) when
 *   the section shows an email or a phone: nested includes don't work.
 *
 * It only collects references and raw resources; EntryMapper turns them into
 * the card's contacts, through ContactResolver.
 *
 * @phpstan-import-type OrgContact from DirectoryConfig
 * @phpstan-import-type OrgContactRef from ResultPage
 */
final class OrganizationContacts
{
    /**
     * Page size of each batch: the MDP's cap. A page of organizations with
     * more rows than this loses the rows past it.
     */
    public const PAGE_SIZE = 2000;

    /**
     * The most people one card lists. "Any type" on a large organization
     * could otherwise list its whole staff.
     */
    public const MAX_PER_ORG = 10;

    /**
     * @param object $client API client (wicket_api_client()), with post().
     */
    public function __construct(private readonly object $client) {}

    /**
     * Load the contacts of a page of organizations.
     *
     * @param list<string>              $orgs        The page's organization UUIDs.
     * @param OrgContact                $settings    The card's Organization Contact settings.
     * @param array<string, mixed>|null $memberships The page's `organization_memberships/query`
     *                                               response with `include=owner`. Needed for
     *                                               the Membership Owner source.
     * @param list<string>              $tiers       The directory's tier UUIDs (lower case); empty
     *                                               for any. Only those memberships' owners count.
     *
     * @throws RuntimeException When a request fails or its response isn't a JSON:API list.
     *
     * @return array{contacts: array<string, list<OrgContactRef>>, people: list<array<string, mixed>>, included: list<array<string, mixed>>}
     */
    public function load(array $orgs, array $settings, ?array $memberships, array $tiers = []): array
    {
        $orgs = array_values(array_unique(array_filter($orgs, static fn (mixed $id): bool => is_string($id) && $id !== '')));
        $types = $settings['relationship_types'];

        if ($orgs === []) {
            return ['contacts' => [], 'people' => [], 'included' => []];
        }

        if ($settings['source'] === DirectoryConfig::ORG_CONTACT_RELATIONSHIP) {
            $response = $this->connections($orgs, [], $types, true);
            $contacts = self::connection_contacts($response, $orgs, $types);
            $people = self::people(is_array($response['included'] ?? null) ? $response['included'] : []);
        } else {
            $memberships ??= [];
            $contacts = self::owners($memberships, $orgs, $tiers);
            $people = self::people(is_array($memberships['included'] ?? null) ? $memberships['included'] : []);

            if ($types !== [] && $contacts !== []) {
                $owners = array_values(array_unique(array_merge(...array_values(array_map(
                    static fn (array $refs): array => array_column($refs, 'person'),
                    $contacts,
                )))));
                $relationships = self::connection_contacts($this->connections(array_keys($contacts), $owners, $types, false), $orgs, $types);
                $contacts = self::with_relationships($contacts, $relationships);
            }
        }

        $contacts = array_map(
            static fn (array $refs): array => array_slice(self::sorted($refs, $people, $types), 0, self::MAX_PER_ORG),
            $contacts,
        );

        $wanted = [];

        foreach ($contacts as $refs) {
            foreach ($refs as $ref) {
                $wanted[$ref['person']] = true;
            }
        }

        $people = array_intersect_key($people, $wanted);
        $included = [];

        if ($wanted !== [] && ($settings['email']['show'] || $settings['phone']['show'])) {
            [$people, $included] = $this->contact_records(array_keys($wanted));
        }

        return [
            'contacts' => $contacts,
            'people'   => array_values($people),
            'included' => $included,
        ];
    }

    /**
     * Every distinct owner of each organization's active memberships.
     *
     * When the directory is limited to tiers, only the owners of memberships
     * in those tiers count, as on the tier line. Otherwise every owner does.
     * Newest membership first (`starts_at`).
     *
     * @param array<string, mixed> $response `organization_memberships/query` with `include=owner`.
     * @param list<string>         $orgs     The page's organization UUIDs.
     * @param list<string>         $tiers    Tier UUIDs (lower case); empty for any.
     *
     * @return array<string, list<OrgContactRef>> Keyed by organization UUID, with no types.
     */
    public static function owners(array $response, array $orgs, array $tiers): array
    {
        $rows = array_values(array_filter(is_array($response['data'] ?? null) ? $response['data'] : [], 'is_array'));

        usort($rows, static fn (array $a, array $b): int => strcmp(
            self::text($b['attributes']['starts_at'] ?? null),
            self::text($a['attributes']['starts_at'] ?? null),
        ));

        $wanted = array_flip($orgs);
        $allowed = array_flip($tiers);
        $owners = [];

        foreach ($rows as $row) {
            $org = $row['relationships']['organization']['data']['id'] ?? null;
            $owner = $row['relationships']['owner']['data']['id'] ?? null;
            $tier = $row['relationships']['membership']['data']['id'] ?? null;

            if (!is_string($org) || !isset($wanted[$org]) || !is_string($owner) || $owner === '') {
                continue;
            }

            if ($allowed !== [] && (!is_string($tier) || !isset($allowed[strtolower($tier)]))) {
                continue;
            }

            $owners[$org][$owner] = ['person' => $owner, 'types' => []];
        }

        return array_map('array_values', $owners);
    }

    /**
     * One `connections/query` for active person ⟷ organization relationships.
     *
     * @param list<string> $orgs           Organization UUIDs.
     * @param list<string> $people         Person UUIDs; empty for anyone.
     * @param list<string> $types          Relationship type slugs; empty for any.
     * @param bool         $include_people Include each connection's person (name and job title).
     *
     * @throws RuntimeException When the response isn't a JSON:API list.
     *
     * @return array<string, mixed>
     */
    private function connections(array $orgs, array $people, array $types, bool $include_people): array
    {
        $filter = [
            'connection_type_eq'   => 'person_to_organization',
            'organization_uuid_in' => array_values($orgs),
            'active_eq'            => true,
        ];

        if ($people !== []) {
            $filter['person_uuid_in'] = $people;
        }

        if ($types !== []) {
            $filter['resource_type_slug_in'] = $types;
        }

        $query = ['page' => ['size' => self::PAGE_SIZE]];

        if ($include_people) {
            $query['include'] = 'person';
        }

        return $this->post('connections/query?' . http_build_query($query), $filter);
    }

    /**
     * The people's emails and phones: one `people/query`.
     *
     * @param list<string> $ids Person UUIDs.
     *
     * @throws RuntimeException When the response isn't a JSON:API list.
     *
     * @return array{array<string, array<string, mixed>>, list<array<string, mixed>>} The people keyed by
     *                                                                                UUID, and their records.
     */
    private function contact_records(array $ids): array
    {
        $query = http_build_query(['page' => ['size' => self::PAGE_SIZE], 'include' => 'emails,phones']);
        $response = $this->post('people/query?' . $query, ['uuid_in' => $ids]);

        return [
            self::people($response['data']),
            array_values(array_filter(is_array($response['included'] ?? null) ? $response['included'] : [], 'is_array')),
        ];
    }

    /**
     * Send a query.
     *
     * @param string               $path   Endpoint with its query string.
     * @param array<string, mixed> $filter Ransack filter.
     *
     * @throws RuntimeException When the response isn't a JSON:API list.
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $filter): array
    {
        $response = $this->client->post($path, ['json' => ['filter' => $filter]]);

        if (!is_array($response) || !is_array($response['data'] ?? null)) {
            throw new RuntimeException('Unexpected response from ' . strtok($path, '?') . '.');
        }

        return $response;
    }

    /**
     * The people with a qualifying relationship to each organization.
     *
     * A person with several (e.g. Employee and Primary Contact) is listed
     * once, with every type.
     *
     * @param array<string, mixed> $response `connections/query` response.
     * @param list<string>         $orgs     The page's organization UUIDs.
     * @param list<string>         $types    The chosen types; empty for any. Rows of other types are
     *                                       ignored, in case the filter wasn't applied.
     *
     * @return array<string, list<OrgContactRef>> Keyed by organization UUID.
     */
    private static function connection_contacts(array $response, array $orgs, array $types): array
    {
        $wanted = array_flip($orgs);
        $allowed = array_flip($types);
        $contacts = [];

        foreach (is_array($response['data'] ?? null) ? $response['data'] : [] as $row) {
            if (!is_array($row) || ($row['attributes']['active'] ?? true) === false) {
                continue;
            }

            $org = $row['relationships']['organization']['data']['id'] ?? null;
            $person = $row['relationships']['person']['data']['id'] ?? null;
            $type = self::text($row['attributes']['type'] ?? null);

            if (!is_string($org) || !isset($wanted[$org]) || !is_string($person) || $person === '') {
                continue;
            }

            if ($allowed !== [] && !isset($allowed[$type])) {
                continue;
            }

            $contacts[$org][$person] ??= ['person' => $person, 'types' => []];

            if ($type !== '' && !in_array($type, $contacts[$org][$person]['types'], true)) {
                $contacts[$org][$person]['types'][] = $type;
            }
        }

        return array_map('array_values', $contacts);
    }

    /**
     * Keep the owners who have a qualifying relationship, with its types.
     *
     * @param array<string, list<OrgContactRef>> $owners        Owners per organization.
     * @param array<string, list<OrgContactRef>> $relationships Qualifying people per organization.
     *
     * @return array<string, list<OrgContactRef>> Organizations left with nobody are dropped.
     */
    private static function with_relationships(array $owners, array $relationships): array
    {
        $kept = [];

        foreach ($owners as $org => $refs) {
            $types = array_column($relationships[$org] ?? [], 'types', 'person');

            foreach ($refs as $ref) {
                if (($types[$ref['person']] ?? []) !== []) {
                    $kept[$org][] = ['person' => $ref['person'], 'types' => $types[$ref['person']]];
                }
            }
        }

        return $kept;
    }

    /**
     * One organization's contacts in display order.
     *
     * By the first of their types in the admin's order (owners keep their
     * membership order when no type is chosen), then by family and given
     * name. The sort is stable, so ties keep the API's order.
     *
     * @param list<OrgContactRef>                 $refs   Contacts.
     * @param array<string, array<string, mixed>> $people `people` resources by UUID.
     * @param list<string>                        $types  The chosen types, in the admin's order.
     *
     * @return list<OrgContactRef>
     */
    private static function sorted(array $refs, array $people, array $types): array
    {
        $rank = array_flip($types);
        $key = static function (array $ref) use ($people, $rank): array {
            $ranks = array_map(static fn (string $type): int => $rank[$type] ?? PHP_INT_MAX, $ref['types']);
            $attributes = $people[$ref['person']]['attributes'] ?? [];

            return [
                $ranks === [] ? PHP_INT_MAX : min($ranks),
                strtolower(self::text($attributes['family_name'] ?? null)),
                strtolower(self::text($attributes['given_name'] ?? null) ?: self::text($attributes['full_name'] ?? null)),
            ];
        };

        // An owner list without types keeps the membership order.
        if ($types === [] && array_merge(...array_column($refs, 'types')) === []) {
            return $refs;
        }

        usort($refs, static fn (array $a, array $b): int => $key($a) <=> $key($b));

        return $refs;
    }

    /**
     * The `people` resources in a list, keyed by UUID.
     *
     * @param array<mixed> $records Resources, e.g. a response's `included`.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function people(array $records): array
    {
        $people = [];

        foreach ($records as $record) {
            if (is_array($record) && ($record['type'] ?? null) === 'people' && is_string($record['id'] ?? null)) {
                $people[$record['id']] ??= $record;
            }
        }

        return $people;
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
