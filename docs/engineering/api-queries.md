---
title: "MDP API Queries"
audience: [developer, agent]
updated: 2026-11-12
---

# Verified MDP queries for directories

Outcome of the API spikes: ticket 0.1 (people and organizations) and ticket P2.0 (groups, group members, connections and the membership owner, from [Groups directory](#groups-directory-groupsquery) on). Every filter, field and sort key below was run against a staging MDP (`demo-woocommerce-api.staging.wicketcloud.com`) through `wicket_api_client()` in `wp eval-file`. On 2026-10-02 that tenant had 161 people and 19,933 organizations; on 2026-11-10 it had 175 people, 8 groups, 125 group member rows and 40 connections. The query builders (tickets 2.2, 2.3, P2.1, P2.3) and `ContactResolver` (2.5) must use exactly these names.

## How to read "verified"

**The MDP silently ignores unknown Ransack predicates and unknown sort keys.** A typo doesn't return an error. It returns the unfiltered result set. For example, `foo_bar_eq: 'x'` on `people/query` returns all 161 people.

So a predicate counts as verified only when both of these hold:

1. a value that can't match returns **0**;
2. a real value returns a plausible subset.

A predicate that returns the full total for a no-match value is **ignored**, and is listed under [Rejected](#rejected-predicates-silently-ignored).

> ⚠️ This matters most for eligibility. If an active-membership or opt-in predicate is misspelled, the directory lists **every** person or organization in the tenant. The unit tests for the query builders (ticket 6.2) must assert the exact keys in this doc.

## Request shape

```php
$query = [
    'page'    => ['size' => 6, 'number' => 1],
    'sort'    => 'family_name',
    'include' => 'emails,phones,addresses,web_addresses',
];
$qs = preg_replace('/\%5B\d+\%5D/', '%5B%5D', http_build_query($query));

$response = wicket_api_client()->post("people/query?{$qs}", ['json' => ['filter' => $filter]]);
```

- `filter` goes in the JSON body. `page`, `sort` and `include` go in the query string, as in every existing caller. `page` and `include` also work in the JSON body, but keep them in the query string for consistency.
- An empty filter must be sent as an object (`(object) []`), not `[]`.
- The SDK passes `['json' => …]` straight to Guzzle. HTTP 4xx and 5xx responses **throw**, so catch `\Throwable`. On success the return value is the decoded JSON array.
- Response: `data[]`, `included[]`, and `meta.page` = `{total_items, total_pages, number, size}`.

## Paging

| Case | Behaviour |
|---|---|
| `page[size]` 1–2000 | Honoured |
| `page[size]` > 2000 | **Silently capped at 2000** (`meta.page.size` = 2000) |
| `page[size]=0` | Error (exception) |
| `page[number]` past the last page | `data: []` with correct `meta` (total_items, total_pages) |

The plugin clamps `resultsPerPage` to 1–50. `RequestParams` should still clamp the page number to `total_pages`, so an out-of-range link renders the last page rather than an empty one.

Joins on has-many associations (emails, addresses, phones, `membership_people`) **don't duplicate rows**. `total_items` equals the number of unique records in every case tested, including people with several emails and people matching several tiers.

## Groups (AND / OR)

- Top-level keys are **ANDed**.
- OR groups go in `g`: `'g' => [['m' => 'or', …], ['m' => 'or', …]]`. Each group is ORed internally, and groups are ANDed with each other and with top-level keys.
- **Don't** put `'m' => 'or'` at the top level. It works, but it ORs the eligibility conditions with the keyword too.
- Ransack attribute combinators also work, e.g. `given_name_or_family_name_or_full_name_cont`. We use `g` groups so that `identifying_number_eq` (an `_eq`, not a `_cont`) can join the same OR.

| Test (people) | Total |
|---|---|
| `given_name_cont: 'a'` | 101 |
| `family_name_cont: 'a'` | 71 |
| both at top level (AND) | 54 |
| `g: [{m: or, given_name_cont: 'a', family_name_cont: 'a'}]` | 118 |
| same group + `membership_people_status_eq: 'Active'` | 27 (active alone: 41) |

## People (`people/query`)

### Eligibility

| Purpose | Predicate | Value | Verified |
|---|---|---|---|
| Active membership | `membership_people_status_eq` | `'Active'` | 41 of 161. A no-match value → 0. `'Inactive'` → 137, `'Delayed'` → 6 |
| Active membership (alt.) | `membership_people_active_eq` | `true` | 41, the same set |
| Membership tier(s) | `membership_people_membership_uuid_in` | `[uuid, …]` | 11 people for one tier. No-match → 0 |
| Membership tier(s) by slug | `membership_people_membership_slug_in` | `[slug, …]` | 11, the same set as the UUID. Used by the old theme template |

**Use `membership_people_status_eq: 'Active'`.** It matches the existing member-directory template, and the same `status` field is what we read back for the tier label (see [Tiers on the card](#tiers-on-the-card)). The `active` boolean on membership records is unreliable (see below).

Tier and status conditions on the same association **apply to the same membership row**. So "active in tier X" is:

```php
'membership_people_status_eq'          => 'Active',
'membership_people_membership_uuid_in' => ['69306476-…'],
```

For that tier: 11 people hold it in any status, and 7 hold it actively.

Store tier **UUIDs** in the config (`membership_ids`), not slugs. Slugs can be renamed in the MDP.

Membership tier options for the admin come from `GET memberships` (use `page[size]=100`). Each record has `attributes.type`: `individual` (28 on staging) or `organization` (17). Offer only the tiers matching the directory type. The records also carry `name_{lang}`, `slug`, and `active`.

Don't use date predicates (`membership_people_starts_at_lteq` / `_ends_at_gteq`). They return 31 instead of 41, because open-ended memberships have `ends_at = null`. 24 of the active memberships on staging have no end date.

### Keyword

```php
'g' => [[
    'm'                     => 'or',
    'given_name_cont'       => $keyword,
    'family_name_cont'      => $keyword,
    'full_name_cont'        => $keyword,
    'identifying_number_eq' => $keyword,
]],
```

All four are verified: no-match → 0, and a real member number → exactly 1. `identifying_number` is the member ID the MDP UI shows (`display_identifying_number` is its formatted display value). `membership_number_eq` is **ignored**, so don't use it.

`_cont` is case-insensitive already (lower- and upper-case values match the same records). `_i_cont` is kept only for location, to match the existing templates.

### Location

```php
'g' => [[
    'm'                           => 'or',
    'addresses_city_i_cont'       => $location,
    'addresses_state_name_i_cont' => $location,
]],
```

Both are verified (no-match → 0). The location group goes in `g` alongside the keyword group, so the two are ANDed.

Location matches **any** of the record's addresses, not only the one `ContactResolver` picks for the card. The CHFA template narrows this with `addresses_primary_eq: true` (verified: 8 people). Leave it out of the MVP unless parity testing (ticket 7.2) shows it's needed.

### Data fields (opt-in and facets)

```php
'search_query' => [
    'data_fields.practice_details.value.breedSpecialties' => ['cats', 'dogs'],
    'data_fields.license_details.value.haveLicense'       => true,
],
```

| Behaviour | Evidence |
|---|---|
| Recognised on people and orgs | no-match → 0 on both |
| Array value = **any of** (OR) | `[v1]` → 4, `[v2]` → 3, `[v1, v2]` → 5, `[v1, no-match]` → 4 |
| Scalar value against an array field = contains | `v1` → 4 |
| Several keys = AND | `[v1]` + a no-match second key → 0 |
| Boolean | `true` → 3, `false` → 1, `'true'` → 3 |
| Empty string = **no condition** | `''` → the full total (the CHFA template relies on this for unset filters) |
| ANDs with Ransack predicates | `search_query` + a no-match `given_name_cont` → 0 |

A multi-select facet sends its selected values as one array (any-of). Different facets go under separate keys (AND). Never send `''` for a facet the visitor hasn't used; omit the key instead.

The opt-in condition (`opt_in: {schema_key, field, value}`) is one more `search_query` key: `data_fields.{schema_key}.value.{field}` ⇒ `value`.

Org data fields are **unverified positively**: no organization on this staging tenant has data fields. The predicate is recognised (no-match → 0), and the CHFA production template uses the same syntax for `orgmemberdir.optin`. Re-check during the CHFA parity check (ticket 7.2).

### Sort

`sort` in the query string, `-` prefix for descending:

| Key | Verified |
|---|---|
| `given_name`, `-given_name` | ✔ distinct orders |
| `family_name`, `-family_name` | ✔ distinct orders |

Unknown keys (`bogus_sort`) are silently ignored and fall back to the default order. `BlockAttributes` / `RequestParams` must whitelist sort keys; don't pass a visitor value through.

### Example: full people payload

Active members of the selected tiers, keyword "a", sorted by last name. Verified: 21 results.

```php
$client->post('people/query?' . $qs, ['json' => ['filter' => [
    'membership_people_status_eq'          => 'Active',
    'membership_people_membership_uuid_in' => $tier_uuids,
    'g' => [
        ['m' => 'or', 'given_name_cont' => $kw, 'family_name_cont' => $kw, 'full_name_cont' => $kw, 'identifying_number_eq' => $kw],
        ['m' => 'or', 'addresses_city_i_cont' => $loc, 'addresses_state_name_i_cont' => $loc], // only when a location is given
    ],
    'search_query' => ['data_fields.orgmemberdir.value.optin' => true],  // only when opt-in / facets are configured
]]]);
// $qs = page[size]=6&page[number]=1&sort=family_name&include=emails,phones,addresses,web_addresses
```

### Person attributes used by cards

These are keys only; values vary per tenant.

`given_name`, `family_name`, `additional_name` (middle name), `full_name`, `honorific_prefix` (salutation), `honorific_suffix`, `suffix`, `job_title`, `identifying_number`, `display_identifying_number`, `membership_status`, `membership_in_grace`, `data_fields`.

Relationships: `emails`, `phones`, `addresses`, `web_addresses`, `primary_organization`, `organizations`, `membership_people`, and others.

## Organizations (`organizations/query`)

### Eligibility

| Purpose | Predicate | Value | Verified |
|---|---|---|---|
| Active membership | `membership_entries_status_eq` | `'Active'` | 10 of 19,933. No-match → 0 |
| Active membership (alt.) | `membership_entries_active_eq` | `true` | 10, the same set. Used by the CHFA template |
| Membership tier(s) | `membership_entries_membership_uuid_in` | `[uuid, …]` | 16 orgs in any status, 10 active. No-match → 0 |
| Org type(s) | `type_in` | `[slug, …]` | `['company']` → 2,846. No-match → 0. `type_eq` also works |

Use `membership_entries_status_eq: 'Active'`, for the same reason as people.

`type` is the org-type **slug** (e.g. `company`). Options come from `wicket_get_org_types_list()`, which returns resource types with `attributes.slug` and `name_{lang}`.

Don't use `membership_status_eq` on orgs. It isn't ignored, but it returns 0 for `'Active'`.

### Keyword

`legal_name_{lang}_cont` (verified for `en` and `fr`). `alternate_name_{lang}_cont` also exists, if keyword search should cover alternate names later. Bare `legal_name_cont` is recognised, but the directory uses the language-specific column.

Take `{lang}` from `wicket_get_current_language()`.

### Location

The same OR group as people: `addresses_city_i_cont` / `addresses_state_name_i_cont`. Both verified. The same "any address" caveat applies.

The existing organization template uses `addresses_state_name_eq` when the country is US or CA. The MVP has one free-text location box, so `_i_cont` is right.

### Sort

| Key | Verified |
|---|---|
| `legal_name_{lang}`, `-legal_name_{lang}` | ✔. `legal_name_en` and `legal_name_fr` give different orders |
| `legal_name` (no language) | ✘ **ignored**, gives the same order as a bogus key |

### Example: full organization payload

```php
$client->post('organizations/query?' . $qs, ['json' => ['filter' => [
    'membership_entries_status_eq'          => 'Active',
    'membership_entries_membership_uuid_in' => $tier_uuids,   // when tiers are configured
    'type_in'                               => $org_type_slugs, // eligibility + org-type facet
    "legal_name_{$lang}_cont"               => $kw,
    'g' => [['m' => 'or', 'addresses_city_i_cont' => $loc, 'addresses_state_name_i_cont' => $loc]],
    'search_query' => ['data_fields.orgmemberdir.value.optin' => true],
]]]);
// $qs = page[size]=6&page[number]=1&sort=legal_name_en&include=emails,phones,addresses,web_addresses
```

If an org-type facet is used as well as `org_types` eligibility, send the **intersection** as one `type_in`. Two `type_in` keys can't coexist in one hash.

### Organization attributes used by cards

These are keys only.

`legal_name_{lang}`, `alternate_name_{lang}`, `description_{lang}`, `type`, `identifying_number`, `display_identifying_number`, `membership_status`, `data_fields`.

Relationships: `emails`, `phones`, `addresses`, `web_addresses`, `membership_entries`, and others.

## Tiers on the card

`include=person_memberships` and `include=membership_people` on `people/query` return **nothing** (no error and no included records). Neither does `include=membership_people` on `GET people/{uuid}`. The tier label therefore needs **one batch request per rendered page**, after the main query:

```php
// People: IDs of the current page
$client->post('person_memberships/query?' . $qs, ['json' => ['filter' => [
    'person_uuid_in' => $page_person_ids,
    'status_eq'      => 'Active',
]]]);
// $qs = page[size]=2000&include=membership

// Organizations
$client->post('organization_memberships/query?' . $qs, ['json' => ['filter' => [
    'organization_uuid_in' => $page_org_ids,
    'status_eq'            => 'Active',
]]]);
```

- Map each row to its entity with `relationships.person.data.id` (or `relationships.organization.data.id`). Map it to its tier with `relationships.membership.data.id`, then look that up in `included[type=memberships]` for `name_{lang}` / `slug` / `type`.
- People often hold several active memberships. On staging, 6 people had 32 active rows, and a person's rows can include `organization`-type tiers cascaded from their org. So:
  - use a large `page[size]` (up to 2000);
  - when the directory filters by tiers, show only the configured tiers;
  - otherwise show the distinct tier names.
- Filter on **`status_eq: 'Active'`**, not `active`. In the `active_at: 'now'` results (110 rows), every row had `status: 'Active'` but **`attributes.active: false`**. The boolean attribute can't be trusted. `active_at: 'now'` gives the same rows as `status_eq: 'Active'` (110 each).
- `person_memberships` attributes: `starts_at`, `ends_at`, `expires_at`, `grace_period_days`, `in_grace`, `status`, `active`, `data`, `membership_category`, … `organization_memberships` adds `is_active_owner`, `max_assignments`, `active_assignments_count`, and others.
- Cache this response together with the page result in `DirectoryRepository`, under the same key and TTL. Make the request only when the card's tier toggle is on.

This is one request per page, not per row, so it keeps to the "no per-row API calls" rule.

## Contact records

All four record types are included with `include=emails,phones,addresses,web_addresses`. Match them to their owner with the relationship `emailable` / `phoneable` / `addressable` / `web_addressable`, or use `\Wicket\ResponseHelper::getIncludedRelationship()`.

| Field | emails | phones | addresses | web_addresses |
|---|---|---|---|---|
| Type | `type` | `type` | `type` | `type` |
| Show in directory | `consent_directory` | `consent_directory` | `consent_directory` | `consent_directory` |
| Primary | `primary` | `primary` | `primary` | **none** |
| Display value | `address` | `number_international_format` (also `number`, `number_national_format`, `extension`) | `address1`, `address2`, `city`, `state_name`, `zip_code`, `country_code`, `country_name`, `formatted_address_label` | `address` (the URL); `data` was null wherever seen |
| Other | `consent`, `consent_third_party`, `unique` | `primary_sms`, `consent`, `consent_third_party` | `active`, `mailing`, `company_name`, `latitude`, `longitude`, `consent`, `consent_third_party` | `consent`, `consent_third_party` |

- **The type field is `type` on all four.** There is no `phone_type` attribute. Values on staging:
  - emails: `work` / `personal` / `other`
  - phones: `work` / `mobile`
  - addresses: `work`
  - web_addresses: `website`

  These are resource-type slugs, so the admin's type options come from `wicket_get_resource_types()`.
- **"Show in directory" is `consent_directory`.** `consent` is the general contact consent, and `consent_third_party` is third-party sharing. Don't use either for the directory.
- **Web addresses have no `primary` flag.** See [Plan corrections](#plan-corrections), item 4.
- On this staging tenant **every** contact record has `consent_directory: false`. So with the default rule, cards fall back to the primary record. Test the flagged branch with unit fixtures, not staging data.

## Data-field items

Each entry in `attributes.data_fields[]` has `$schema` (`urn:uuid:…`), `key` (the schema slug used in `search_query`), `schema_slug`, `version`, `value` (an object keyed by field) and `valid`.

Read a field as the item where `key === $schema_key`, then `value[$field]`. Values can be strings, booleans or arrays of enum keys. Convert enum keys to labels with `wicket_get_schemas_options()`. It misses the schema's own `enumNames` and reads only the site language, so `EnumLabels` fills the gaps (see ticket 2.6's outcome).

### Finding schema keys and fields

Since base plugin 2.7.12, a WP admin (`manage_options`) can open **`/?mdp_schemas`** on any stack site. It lists the tenant's JSON schemas, grouped by resource type. `/?mdp_schemas&schema=<uuid|slug|key>` shows one schema as JSON. Look up the reference parts there:

- the **schema key** (`attributes.key`) is the config's `schema_key`;
- a property under `attributes.schema.properties` is the config's `field`;
- `enum` / `items.enum` holds the enum keys, and `enumNames` / `ui_schema.{field}.ui:i18n.enumNames.{lang}` holds their labels.

It's an admin tool (`WicketWP\SchemaInspector`, all methods private), not an API: plugin code keeps using `wicket_get_schemas()`. The list is cached for 5 minutes; add `&mdp_schemas_refresh=1` after changing a schema in the MDP.

### `json_schemas` isn't paged

Verified 2026-10-02 on staging (11 schemas): `GET json_schemas` ignores `page[size]` and `page[number]`, whether they're sent as Guzzle `query` options or in the query string. It returns no `meta.page` and no `links`, and it gave all 11 schemas even with `page[size]=1`. So the unpaged `wicket_get_schemas()` gets every schema. The inspector's paging loop is harmless but not needed.

## Groups directory (`groups/query`)

Ticket P2.0, verified 2026-11-10. `POST groups/query` takes the same request shape as people and organizations: filter in the JSON body; `page`, `sort` and `include` in the query string. `GET groups` returns the same records. Paging behaves as in [Paging](#paging): `page[size]` caps at 2000, and a page past the end returns `data: []` with correct `meta`.

> ⚠️ **On groups, `type_eq` and `type_in` are silently ignored**, although `type` is an attribute. Filter the group type with `resource_type_slug_in`. The same is true of group members and connections below.

### Eligibility and facets

| Purpose | Predicate | Value | Verified |
|---|---|---|---|
| Group status: Active | `active_eq` | `true` | 8 of 8 (every staging group is active) |
| Group status: Inactive | `active_eq` | `false` | 0. Recognised (an ignored key would return 8), but no inactive group exists to check a positive match |
| Group type(s) | `resource_type_slug_in` | `[slug, …]` | `['board']` → 4, `['committee','working-group']` → 4. No-match → 0. `resource_type_slug_eq` also works |
| Exclude groups | `uuid_not_in` | `[uuid, …]` | two UUIDs → 6 of 8. A no-match UUID → 8 |
| One group (roster) | `uuid_in` / `uuid_eq` | `[uuid]` / `uuid` | 1. No-match → 0 |
| Group's organization | `organization_uuid_eq` | `uuid` | no-match → 0 (not needed by P2.1) |
| Data fields | `search_query` | as for people | Recognised (no-match → 0), but no JSON schema on staging is bound to groups, so there's nothing to facet on |

Group status **All** sends no `active_eq` key. Store excluded groups by UUID, as P2.1 plans; `id_not_in` is ignored (`id` isn't the UUID).

`active_at` is recognised on groups (`'1900-01-01'` → 3, `'2020-01-01'` → 7: it reads `start_date` / `end_date`), but use `active_eq`. It's the same flag the record shows, and it agrees with the dates on every staging group.

**A direct roster link** to a group that's inactive, excluded or of another type can't be reached if the group lookup sends all of the directory's eligibility keys together with the group's UUID:

```php
$client->post('groups/query?page%5Bsize%5D=1', ['json' => ['filter' => [
    'uuid_in'               => [$group_uuid],      // from wd{ID}_group
    'active_eq'             => true,               // Group Status: Active
    'resource_type_slug_in' => $group_type_slugs,  // when group types are configured
    'uuid_not_in'           => $excluded_uuids,    // when the exclude list isn't empty
]]]);
// 1 → render the roster; 0 → treat the link as unknown
```

Verified: the eligible group → 1; the same group in `uuid_not_in` → 0; the same group with another type → 0.

### Keyword

`name_{lang}_cont`, as for organizations. `name_en_cont` and `name_fr_cont` are verified (no-match → 0, `'seed'` → 3); `_cont` is case-insensitive (`'SEED'` → 3). **Bare `name_cont` is ignored.** `description_{lang}_cont` is also recognised, if the keyword should cover descriptions later; put both in one `g` OR group.

### Sort

| Key | Verified |
|---|---|
| `name_{lang}`, `-name_{lang}` | ✔ A–Z and Z–A. Empty names sort last ascending and first descending |
| `name` (no language) | ✘ **ignored**: the same order as a bogus key |
| `active_member_count`, `-active_member_count` | ✘ ignored |

### Group attributes used by cards

`name_{lang}`, `description_{lang}`, `type` (the group-type slug), `active`, `active_member_count`, `start_date`, `end_date`, `slug`, `identifying_number`. Relationships: `organization`, `parent_group`, `address`, `web_address`, `administrators`. Three staging groups have empty names, so the card needs an empty-name case.

### Active member count

`attributes.active_member_count` comes with every group, so it costs no request. **But it counts active group member rows, not people.** A person can hold two active rows in one group (an early renewal: one term ends 2026-12-31 and the next starts 2026-10-06), so the attribute can be higher than the roster's total:

| Group | `active_member_count` | Active rows | Distinct active people (= roster total) |
|---|---|---|---|
| FIG A | 3 | 3 | 2 |
| FIG B | 3 | 3 | 2 |
| FIG C | 6 | 6 | 5 |
| the other five | match | match | match |

The count that matches the roster takes **one batch request per page of groups**:

```php
$client->post('group_members/query?page%5Bsize%5D=2000', ['json' => ['filter' => [
    'group_uuid_in' => $page_group_ids,
    'active_eq'     => true,
]]]);
// count distinct relationships.person.data.id per relationships.group.data.id
```

Verified: 15 rows for the 8 groups, giving 1, 2, 2, 2, 5, 0, 0, 0. `group_uuid_in` with a no-match UUID → 0. If `meta.page.total_items` is over 2000, the counts are incomplete; fall back to `active_member_count` rather than page through. P2.1 picks one of the two (see [Phase 2 corrections](#phase-2-corrections), item 2).

## Group members

A group member row (`type: group_members`) is **one term**, not one person. On staging a member of FIG C has 11 rows (2016 to 2026), and the group has 55 rows for 5 people. Each row has:

- `attributes`: `type` (the role slug), `start_date`, `end_date` (null when open-ended), `active`, `identifying_number`, `external_id`;
- `relationships`: `group`, `person`.

Two endpoints return the rows: `GET groups/{uuid}/people` (what `wicket_get_group_members()` uses; there's no `POST …/people/query`, it's a 404) and `POST group_members/query` with `group_uuid_eq`. Neither suits the roster on its own: they page by row, so one person appears once per term, and they can't use the person's data-field filters (`search_query` is ignored on `group_members/query`). Nested includes (`include=person.emails`) return only the person.

### Roster: `people/query` joined to group members

`people/query` can join `group_members`. It returns **each person once**, and every person filter, sort and include from [People](#people-peoplequery) keeps working:

| Purpose | Predicate | Value | Verified |
|---|---|---|---|
| Member of the group | `group_members_group_uuid_eq` | `uuid` | FIG C → 5 people. No-match → 0. `group_members_group_uuid_in` also works |
| Group Member Status: Active | `group_members_active_eq` | `true` | FIG C → 5 |
| Role(s) | `group_members_resource_type_slug_in` | `[slug, …]` | no-match → 0 |
| Group still active | `group_members_group_active_eq` | `true` | `false` → 0 for an active group (recognised) |

Conditions on `group_members_*` **apply to the same row**, as with `membership_people_*`: Test Group's two people hold only `immediate-past-chair` rows and have no `member` row anywhere, and Test Group + role `member` → 0.

The full roster query, verified (FIG C: 5 people, 5 rows, no duplicates, although one person has two active rows):

```php
$client->post('people/query?' . $qs, ['json' => ['filter' => [
    'group_members_group_uuid_eq' => $group_uuid,
    'group_members_active_eq'     => true,          // Group Member Status: Active
    'g' => [
        ['m' => 'or',                                // roster keyword
         'given_name_cont' => $kw, 'family_name_cont' => $kw, 'full_name_cont' => $kw, 'identifying_number_eq' => $kw,
         'group_members_resource_type_slug_in' => $role_slugs_matching_kw],   // only when a role label matches
        ['m' => 'or', 'addresses_city_i_cont' => $loc, 'addresses_state_name_i_cont' => $loc], // roster location
    ],
    'search_query' => ['data_fields.{schema}.value.{field}' => [...]],       // roster facets
]]]);
// $qs = page[size]=…&page[number]=…&sort=family_name&include=emails,phones,addresses,web_addresses
```

- **Keyword on the role:** the MDP can't match a role label, so match the keyword against the role labels first (the `group_members` resource types, `name_{lang}`), then OR the matching slugs into the keyword group as `group_members_resource_type_slug_in`. Verified: Test Group + keyword `'chair'` → 0 on names alone, and 2 with `['immediate-past-chair']` in the OR group. The role key stays on the same row as the group: FIG C + a no-match keyword + `['immediate-past-chair']` → 0. Omit the key when no label matches.
- **Sort:** person keys only (`family_name`, `given_name`, as for people). Sorting on the join (`group_members_start_date`, `group_members_resource_type_slug`) is an **HTTP 500**, so it must never come from a visitor value.
- `group_members_active_at` is **ignored** on `people/query`.

### Group Member Status

| Setting | Filter |
|---|---|
| Active | `group_members_group_uuid_eq` + `group_members_active_eq: true` |
| All | `group_members_group_uuid_eq` only |
| Inactive | `group_members_group_uuid_eq` + `uuid_not_in: [active person UUIDs]` |

`group_members_active_eq: false` is **not** "inactive members". It matches anyone with an inactive row, and most current members have past terms: FIG C + `false` → 4, and all 4 are active members too. An inactive member is someone with rows in the group and **no** active row. That needs the active person UUIDs first: one `group_members/query` with `group_uuid_eq` + `active_eq: true` (`page[size]=2000`), then `uuid_not_in` on `people/query`. `uuid_not_in` on people is verified (one UUID → 4 of 5; a no-match UUID → 5). On staging nobody is inactive in any group, so this mode can only be checked with fixtures.

### Roster card: role, status and dates

One batch per rendered roster page, after the people query:

```php
$client->post('group_members/query?page%5Bsize%5D=2000&sort=-start_date', ['json' => ['filter' => [
    'group_uuid_eq'  => $group_uuid,
    'person_uuid_in' => $page_person_ids,
    'active_eq'      => true,     // only in the Active mode
]]]);
```

Verified: FIG C's 5 people → 6 rows. Map rows to people with `relationships.person.data.id`. A person can have several rows, so take **the first row per person** with `sort=-start_date` (verified; `start_date` ascending works too, `end_date` isn't a sort key). In the Active mode that's the newest active term; in All and Inactive it's the latest term. **P2.4 refinement:** the directory keeps each person's newest *active* row, else their newest row. That changes nothing in Active and Inactive, and in All it shows the term the person is in now rather than a newer one that has ended. `type` is the role slug, `active` the in-group status, `start_date` / `end_date` the dates (`end_date` null → "Present").

Other `group_members/query` keys, all verified (no-match → 0): `group_uuid_eq`, `group_uuid_in`, `person_uuid_in`, `active_eq` (FIG C: `true` → 6, `false` → 49), `active_at` (`'now'` → 6, `'1900-01-01'` → 0), `resource_type_slug_in`, `person_given_name_cont`, `person_family_name_cont`, `person_full_name_cont`, `person_addresses_city_i_cont`, `person_search_query` (the keyword form `wicket_search_group_members()` sends). **Ignored:** `type_eq`, `search_query`.

## Connections (`connections/query`)

`POST connections/query` (and `GET connections`). Every staging connection is `person_to_organization`. A record has `attributes.type` (the relationship-type slug), `connection_type`, `active`, `starts_at`, `ends_at`, `description`, and the relationships `person` / `organization` (also `from` / `to`).

| Purpose | Predicate | Value | Verified |
|---|---|---|---|
| Person ⟷ org only | `connection_type_eq` | `'person_to_organization'` | 40. No-match → 0 |
| Relationship type(s) | `resource_type_slug_in` | `[slug, …]` | `['employee']` → 27, the expected count. No-match → 0 |
| Active | `active_eq` | `true` | 21; `false` → 19. Matches the dates: no active row has a past `ends_at`, and no inactive row is open-ended |
| A page of orgs | `organization_uuid_in` | `[uuid, …]` | two orgs → 5, the expected count. No-match → 0 |
| A page of people | `person_uuid_in` | `[uuid, …]` | one person → 17, the expected count. No-match → 0 |

`active_at` is recognised (`'now'` → 21, the same as `active_eq: true`), but use `active_eq`. **Don't use** `type_eq` / `type_in` (ignored, 40), or `from_uuid_in` / `to_uuid_in` (**HTTP 500**). `include=person`, `include=organization` and both together work; nested includes (`person.emails`) return only the person. The included organization carries `legal_name_{lang}`, and the included person carries `job_title`.

### Employer on the individual card (P2.6)

One batch per page of people:

```php
$client->post('connections/query?page%5Bsize%5D=2000&include=organization', ['json' => ['filter' => [
    'connection_type_eq'    => 'person_to_organization',
    'person_uuid_in'        => $page_person_ids,
    'active_eq'             => true,
    'resource_type_slug_in' => $employer_relationship_slugs,  // when configured
]]]);
```

Map each row with `relationships.person.data.id` and `relationships.organization.data.id`, then read `legal_name_{lang}` from `included[type=organizations]`.

**The primary organization is unverified.** People have a `primary_organization` relationship, but it's `null` for all 175 people on staging, also through `GET people/{uuid}` and with `include=primary_organization`. No stack package reads it. P2.6 has to decide whether "primary organization" is worth supporting at all (see [Phase 2 corrections](#phase-2-corrections), item 7).

### Relationship Types contacts on the org card (P2.5)

Two batches per page of orgs: the connections, then the contact people with their contact records (nested includes don't work):

```php
// 1. Who qualifies
$client->post('connections/query?page%5Bsize%5D=2000&include=person', ['json' => ['filter' => [
    'connection_type_eq'    => 'person_to_organization',
    'organization_uuid_in'  => $page_org_ids,
    'active_eq'             => true,
    'resource_type_slug_in' => $contact_relationship_slugs,  // omit for "any type"
]]]);

// 2. Their email and phone, only when the card shows either
$client->post('people/query?page%5Bsize%5D=2000&include=emails,phones', ['json' => ['filter' => [
    'uuid_in' => $contact_person_ids,
]]]);
```

`uuid_in` on people is verified (no-match → 0). Name and title come from the included person in request 1 (`given_name`, `family_name`, `full_name`, `job_title`), so request 2 is needed only for the email and phone toggles. Those records go through `ContactResolver` as usual.

## Membership owner (P2.5)

The owner is **`relationships.owner`** (a person) on an `organization_memberships` row. All 23 active org membership rows on staging have one. Fetch it for a page of orgs in one request, extending the tier batch from [Tiers on the card](#tiers-on-the-card):

```php
$client->post('organization_memberships/query?page%5Bsize%5D=2000&include=membership,owner', ['json' => ['filter' => [
    'organization_uuid_in' => $page_org_ids,
    'status_eq'            => 'Active',
]]]);
```

Verified: 23 rows for the 10 orgs with an active membership; `include=owner` → 8 people, `include=membership,owner` → 8 memberships + 8 people. `owner_uuid_in` / `owner_uuid_eq` are recognised (no-match → 0). Nested includes (`owner.emails`) return only the person, so the owner's email and phone need the same `people/query` `uuid_in` batch as the contacts above (one request for owners and contacts together).

- **An org can have several active memberships with different owners** (2 of the 10 orgs on staging). P2.5's rule: when the directory filters by tiers, the owners of memberships in those tiers; otherwise every distinct owner, newest `starts_at` first. See [Phase 2 corrections](#phase-2-corrections), item 6.
- `is_active_owner` was `true` on every active row, and `is_active_owner_eq` is **ignored** (73 = all rows). Don't filter on it.
- **"Display relationship type"** (show the owner only if they have an active connection of the selected types to the org) is the connections batch above with `person_uuid_in` = the owners and `organization_uuid_in` = the page's orgs, matched per (person, org) pair in PHP. The keys are verified, but no owner on staging has an active connection to their own org (0 of 23 rows), so the positive case needs fixtures.

## Labels: group types, roles and relationship types

All three come from `GET resource_types` (one unpaged request; `Admin\ResourceTypes` already loads it and groups it by `attributes.resource_type`). Each record has `slug`, `name_{lang}` (`name_en`, `name_fr`, `name_es`), `weight` and `default`.

| `resource_type` | Used for | Staging slugs |
|---|---|---|
| `groups` | group type (`groups.type`, the `resource_type_slug_in` values) | `board`, `committee`, `working-group` |
| `group_members` | member role (`group_members.type`) | `chair`, `immediate-past-chair`, `member`, `officer`, `vice-chair` |
| `connection_person_to_organizations` | person ⟷ org relationship type (`connections.type`) | `chair`, `consultant`, `employee`, `general-contact`, `primary-contact`, `renewal-contact`, `sitting-member`, `student` |

EN and FR labels are both present (e.g. `employee` → Employee / Employé). Some tenants leave `name_fr` equal to `name_en`. `connection_person_to_people` also exists but isn't used. Cards should read these labels through `EnumLabels`, as they do org types today (`EnumLabels::org_types()`), so each map loads once per request and is cached per language. `EnumLabels` loads org types through `wicket_get_org_types_list()`, so the three new lists need their own loader on the same `resource_types` response.

## Rejected predicates (silently ignored)

These return the full total for a no-match value. **Never use them:**

| Predicate | Where tried | Note |
|---|---|---|
| `membership_uuid_in` | people | the plan's guess. Use `membership_people_membership_uuid_in` |
| `person_memberships_active_eq`, `person_memberships_membership_uuid_in` | people | no such association on people |
| `membership_entries_active_eq` | people | org-only association |
| `membership_status_eq`, `membership_in_grace_eq` | people | computed attributes, not filterable |
| `membership_number_eq` | people | use `identifying_number_eq` |
| `membership_people_active_at` | people | `active_at` only exists on the `*_memberships/query` endpoints |
| `phones_phone_type_eq`, `phones_type_eq` | people | phone type can't be filtered in a people query |
| `legal_name` (as a **sort** key) | orgs | use `legal_name_{lang}` |
| `type_eq`, `type_in` | groups, group_members, connections | use `resource_type_slug_in` |
| `name_cont` | groups | use `name_{lang}_cont` |
| `name`, `active_member_count` (as **sort** keys) | groups | use `name_{lang}` |
| `id_not_in` | groups | use `uuid_not_in` |
| `active_member_count_gt` | groups | not filterable |
| `search_query` | group_members | filter person data fields on `people/query` instead |
| `group_members_active_at` | people | use `group_members_active_eq` |
| `is_active_owner_eq` | organization_memberships | returns every row |

These return an **HTTP 500**, so never build them from visitor input:

- a plain-string `search_query` (instead of a hash);
- `from_uuid_in`, `to_uuid_in` on `connections/query` (use `person_uuid_in` / `organization_uuid_in`);
- sorting `people/query` on a join (`group_members_start_date`, `group_members_resource_type_slug`).

## Plan corrections

These are the assumptions in `mvp-plan.md` that the spike changed. The plan and tickets have been updated to match.

1. **People active membership** is `membership_people_status_eq: 'Active'`. **Tiers** use `membership_people_membership_uuid_in`. The plan's `membership_uuid_in?` guess is silently ignored.
2. **Org active membership:** `membership_entries_status_eq: 'Active'` is preferred over `membership_entries_active_eq`. They return the same set, but `status` is also what the tier lookup reads.
3. **Person tier on the card:** there is no `include=person_memberships`. It needs one batch `person_memberships/query` per page (or `organization_memberships/query` for orgs).
4. **`ContactResolver` and websites.** `web_addresses` have no `primary` flag, so the truth table's "else first primary" step and the `only_primary` option don't apply to websites. **Confirmed for ticket 2.5 (2026-10-02):**
   - websites fall back to the **first record of the matching type**;
   - `only_primary` isn't offered for the website rule (ticket 5.2).
5. **Contact field names:**
   - the type field is `type` (not `phone_type`);
   - show-in-directory is `consent_directory` (not `consent`).
6. **Unknown predicates and sort keys are silently ignored.**
   - Query builders must be unit-tested against the exact keys here.
   - Sort values must be whitelisted.
7. **Paging:** `page[size]` is silently capped at 2000, and 0 is an error. An out-of-range page returns an empty `data`, so clamp to `total_pages`.

## Phase 2 corrections

What the P2.0 spike changed in [phase-2-tickets.md](phase-2-tickets.md). The tickets have been updated to match.

1. **Group type filter** is `resource_type_slug_in`, not `type_in` (ignored on groups). Keyword is `name_{lang}_cont`, sort is `name_{lang}`. Excluded groups use `uuid_not_in`.
2. **Active member count:** `active_member_count` counts active *rows*, so a person with two overlapping active terms counts twice and the card can disagree with the roster total (FIG C: 6 vs 5). P2.1 chooses between the free attribute and one `group_members/query` batch per page that counts distinct people. **Recommended: the batch**, with the attribute as the fallback past 2000 rows, so the card and the roster always agree.
3. **The roster is a `people/query`**, joined with `group_members_group_uuid_eq` (+ `group_members_active_eq`), not `groups/{uuid}/people`. It lists each person once and keeps every MVP person filter, sort and facet. Role, in-group status and dates come from one `group_members/query` batch per roster page (first row per person by `-start_date`).
4. **Group Member Status: Inactive** means "has rows in the group but no active row", and needs one extra request for the active people's UUIDs. `group_members_active_eq: false` would list current members who have past terms.
5. **Roster keyword on the role** works only by matching role labels in PHP first, then ORing the slugs as `group_members_resource_type_slug_in`. Roster sort stays on person keys (a join sort is an HTTP 500).
6. **Membership owner** is `organization_memberships.relationships.owner`, fetched with the existing tier batch (`include=owner`). An org can have several active memberships with different owners. P2.5 settled which to show: the configured tiers' owners when the directory filters by tiers, else every distinct owner, newest first.
7. **Primary organization** can't be verified: `primary_organization` is null for every person on staging and nothing in the stack reads it. P2.6 should build Employer from active connections of the selected types and treat "primary organization" as an open question until a tenant with the data is found.
8. **Contact details for owners and contacts** need a second request per page (`people/query` `uuid_in` + `include=emails,phones`), because nested includes don't work on `connections` or `organization_memberships`. That makes P2.5 two or three requests per page; still none per row.
9. **No group data fields:** no JSON schema on staging is bound to groups, so the group list's facets are group type only. The Group Member card's facets are person data fields, as on individual directories.
