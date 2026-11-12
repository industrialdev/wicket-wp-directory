<?php

declare(strict_types=1);

namespace Wicket\Directory\Api;

/**
 * One page of directory results, as DirectoryRepository returns it.
 *
 * Holds the raw API resources: EntryMapper turns them into card DTOs. The
 * same shape is what the transient cache stores (see to_array()).
 *
 * An empty page is either a real "no results" or, when `unavailable` is set,
 * an API failure. Templates show the friendly "unavailable" alert for the
 * latter and the no-results message for the former.
 *
 * @phpstan-type Tier array{id: string, slug: string, name: string}
 * @phpstan-type Membership array{role: string, active: bool|null, start_date: string, end_date: string|null}
 * @phpstan-type OrgContactRef array{person: string, types: list<string>}
 */
final class ResultPage
{
    /**
     * @param list<array<string, mixed>>  $items       Resources from the response's `data`, in order.
     * @param list<array<string, mixed>>  $included    Records from the response's `included` (emails,
     *                                                 phones, addresses, web addresses).
     * @param int                         $total       Matching records across all pages.
     * @param int                         $page        Current page number, from 1. Clamped to
     *                                                 total_pages when there are results.
     * @param int                         $total_pages Number of pages; 0 when there are no results.
     * @param int                         $per_page    Page size.
     * @param array<string, list<Tier>>   $tiers         Active membership tiers keyed by entity UUID.
     *                                                   Only filled when the card's tier toggle is on.
     * @param bool                        $unavailable   The API failed; the page is empty for that reason.
     * @param array<string, int>          $member_counts Distinct active members keyed by group UUID, for
     *                                                   every group on the page. Only filled for group
     *                                                   directories whose card shows the count, and only
     *                                                   when the count is complete; EntryMapper falls
     *                                                   back to the group's `active_member_count`.
     * @param array<string, Membership>   $group_members One `group_members` row per person UUID, for a
     *                                                   group's member list whose card shows a role,
     *                                                   status or date. People without a row are left out.
     * @param array<string, list<OrgContactRef>> $org_contacts The people each organization's card lists
     *                                                   under Organization Contact, keyed by organization
     *                                                   UUID, in display order, with the relationship
     *                                                   types that qualified them. Only filled when the
     *                                                   card shows the section; organizations with nobody
     *                                                   are left out.
     * @param list<array<string, mixed>>  $contact_people   The `people` resources of $org_contacts.
     * @param list<array<string, mixed>>  $contact_included Their emails and phones, when the section shows
     *                                                      either.
     */
    public function __construct(
        public readonly array $items,
        public readonly array $included,
        public readonly int $total,
        public readonly int $page,
        public readonly int $total_pages,
        public readonly int $per_page,
        public readonly array $tiers = [],
        public readonly bool $unavailable = false,
        public readonly array $member_counts = [],
        public readonly array $group_members = [],
        public readonly array $org_contacts = [],
        public readonly array $contact_people = [],
        public readonly array $contact_included = [],
    ) {}

    /**
     * A page with no results.
     *
     * @param int  $per_page    Page size.
     * @param bool $unavailable Whether it's empty because the API failed.
     *
     * @return self
     */
    public static function empty(int $per_page, bool $unavailable = false): self
    {
        return new self([], [], 0, 1, 0, max(1, $per_page), [], $unavailable);
    }

    /**
     * Build a page from a `{people|organizations}/query` response.
     *
     * @param array<string, mixed>      $response  Decoded response: `data`, `included`, `meta.page`.
     * @param int                       $page      The page number requested; used when `meta` lacks it.
     * @param int                       $per_page  The page size requested; used when `meta` lacks it.
     * @param array<string, list<Tier>> $tiers     Tier labels for the page's entities.
     *
     * @return self
     */
    public static function from_response(array $response, int $page, int $per_page, array $tiers = []): self
    {
        $meta = is_array($response['meta']['page'] ?? null) ? $response['meta']['page'] : [];
        $total = self::int($meta['total_items'] ?? null, 0);
        $total_pages = self::int($meta['total_pages'] ?? null, 0);
        $number = self::int($meta['number'] ?? null, $page);

        return new self(
            self::records($response['data'] ?? null),
            self::records($response['included'] ?? null),
            $total,
            $total_pages > 0 ? max(1, min($number, $total_pages)) : 1,
            $total_pages,
            max(1, self::int($meta['size'] ?? null, $per_page)),
            $tiers,
        );
    }

    /**
     * Rebuild a page from its cached form.
     *
     * @param mixed $data A value from to_array(), e.g. read back from a transient.
     *
     * @return self|null Null when the value isn't a cached page.
     */
    public static function from_array(mixed $data): ?self
    {
        if (!is_array($data) || !is_array($data['items'] ?? null) || !is_array($data['included'] ?? null) || !is_array($data['tiers'] ?? null)) {
            return null;
        }

        foreach (['total', 'page', 'total_pages', 'per_page'] as $key) {
            if (!is_int($data[$key] ?? null)) {
                return null;
            }
        }

        return new self(
            self::records($data['items']),
            self::records($data['included']),
            $data['total'],
            $data['page'],
            $data['total_pages'],
            $data['per_page'],
            $data['tiers'],
            false,
            // Pages cached before groups existed have no counts, nor member rows.
            is_array($data['member_counts'] ?? null) ? array_filter($data['member_counts'], 'is_int') : [],
            is_array($data['group_members'] ?? null) ? array_filter($data['group_members'], 'is_array') : [],
            // Nor do pages cached before Organization Contact.
            is_array($data['org_contacts'] ?? null) ? array_filter($data['org_contacts'], 'is_array') : [],
            self::records($data['contact_people'] ?? null),
            self::records($data['contact_included'] ?? null),
        );
    }

    /**
     * The page in the form the cache stores.
     *
     * Unavailable pages are never cached, so the flag isn't part of it.
     *
     * @return array{items: list<array<string, mixed>>, included: list<array<string, mixed>>, total: int, page: int, total_pages: int, per_page: int, tiers: array<string, list<Tier>>, member_counts: array<string, int>, group_members: array<string, Membership>, org_contacts: array<string, list<OrgContactRef>>, contact_people: list<array<string, mixed>>, contact_included: list<array<string, mixed>>}
     */
    public function to_array(): array
    {
        return [
            'items'            => $this->items,
            'included'         => $this->included,
            'total'            => $this->total,
            'page'             => $this->page,
            'total_pages'      => $this->total_pages,
            'per_page'         => $this->per_page,
            'tiers'            => $this->tiers,
            'member_counts'    => $this->member_counts,
            'group_members'    => $this->group_members,
            'org_contacts'     => $this->org_contacts,
            'contact_people'   => $this->contact_people,
            'contact_included' => $this->contact_included,
        ];
    }

    /**
     * Whether the page has no items.
     *
     * @return bool
     */
    public function is_empty(): bool
    {
        return $this->items === [];
    }

    /**
     * The 1-based position of the first item, for "Displaying X–Y of N".
     *
     * @return int 0 when the page is empty.
     */
    public function first_number(): int
    {
        return $this->is_empty() ? 0 : ($this->page - 1) * $this->per_page + 1;
    }

    /**
     * The 1-based position of the last item, for "Displaying X–Y of N".
     *
     * @return int 0 when the page is empty.
     */
    public function last_number(): int
    {
        return $this->is_empty() ? 0 : $this->first_number() + count($this->items) - 1;
    }

    /**
     * Active membership tiers for one entity.
     *
     * @param string $id Person or organization UUID.
     *
     * @return list<Tier>
     */
    public function tiers_for(string $id): array
    {
        return $this->tiers[$id] ?? [];
    }

    /**
     * A copy with other tiers.
     *
     * @param array<string, list<Tier>> $tiers Tiers keyed by entity UUID.
     *
     * @return self
     */
    public function with_tiers(array $tiers): self
    {
        return $this->copy(['tiers' => $tiers]);
    }

    /**
     * A copy with other member counts.
     *
     * @param array<string, int> $member_counts Distinct active members keyed by group UUID.
     *
     * @return self
     */
    public function with_member_counts(array $member_counts): self
    {
        return $this->copy(['member_counts' => $member_counts]);
    }

    /**
     * A copy with other group member rows.
     *
     * @param array<string, Membership> $group_members One row per person UUID.
     *
     * @return self
     */
    public function with_group_members(array $group_members): self
    {
        return $this->copy(['group_members' => $group_members]);
    }

    /**
     * A copy with other Organization Contacts.
     *
     * @param array<string, list<OrgContactRef>> $org_contacts The people per organization UUID.
     * @param list<array<string, mixed>>         $people       Their `people` resources.
     * @param list<array<string, mixed>>         $included     Their emails and phones.
     *
     * @return self
     */
    public function with_org_contacts(array $org_contacts, array $people, array $included = []): self
    {
        return $this->copy([
            'org_contacts'     => $org_contacts,
            'contact_people'   => self::records($people),
            'contact_included' => self::records($included),
        ]);
    }

    /**
     * The people one organization's card lists under Organization Contact.
     *
     * @param string $id Organization UUID.
     *
     * @return list<OrgContactRef>
     */
    public function org_contacts_for(string $id): array
    {
        return $this->org_contacts[$id] ?? [];
    }

    /**
     * One person's row in the group whose member list this page is.
     *
     * @param string $id Person UUID.
     *
     * @return Membership|null Null when the page has no row for the person.
     */
    public function group_member_for(string $id): ?array
    {
        return $this->group_members[$id] ?? null;
    }

    /**
     * Distinct active members of one group, if the page counted them.
     *
     * @param string $id Group UUID.
     *
     * @return int|null Null when the page has no count for the group.
     */
    public function member_count_for(string $id): ?int
    {
        return $this->member_counts[$id] ?? null;
    }

    /**
     * A copy with some properties changed.
     *
     * @param array<string, mixed> $changes Constructor arguments, keyed by name.
     *
     * @return self
     */
    private function copy(array $changes): self
    {
        return new self(...$changes + get_object_vars($this));
    }

    /**
     * The array records in a list, re-indexed.
     *
     * @param mixed $value Raw list.
     *
     * @return list<array<string, mixed>>
     */
    private static function records(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * A non-negative integer from a decoded JSON value.
     *
     * @param mixed $value   Raw value.
     * @param int   $default Used when the value isn't numeric.
     *
     * @return int
     */
    private static function int(mixed $value, int $default): int
    {
        return is_numeric($value) ? max(0, (int) $value) : $default;
    }
}
