---
title: "MDP API Queries"
audience: [developer, agent]
updated: 2026-09-30
---

# Verified MDP queries for directories

Outcome of the API spike (ticket 0.1). Every filter, field and sort key below was run against a staging MDP (`demo-woocommerce-api.staging.wicketcloud.com`) through `wicket_api_client()` in `wp eval-file`. That tenant has 161 people and 19,933 organizations. The query builders (tickets 2.2, 2.3) and `ContactResolver` (2.5) must use exactly these names.

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

Read a field as the item where `key === $schema_key`, then `value[$field]`. Values can be strings, booleans or arrays of enum keys. Convert enum keys to labels with `wicket_get_schemas_options()`.

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

A plain-string `search_query` (instead of a hash) returns **HTTP 500**.

## Plan corrections

These are the assumptions in `mvp-plan.md` that the spike changed. The plan and tickets have been updated to match.

1. **People active membership** is `membership_people_status_eq: 'Active'`. **Tiers** use `membership_people_membership_uuid_in`. The plan's `membership_uuid_in?` guess is silently ignored.
2. **Org active membership:** `membership_entries_status_eq: 'Active'` is preferred over `membership_entries_active_eq`. They return the same set, but `status` is also what the tier lookup reads.
3. **Person tier on the card:** there is no `include=person_memberships`. It needs one batch `person_memberships/query` per page (or `organization_memberships/query` for orgs).
4. **`ContactResolver` and websites.** `web_addresses` have no `primary` flag, so the truth table's "else first primary" step and the `only_primary` option don't apply to websites. **Proposed, to be confirmed before ticket 2.5:**
   - websites fall back to the **first record of the matching type**;
   - `only_primary` isn't offered for the website rule (ticket 5.2).
5. **Contact field names:**
   - the type field is `type` (not `phone_type`);
   - show-in-directory is `consent_directory` (not `consent`).
6. **Unknown predicates and sort keys are silently ignored.**
   - Query builders must be unit-tested against the exact keys here.
   - Sort values must be whitelisted.
7. **Paging:** `page[size]` is silently capped at 2000, and 0 is an error. An out-of-range page returns an empty `data`, so clamp to `total_pages`.
