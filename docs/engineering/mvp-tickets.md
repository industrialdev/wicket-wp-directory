---
title: "MVP Tickets"
audience: [developer, agent]
updated: 2026-10-02
---

# `wicket-wp-directory` MVP tickets

Phase 1 (MVP) build tickets, derived from [mvp-plan.md](mvp-plan.md). IDs are `{step}.{n}`, where `step` is the plan's implementation step (0 = API spike … 7 = acceptance). Refer to a ticket by its ID, e.g. "let's do ticket 2.3". The same IDs prefix the ticket names in the Asana import (`[Directory 2.3] …`).

Keep the **Status** column current: `To do`, `In progress`, `Done`.

## Index

| ID | Ticket | Depends on | Status |
|---|---|---|---|
| 0.1 | [API spike: verify MDP query filters for people and organizations](#01) | — | Done |
| 1.1 | [Plugin scaffold: main file, composer, bootstrap, dependency guard](#11) | — | Done |
| 1.2 | [Directory custom post type and config post meta](#12) | 1.1 | Done |
| 1.3 | [DirectoryConfig value object and DirectoryType enum](#13) | 1.2 | Done |
| 2.1 | [RequestParams: parse and sanitize visitor GET parameters](#21) | 1.3 | Done |
| 2.2 | [OrganizationQueryBuilder for organizations/query](#22) | 0.1, 2.1 | Done |
| 2.3 | [PersonQueryBuilder for people/query](#23) | 0.1, 2.1 | Done |
| 2.4 | [DirectoryRepository: execute queries with caching and error handling](#24) | 2.2, 2.3 | Done |
| 2.5 | [ContactResolver: shared rules for address, email, phone and website](#25) | 0.1 | Done |
| 2.6 | [EntryMapper and entry DTOs](#26) | 2.4, 2.5 | Done |
| 2.7 | [FacetOptions: facet choices from schemas and resource types](#27) | 1.3 | To do |
| 3.1 | [Block registration (server side) and BlockAttributes](#31) | 1.1 | To do |
| 3.2 | [DirectoryRenderer and TemplateLoader (single render path)](#32) | 3.1, 2.6, 2.7 | To do |
| 3.3 | [Search bar template (keyword + location)](#33) | 3.2 | To do |
| 3.4 | [Refine Results filters template](#34) | 3.2 | To do |
| 3.5 | [Results header, Order by and pagination](#35) | 3.2 | To do |
| 3.6 | [Individual card template](#36) | 3.2 | To do |
| 3.7 | [Organization card template](#37) | 3.2 | To do |
| 3.8 | [Directory CSS (Wicket theme tokens) and JS](#38) | 3.2 | To do |
| 4.1 | [Block editor sidebar, preview and build](#41) | 3.1, 3.2 | To do |
| 5.1 | [Admin meta box: Directory type and eligibility](#51) | 1.3 | To do |
| 5.2 | [Admin meta box: Card configuration](#52) | 5.1 | To do |
| 5.3 | [Admin meta box: Filters (facets)](#53) | 5.1, 2.7 | To do |
| 5.4 | [Admin list table columns](#54) | 1.2 | To do |
| 6.1 | [Register the plugin in the QA suite and stack docs](#61) | 1.1 | To do |
| 6.2 | [Unit tests for config, query and data classes](#62) | 6.1 | To do |
| 6.3 | [WordPress integration tests](#63) | 6.1 | To do |
| 7.1 | [Theme compatibility check](#71) | 3.8, 4.1 | To do |
| 7.2 | [CHFA parity check: rebuild the org directory with the block](#72) | 7.1, 5.3, 5.2 | To do |
| 7.3 | [Documentation: README, AGENTS.md and plan updates](#73) | 7.2 | To do |

## 0. API spike

<a id="01"></a>

### 0.1 — API spike: verify MDP query filters for people and organizations

Before the data layer is built, confirm the exact Wicket API (MDP) filters and fields against a staging MDP, using wicket_api_client() in `wp shell`.

To verify:
- people/query filter names for active membership, membership tier (e.g. membership_uuid_in?), keyword (given_name/family_name/full_name/identifying_number) and address (city/state).
- organizations/query: membership_entries_active_eq, legal_name_{lang}_cont, type filter, data_fields search_query syntax (as used by the CHFA template).
- Contact records (addresses, emails, phones, web_addresses): field names for type (type vs phone_type) and the "show in directory" consent flag (consent_directory / consent).
- How a person's membership tier is returned (include=person_memberships?).
- Sort keys (given_name, family_name, legal_name_{lang}) and page[size] limits.

**Acceptance criteria:**

- docs/engineering/api-queries.md created with frontmatter, listing each verified filter/field with a working example payload.
- Any assumption in the MVP plan that turned out wrong is flagged and the plan doc updated.
- api-queries.md added to the Documentation table in AGENTS.md.

**Outcome:** see [api-queries.md](api-queries.md). Tickets 2.2, 2.3, 2.4, 2.5 and 5.2 below have been updated with the verified names.

## 1. Scaffold

<a id="11"></a>

### 1.1 — Plugin scaffold: main file, composer, bootstrap, dependency guard

Create the plugin skeleton following wicket-wp-financial-fields / wicket-wp-portus conventions.

- wicket-wp-directory.php header (Plugin Name "Wicket Directory", Requires PHP: 8.3, Requires Plugins: wicket-wp-base-plugin, Text Domain wicket-directory), declare(strict_types=1), constants (VERSION, FILE, PATH, URL, BASENAME).
- composer.json: industrialdev/wicket-wp-directory, type wordpress-plugin, PSR-4 Wicket\Directory\ => src/, php >=8.3, dev dep php-cs-fixer, scripts cs:lint / cs:fix / production / setup-hooks.
- .php-cs-fixer.dist.php, .ci/pre-push and .gitignore copied from portus.
- src/Plugin.php singleton; plugin_setup() on plugins_loaded; load_plugin_textdomain.
- Dependency guard: admin notice + early return when the base plugin is inactive; activation hook checks PHP version and dependencies.
- README.md stub.

**Acceptance criteria:**

- Plugin activates cleanly with the base plugin active.
- With the base plugin inactive: admin notice shown, no fatal errors.
- composer install + composer cs:lint pass.

<a id="12"></a>

### 1.2 — Directory custom post type and config post meta

**Depends on:** 1.1

Add src/PostType/DirectoryPostType.php.

- Register CPT wicket_directory: not public on the frontend, show_ui, show_in_rest (so the block can list directories via core-data), supports title + revisions, capabilities mapped to manage_options.
- Filter use_block_editor_for_post_type to use the classic edit screen for this CPT.
- register_post_meta('_wicket_directory_config') as a single array with a REST schema, auth_callback = manage_options.

**Acceptance criteria:**

- "Directories" menu appears for admins only.
- Edit screen uses the classic editor with Draft/Publish.
- Meta is readable via the REST API for users who can edit directories, and not exposed publicly.

**Outcome:** `src/PostType/DirectoryPostType.php`, registered from `Plugin::plugin_setup()`. Notes for later tickets:
- The CPT also supports `custom-fields`, because the REST posts controller only adds `meta` to responses when it does. The generic Custom Fields meta box is removed from the edit screen.
- WordPress serves published posts of any `show_in_rest` type to anyone in the `view` context. The config meta's schema is therefore `context: ['edit']`. The `edit` context needs the CPT's `edit_posts` capability (`manage_options`), so the config never appears in public or editor responses. The meta is also `revisions_enabled`.
- The meta REST schema lists only the top-level keys (`type`, `eligibility`, `card`, `facets`, `cache_version`), with nested objects left open (`additionalProperties: true`). Core strips any object property not declared in the schema, so a new top-level config key must be added there too. The `sanitize_callback` routes through `DirectoryConfig::sanitize()` (ticket 1.3).

<a id="13"></a>

### 1.3 — DirectoryConfig value object and DirectoryType enum

**Depends on:** 1.2

Add src/Config/DirectoryType.php (enum: Individual, Organization) and src/Config/DirectoryConfig.php.

Config shape (see plan "Directory config"):
- type
- eligibility: require_active_membership (default true), membership_ids, org_types (org only), opt_in {schema_key, field, value}
- card toggles per type, contact rules {type, only_directory, only_primary} for address/email/phone/website, address_format, eyebrow and tag-chip data fields
- facets: [{source: data_field|org_type, schema_key, field, label}]
- cache_version

Methods: defaults(type), from_post(post), sanitize(array). Changing type resets card and facet settings. cache_version increments on every save.

**Acceptance criteria:**

- sanitize() never trusts input: unknown keys dropped, types coerced, enums validated.
- Type change resets card/facets to that type's defaults.
- Covered by unit tests (see the unit tests ticket).

**Outcome:** `src/Config/DirectoryType.php` and `src/Config/DirectoryConfig.php`. `DirectoryPostType::sanitize_meta()` and the meta REST schema's `type` enum now use them. Unit tests are still ticket 6.2. Notes for later tickets:
- Methods are snake_case like the rest of the stack: `defaults(DirectoryType)`, `from_post(WP_Post|int): ?self` (null when the post isn't a directory), `sanitize(array $input, ?self $previous = null)`, `to_array()`. Properties are public readonly: `type`, `eligibility`, `card`, `facets`, `cache_version`. Every key is always present, so consumers don't need `isset()`. The array shapes are the `@phpstan-type`s at the top of `DirectoryConfig`.
- **Type reset and cache bump need `$previous`.** WordPress doesn't pass the post ID to a meta `sanitize_callback`, so the callback sanitizes statelessly (idempotent; no reset, no bump). The save handler (5.1) must call `DirectoryConfig::sanitize($input, DirectoryConfig::from_post($post))` and store `->to_array()`. Writes through REST or code keep the `cache_version` they send, so cached results last until the TTL.
- A type change also clears `eligibility.membership_ids`, not only the card and facets. Tiers are typed, so 5.1 would hide the old ones while they kept filtering. The 5.1 warning should mention tiers.
- Shapes:
  - Data-field references are `{schema_key, field}`. Both are set or both are `''`, and empty means off. They allow `[A-Za-z0-9_-]` only: case is kept for camelCase fields, and dots are rejected because the values become `search_query` path segments.
  - `card.{address,email,phone,website}` are `{show, type, only_directory, only_primary}`, where `type` is `any` or a resource-type slug. `website.only_primary` is always false.
  - `card.address_format` is one of `DirectoryConfig::ADDRESS_FORMATS` (default `full`). `card.website_label` is button text (`''` means the template's default).
  - `card.post_nominal`, `profile_image`, `logo` and `eyebrow` are single data-field references. `card.tag_chips` is a list of them.
  - `eligibility.opt_in` is `null` or `{schema_key, field, value}`. An empty value disables it, because the MDP ignores `''`. `"true"`/`"false"` become booleans.
  - Facets with an invalid source are dropped, and so are duplicates. `org_type` facets are allowed on organization directories only.
- A missing boolean keeps its default (e.g. `require_active_membership` stays true). Meta-box checkboxes must submit a hidden `0` so unticking them saves.
- Card defaults: individual shows job title and address only. Personal email, phone and website are off by default. Organization shows org type, description, address, email, phone and website. Member ID and tier are off for both, because the tier needs an extra request per page.

## 2. Query & data layer

<a id="21"></a>

### 2.1 — RequestParams: parse and sanitize visitor GET parameters

**Depends on:** 1.3

Add src/Query/RequestParams.php.

- Flat params prefixed per directory: wd{ID}_keyword, wd{ID}_location, wd{ID}_sort, wd{ID}_pg, wd{ID}_{facet_key}[] (flat so the base search-form component can pre-fill from $_GET).
- sanitize_text_field / sanitize_key; facet values checked against the facet's allowed options; unknown sort falls back to the default; page clamped to >= 1.
- Params for controls hidden by the block (keyword, location, filters, order by) are ignored.

**Acceptance criteria:**

- Two directories on one page don't interfere with each other.
- Invalid facet values, sorts and pages are dropped or clamped.
- Unit tested.

**Outcome:** `src/Query/RequestParams.php`, plus sort tokens on `DirectoryType`. Unit tests are still ticket 6.2. Notes for later tickets:
- **Sort tokens.** `DirectoryType::sort_options()` lists language-neutral tokens and their labels: individual `first_name_asc|desc`, `last_name_asc|desc`; organization `name_asc|desc`. The first one is the default (`default_sort()`). `sanitize_sort()` returns a valid token or the type default. These tokens are what `defaultSort` stores and what `wd{ID}_sort` carries.
  - 2.2/2.3 map tokens to MDP keys: `first_name` → `given_name`, `last_name` → `family_name`, `name` → `legal_name_{lang}`, with a `-` prefix for `_desc`.
  - 3.1 `BlockAttributes` validates `defaultSort` with `DirectoryType::sanitize_sort()`. 4.1 needs the same tokens and labels in JS.
- `RequestParams::from_request($directory_id, $config, $facet_options, $default_sort, hide_keyword:, hide_location:, hide_filters:, hide_order_by:, query:)`. The `hide_*` flags come from 3.1's attributes; `query` defaults to `$_GET` and is unslashed once. Properties are public readonly: `directory_id`, `keyword`, `location`, `sort`, `page`, `facets`.
- An unknown visitor sort falls back to the block's `defaultSort`, and an invalid `defaultSort` falls back to the type default.
- `page` is at least 1. It isn't capped to the last page, because that's only known after the query. 2.4/3.5 clamp it to `meta.page.total_pages`.
- Keyword and location go through `sanitize_text_field` and are capped at 200 characters. An array value is ignored.
- **Facet keys** come from `RequestParams::facet_key($facet)`: `org_type`, or `{schema_key}__{field}` for a data field. They have no dots because PHP turns dots in GET keys into `_`. Use this one function wherever a facet needs a key: GET params, the `$facet_options` map, and 2.7's `FacetOptions` output.
- `$facet_options` is `facet key => allowed values` (2.7 supplies it). A facet missing from the map accepts no values. Selected values are sanitized, checked strictly against the allowed strings, and de-duplicated. A scalar `wd{ID}_{key}=x` is accepted as well as `[]`. Facets with nothing selected are left out of `facets`, so builders never send `''`.
- `param($name)` builds the full GET key (e.g. `wd12_keyword`) for templates: `search-form`'s `url-param`, form field names, and the pagination format. `facet_values($facet)` gives the selected values for a facet.
- The org-type facet isn't intersected with `eligibility.org_types` here. 2.2 does that, and 2.7/3.4 should only offer eligible org types.

<a id="22"></a>

### 2.2 — OrganizationQueryBuilder for organizations/query

**Depends on:** 0.1, 2.1

Add src/Query/OrganizationQueryBuilder.php that builds the Ransack payload.

- Eligibility: membership_entries_status_eq 'Active', membership_entries_membership_uuid_in (tier UUIDs), type_in (org-type slugs), opt-in data field (search_query data_fields.{key}.value.{field}).
- Keyword: legal_name_{lang}_cont. Location: OR group (`g: [{m: 'or', …}]`) of addresses_city_i_cont / addresses_state_name_i_cont.
- Facets: one search_query key per data-field facet (array = any-of); org-type facet intersected with org_types eligibility into a single type_in.
- The MDP silently ignores unknown predicates and sort keys, so emit only the keys in api-queries.md and whitelist sort values.
- sort (legal_name_{lang} asc/desc), page[size|number], include=emails,phones,addresses,web_addresses.
- Apply the wicket_directory/query_args and wicket_directory/query_args_{slug} filters.
- Reuse the base plugin's query-string fix (page[0] -> page[]).

**Acceptance criteria:**

- Output matches the verified filters in api-queries.md.
- Reproduces the CHFA template's query when configured the same way.
- Unit tested for each combination of eligibility, keyword, location, facets and sort.

**Outcome:** `src/Query/QueryBuilder.php` (abstract, shared), `src/Query/OrganizationQueryBuilder.php` and `src/Query/DirectoryQuery.php`. Unit tests are still ticket 6.2. Checked with `wp eval-file`: 21 offline assertions, plus live runs against the staging tenant (no conditions 19,933, active 10, `type_in: ['company']` 2,846; every no-match keyword, location, type and opt-in → 0; `legal_name_en` / `-legal_name_en` give A→Z / Z→A). Notes for later tickets:
- `(new OrganizationQueryBuilder())->build($config, $params, $per_page, $slug = '', $lang = null)` returns a `DirectoryQuery`. It throws `InvalidArgumentException` if the config isn't an organization directory. `$lang` defaults to `wicket_get_current_language()`; anything that isn't two letters falls back to `en`, because it becomes part of `legal_name_{lang}`.
- `$per_page` is clamped to 1–50 (`QueryBuilder::MIN_PER_PAGE` / `MAX_PER_PAGE`) as well as in 3.1. The page number is `RequestParams::$page`, uncapped.
- `DirectoryQuery` has `endpoint`, `args` (`filter`, `page`, `sort`, `include`) and `matches_nothing`. 2.4 sends `wicket_api_client()->post($query->path(), ['json' => $query->body()])`. `path()` applies the base plugin's `page[0]` → `page[]` fix (the base plugin only has it inline, so the regex is repeated). `body()` sends an empty filter as `{}`. `args` is the natural input for 2.4's cache key.
- **`matches_nothing`.** Ransack treats an empty `type_in: []` as blank and drops it, which would list every eligible org. So when the visitor's org types don't intersect `eligibility.org_types`, or a data-field facet sits on the opt-in field without the opt-in value selected, the builder returns `matches_nothing = true` and `body()` throws `LogicException`. **2.4 must check the flag and return an empty `ResultPage` without calling the API.** The filters don't run for such a query.
- A facet on the opt-in field only narrows it: the opt-in condition always stays, so a facet can't widen eligibility.
- Filter order: eligibility (`membership_entries_status_eq`, `membership_entries_membership_uuid_in`, `type_in`), `legal_name_{lang}_cont`, `g` (location OR group), `search_query` (opt-in, then data-field facets in config order). Empty conditions are left out.
- **Hooks.** `wicket_directory/query_args`, then `wicket_directory/query_args_{slug}` (only when `$slug` isn't empty), each with `($args, $config, $params)`. A filter that returns a non-array is ignored. `{slug}` is the directory post's `post_name`; 3.2 passes it, and should use the same slug for the `entry_{slug}` and `before_render_{slug}` hooks.
- **2.3** extends `QueryBuilder`. It implements `type()`, `endpoint()`, `filter()` and `sort_key()`, and reuses `location_group()` and `search_query()`. The org-type intersection stays in the org builder.
- **CHFA parity.** The CHFA template isn't in this workspace (`wicket-child` has only the generic org template). That template differs on purpose: it puts `addresses_city_i_cont` at the top level and has no state OR, and it sends `''` for unset values. Compare against the real CHFA template in 7.2.

<a id="23"></a>

### 2.3 — PersonQueryBuilder for people/query

**Depends on:** 0.1, 2.1

Add src/Query/PersonQueryBuilder.php, same contract as the organization builder.

- Eligibility: membership_people_status_eq 'Active', membership_people_membership_uuid_in (tier UUIDs).
- Keyword: OR group (`g: [{m: 'or', …}]`) over given_name_cont, family_name_cont, full_name_cont, identifying_number_eq.
- Location: OR group over addresses_city_i_cont / addresses_state_name_i_cont.
- Sort: given_name / family_name asc/desc.
- Same includes, paging and filters as the organization builder.

See wicket-wp-admin-org-roster/src/Services/MdpClient.php searchPersons() for a working people/query example.

**Acceptance criteria:**

- Output matches the verified filters in api-queries.md.
- Unit tested for each combination.

**Outcome:** `src/Query/PersonQueryBuilder.php`, extending `QueryBuilder`. Unit tests are still ticket 6.2. Checked with `wp eval-file`: 36 offline assertions, plus live runs against the staging tenant. The live totals match api-queries.md: no conditions 161, active 41, active + keyword "a" 27, one tier 7 active / 11 any status, a real `identifying_number` → 1, every no-match keyword, location, tier and opt-in → 0, and all four sorts give distinct orders. Notes for later tickets:
- `(new PersonQueryBuilder())->build($config, $params, $per_page, $slug = '', $lang = null)` has the same contract as the org builder. It returns a `DirectoryQuery` for `people/query`, and it throws `InvalidArgumentException` for a non-individual config. `$lang` isn't used for people: names aren't translated.
- Filter order: `membership_people_status_eq`, `membership_people_membership_uuid_in`, then `g` (the keyword OR group first, then the location OR group, each only when set), then `search_query` (opt-in, then data-field facets). The two groups are ANDed.
- Sort tokens map to `given_name` / `-given_name` (`first_name_asc|desc`) and `family_name` / `-family_name` (`last_name_asc|desc`).
- `matches_nothing` can only come from the opt-in/facet conflict here: individual configs have no org types, and `DirectoryConfig` drops org-type facets on them. 2.4 still checks the flag the same way for both builders.
- 2.4 can pick the builder from `$config->type`: `DirectoryType::Individual` → `PersonQueryBuilder`, `Organization` → `OrganizationQueryBuilder`.

<a id="24"></a>

### 2.4 — DirectoryRepository: execute queries with caching and error handling

**Depends on:** 2.2, 2.3

Add src/Api/DirectoryRepository.php.

- Call wicket_api_client()->post('{people|organizations}/query?...').
- Transient cache keyed by md5(directory ID, cache_version, language, params); TTL 10 min via the wicket_directory/cache_ttl filter.
- On an API exception: log with Wicket()->log()->error(..., ['source' => 'wicket-directory']) and return an empty ResultPage flagged as unavailable (never die or print errors).
- ResultPage: items, total, current page, total pages.
- Tier labels: when the tier toggle is on, one batch request per page (person_memberships/query or organization_memberships/query with {person|organization}_uuid_in + status_eq 'Active', include=membership), cached with the page. See api-queries.md "Tiers on the card".

**Acceptance criteria:**

- A repeat visit with the same params is served from cache.
- Saving a directory invalidates its cache.
- API failure shows the friendly "unavailable" state, with a log entry.

**Outcome:** `src/Api/DirectoryRepository.php` and `src/Api/ResultPage.php`, plus `DirectoryQuery::with_page()`. Unit tests are still ticket 6.2. Checked with `wp eval-file`: 27 assertions against the staging tenant, using real directory posts. They cover cache hits, invalidation, the out-of-range page, matches-nothing, the failure paths and their log entries, person and org tiers, the language in the key, and the TTL filter. Notes for later tickets:
- `(new DirectoryRepository())->fetch($post, $config, $params, $per_page, $lang = null)` returns a `ResultPage` and never throws. It picks the builder from `$config->type` and passes `$post->post_name` as the slug. 3.2 calls it with the directory post it already loaded. The constructor takes an optional client factory (`callable(): object`) so 6.2 can pass a fake client. It defaults to `wicket_api_client()`.
- **`ResultPage`** has public readonly `items` (raw `data[]` resources), `included`, `total`, `page`, `total_pages`, `per_page`, `tiers` and `unavailable`. Helpers: `is_empty()`, `first_number()` / `last_number()` for 3.5's "Displaying X–Y of N" (0 when empty), and `tiers_for($uuid)`. 2.6's `EntryMapper` reads `items` + `included`. Don't call `reset()` / `end()` on its array properties: they take a reference, and that fails on readonly properties.
- **Empty pages.** `matches_nothing` returns `ResultPage::empty()` without an API call, so `unavailable` is false and 3.5 shows "no results". An API failure gives an empty page with `unavailable = true`, and 3.5 shows the alert. The page is 1 and `total_pages` is 0 in both cases.
- **Page clamp.** If the requested page is past `meta.page.total_pages` (and there are results), the repository re-fetches the last page, which costs one extra request. It caches the result under the original key, so `ResultPage::$page` is the page actually shown. 3.5 should build pagination from it, not from `RequestParams::$page`.
- **Cache.** One transient per query: `wicket_directory_` + `md5` of the cache format, directory ID, `cache_version`, language, the post's `post_modified_gmt`, the full stored config, and the endpoint + final `args`. The config and the modified date are in the key on purpose. Any save invalidates the cache, even a REST or code write that doesn't bump `cache_version` (only 5.1's save handler does), and even a save that changes nothing. Old entries aren't deleted; they expire with the TTL. The cached value is `ResultPage::to_array()` (plain arrays, not serialized objects); `CACHE_FORMAT` must be bumped if that shape changes.
- **`wicket_directory/cache_ttl`** gets `($ttl, $config, $post)`; the default is 600. A value of 0 or less disables both reading and writing the cache. This matters because a transient set with 0 never expires.
- **Failures** are logged as `Wicket()->log()->error()` with `['source' => 'wicket-directory', 'directory_id' => …]`, and they are never cached. This covers no client (`wicket_api_client()` returned false), a thrown exception (HTTP 4xx/5xx), a response without a `data` array, and an exception while building the query. On sites with WooCommerce the log is `uploads/wc-logs/wicket-directory-*.log`; otherwise it's `uploads/wicket-logs/`.
- **Tiers.** When `card.membership_tier` is on and the page has items, the repository makes one `{person|organization}_memberships/query` request (`{entity}_uuid_in` + `status_eq: 'Active'`, `page[size]=2000`, `include=membership`). `tiers` is `uuid => list<{id, slug, name}>`, distinct and in response order. `name` is `name_{lang}`, then `name`, then `name_en`, then the slug. When `eligibility.membership_ids` is set, only those tiers are kept. Otherwise every active tier is kept, and that includes org-type tiers cascaded onto people (seen on staging, e.g. "Xyz Org Membership" on a person). 3.6 may want to hide those. If the tier request fails, the results still render without tiers, the error is logged, and the page isn't cached, so the next visit retries.

<a id="25"></a>

### 2.5 — ContactResolver: shared rules for address, email, phone and website

**Depends on:** 0.1

Add src/Data/ContactResolver.php, used for all four record types.

Rules (from the prototype):
1. Filter by type ("any" keeps all).
2. Neither only_directory nor only_primary set: first record flagged show-in-directory, else first primary, else nothing.
3. Either flag set: keep only records matching the set flags (AND when both); no fallback.
4. Empty result hides the row on the card (no "Not provided").

Fields (verified in 0.1): `type` and `consent_directory` on all four record types; `primary` on emails, phones and addresses. **web_addresses have no `primary`**: the fallback is the first record of the matching type, and only_primary doesn't apply (confirmed 2026-10-02).

**Acceptance criteria:**

- One implementation, four call sites.
- Every truth-table branch unit tested for each record type.

**Outcome:** `src/Data/ContactResolver.php`. Unit tests are still ticket 6.2. Checked with `wp eval-file`: 64 assertions on fixtures cover every truth-table branch for addresses, emails and phones, plus the website variants, null/non-array entries, strict flags and the unknown-field error. A run against 10 staging orgs with the default org card rules picked the primary addresses, and it showed an unflagged website through the first-of-type fallback. Notes for later tickets:
- `ContactResolver::resolve($field, $records, $rule)` is static and pure (no API calls). `$field` is one of `DirectoryConfig::CONTACT_FIELDS`; an unknown field throws `InvalidArgumentException`. `$records` are the entity's JSON:API resources for that field, in relationship order. Pass `getIncludedRelationship()`'s result straight in (use `?? []`), because nulls and other non-arrays are skipped. `$rule` is `$config->card[$field]`.
- **`ContactResolver::RELATIONSHIPS`** maps each field to its relationship name (`address` → `addresses`, `email` → `emails`, `phone` → `phones`, `website` → `web_addresses`). 2.6 loops over it, so all four fields share one call site.
- **Return value.** It returns `list<resource>`. With no flags set (the default rule), that is at most one record. With `only_directory` / `only_primary` set, it is **every** matching record, as in the prototype. 2.6 decides whether the card shows one or all. It returns `[]` when `show` is false or nothing matches, and the card then hides the row.
- **Websites.** The default rule takes the first `consent_directory` website, else the first website of the matching type. `only_primary` is ignored, even if a stored rule has it set and even if a record has a `primary` attribute.
- **Flags are strict.** Only a real `true` counts for `consent_directory` and `primary` (`1` and `"true"` don't), so an odd payload never shows a record that wasn't opted in. The type match is an exact string compare against the record's `attributes.type` slug.
- The resolver doesn't look at display values. A picked record with an empty `address` / `number_*` is still returned, and 2.6 or the template should hide that row.

<a id="26"></a>

### 2.6 — EntryMapper and entry DTOs

**Depends on:** 2.4, 2.5

Add src/Data/EntryMapper.php plus PersonEntry / OrganizationEntry DTOs.

- Map API resources + included records (via \Wicket\ResponseHelper::getIncludedRelationship) to flat, card-ready DTOs.
- Use ContactResolver for contact fields.
- Convert data-field enum keys to labels with wicket_get_schemas_options() (schemas cached once per request).
- Language-aware names/descriptions via wicket_get_current_language().
- Apply the wicket_directory/entry and wicket_directory/entry_{slug} filters.
- Don't call wicket_get_resource_type_name_by_slug() or wicket_get_\*_connections_by_id() per row.

**Acceptance criteria:**

- No per-row API calls.
- EN/FR labels resolve correctly.

**Outcome:** `src/Data/EntryMapper.php`, `src/Data/Entry.php` (shared base), `src/Data/PersonEntry.php`, `src/Data/OrganizationEntry.php` and `src/Data/EnumLabels.php`. `QueryBuilder::language()` is now public so the mapper normalises the language the same way the query does. Unit tests are still ticket 6.2. Checked with `wp eval-file`: 49 assertions on fixtures (every toggle on and off, the three address formats, contact formatting, EN/FR/fallback labels, both filters and their coercion, failed label loads). Then a run against staging: 41 people and 10 orgs mapped in EN and FR used one `json_schemas` load and one `resource_types` load in total, and no PHP notices. FR org types resolved ("Company" → "Compagnie"), as did schema `enumNames` ("< 1" → "Less than 1") and `ui:i18n` labels. Notes for later tickets:
- **`(new EntryMapper())->map($page, $config, $slug, $lang = null)`** returns one entry per `ResultPage::$items` item, in order: `list<PersonEntry>` or `list<OrganizationEntry>` by `$config->type`. An empty page gives `[]`. 3.2 passes the directory post's `post_name` as `$slug`. The constructor takes an optional `EnumLabels`, so 6.2 can pass one built with fake loaders. It defaults to `EnumLabels::shared()`.
- **Entries hold raw values.** Templates (3.6, 3.7) must escape them. Anything the card config turns off, or that has no value, is `''` or `[]`, so a template shows a row only when its value is non-empty. It never has to check the config. The exception is `website_label`, which stays in the config: the button label is `$config->card['website_label']` or a translated default.
- **Shared fields** (`Entry`): `id`, `name`, `member_id` (`display_identifying_number`, else `identifying_number`), `tiers` (names), `addresses` (`list<list<string>>`: one list of display lines per address, in `address_format`), `emails`, `phones` (`list<{number, extension, uri}>`; `uri` is `tel:+…;ext=…`, and the template formats "ext."), `websites` (http/https URLs; one stored without a scheme gets `https://`), `image_url` (profile image or logo data field, http/https only), `eyebrow` (labels comma-joined), `tags` (one label per value across every chip field, distinct) and `extra` (always `[]`; filters can fill it for theme overrides).
- **`PersonEntry`** adds `salutation` (`honorific_prefix`), `given_name`, `middle_name` (`additional_name`), `family_name`, `suffix`, `post_nominal` (data field, labels comma-joined) and `job_title`. `name` is salutation, given, middle, family and suffix, using only the parts the card turns on. When there's no given or family name it is `full_name`. The post-nominal is a separate field, so 3.6 decides how to show it (e.g. `Jane Doe, PhD`). **Open question:** the `suffix` toggle reads the MDP's `suffix` attribute, not `honorific_suffix`. Neither is set on any staging person. Re-check during 7.2.
- **`OrganizationEntry`** adds `org_type` (slug), `org_type_label` and `description`. Names and descriptions read `{attr}_{lang}`, then `{attr}`, then `{attr}_en`, for `legal_name` and `description`. On staging `description_fr` is often empty while `description` is set. The description is plain text with `\n\n` paragraph breaks (3.7: `wpautop(esc_html(...))` or similar).
- **Contacts.** All four fields come from `ContactResolver::resolve()` in one loop over `RELATIONSHIPS`, with records matched by `ResponseHelper::getIncludedRelationship()`. When a rule sets `only_directory` / `only_primary`, an entry can hold several emails, phones, addresses or websites, and the card shows them all. Invalid emails (`is_email()`), phones with no digits, non-http(s) URLs and addresses with no lines are dropped after resolving. So a picked record with an empty value hides the row, and there is no fallback to the next record.
- **Data fields.** A string or number value is one value, and a list gives one value per item. Booleans, objects and empty values give nothing. Enum keys become labels; values that aren't keys (free text, or a key removed from the schema) are kept as they are. `EnumLabels::data_field()` takes keys from `wicket_get_schemas_options()` and picks each label from: `ui:i18n.enumNames.{lang}`, then the helper's label, then the schema's `enumNames`, then `ui:i18n.enumNames.en`, then the key. The helper alone misses `enumNames` (staging `practice_details.experienceRange` would show "< 1"). It also reads only the site locale, not `wicket_get_current_language()`.
- **Label loads.** `EnumLabels` loads `wicket_get_schemas()` and `wicket_get_org_types_list()` lazily: schemas only when a set data-field reference has a value, org types only when `org_type` is on. Each loads at most once per request, shared by every block on the page. A failure (including the base helpers' fatal `Error` when there's no client) is logged under `wicket-directory`, and the cards show raw keys and slugs for the rest of the request. **These loads aren't cached across requests:** a page view whose results come from the transient still makes one `json_schemas` request, and one `resource_types` request for org directories. 2.7 should build `FacetOptions` on `EnumLabels` and add the planned per-language transient, so that both the facets and the cards use it.
- **`wicket_directory/entry`, then `wicket_directory/entry_{slug}`**, get `($data, $item, $config)`. `$data` is the entry's `to_array()`, `$item` is the raw API resource (with `attributes.data_fields`), and `$config` is the `DirectoryConfig`. They return the array, and the mapper rebuilds the entry with `Entry::with()`. Unknown keys are dropped, a value of the wrong type keeps the original, list items of the wrong type are dropped, and a non-array return is ignored. Filters can't remove an entry; that would break the "X–Y of N" count, so use `query_args` instead.

<a id="27"></a>

### 2.7 — FacetOptions: facet choices from schemas and resource types

**Depends on:** 1.3

Add src/Data/FacetOptions.php.

- Data-field facets: options from wicket_get_schema() + wicket_get_schemas_options() with i18n labels.
- Org-type facet: wicket_get_resource_types() / wicket_get_org_types_list().
- Cache in a transient (e.g. 1 hour) per language.
- Used by the Refine Results template, RequestParams validation, and the admin Filters meta box.

**Acceptance criteria:**

- Facet options match what MDP admins see.
- Options come from the enum, not from the current result set.

## 3. Block & rendering

<a id="31"></a>

### 3.1 — Block registration (server side) and BlockAttributes

**Depends on:** 1.1

Register the dynamic block wicket/directory.

- blocks/directory/block.json: apiVersion 3, category wicket, supports.html false, style = directory.css.
- Attributes: directoryId (0), defaultSort (''), resultsPerPage (6), hideKeyword, hideLocation, hideFilters, hideOrderBy, collapseRefineOnMobile (all false).
- src/Block/DirectoryBlock.php: register_block_type with render_callback -> DirectoryRenderer.
- src/Block/BlockAttributes.php: sanitize/clamp (resultsPerPage 1-50, invalid defaultSort -> type default).
- No shortcode.

**Acceptance criteria:**

- Block appears in the inserter.
- Attributes are sanitized server side.
- Unit tested (BlockAttributes).

<a id="32"></a>

### 3.2 — DirectoryRenderer and TemplateLoader (single render path)

**Depends on:** 3.1, 2.6, 2.7

Add src/Render/DirectoryRenderer.php and src/Render/TemplateLoader.php.

- render(dir_id, attrs): load directory; if missing or not publish -> empty string on the frontend, alert notice in the editor preview.
- Parse request -> query -> map -> render templates/directory.php and parts.
- Fire wicket_directory/before_render and wicket_directory/before_render_{slug}; wicket_directory/card_template filter.
- TemplateLoader: theme override at {theme}/wicket-directory/{file}.php, fallback to plugin templates/.
- Used by both the frontend render_callback and the editor ServerSideRender.

**Acceptance criteria:**

- Draft directory renders nothing for visitors and a notice in the editor.
- A template copied into the theme overrides the plugin's.
- All output escaped; all strings translatable.

<a id="33"></a>

### 3.3 — Search bar template (keyword + location)

**Depends on:** 3.2

templates/parts/search-bar.php.

- Keyword: base get_component('search-form') as-is, url-param = wd{ID}_keyword, placeholder per type.
- Location: icon component + input with component-search-form classes.
- Row order [keyword][location][Search] via .wicket-directory__search .component-search-form { display: contents } + flex order.
- Hide Keyword Search -> render only the button component as submit; Hide Location Search -> no location input.

**Acceptance criteria:**

- Keyword value pre-fills after searching.
- Correct order and a single Search button in every Hide combination.

<a id="34"></a>

### 3.4 — Refine Results filters template

**Depends on:** 3.2

templates/parts/filters.php (base filter-form is not used: it only supports WP taxonomies).

- One checkbox group per configured facet, options from FacetOptions.
- Same component-filter-form__\* class names so the theme's filter-form.scss styles it.
- See more / See less after 5 options, selected count, Apply Filters and Clear All (button component).
- Collapsible on mobile/tablet; initial state from collapseRefineOnMobile.
- Hidden entirely when Hide Filters is on or the directory has no facets.

**Acceptance criteria:**

- Looks consistent with the theme listing block's filters.
- Selected facets persist across pages and sorts.
- Clear All resets only this directory's params.

<a id="35"></a>

### 3.5 — Results header, Order by and pagination

**Depends on:** 3.2

templates/parts/results-header.php, pagination.php, no-results.php.

- "Displaying X-Y of N results" count.
- Order by native select inside .form; options per type (Individual: First/Last Name A-Z/Z-A; Organization: Name A-Z/Z-A); hidden when Hide Order By is on (defaultSort still applies).
- Pagination: theme wicket_pagination() when available with a custom base/format for wd{ID}_pg; else core paginate_links() with the same nav.wicket-pagination markup.
- No-results message (translatable) and API-unavailable alert component.

**Acceptance criteria:**

- Paging keeps keyword, location, facets and sort.
- Works with and without the Wicket theme's pagination helper.

<a id="36"></a>

### 3.6 — Individual card template

**Depends on:** 3.2

templates/cards/card-individual.php.

- component-card-listing structure/classes so theme card styles apply.
- Toggles: salutation, middle name, suffix, post-nominal, job title, member ID, membership tier, address (format), email, phone, website + label, profile image (plain img, lazy), eyebrow and tag chips.
- Components: tag (chips), button (Visit Website, new tab), link + icon (email/phone).
- Pass pre-escaped values to components.
- Container query stacks columns on narrow widths.

**Acceptance criteria:**

- Every toggle shows/hides its field.
- Rows with no resolved value are hidden.

<a id="37"></a>

### 3.7 — Organization card template

**Depends on:** 3.2

templates/cards/card-organization.php.

- Same structure as the individual card.
- Toggles: org type, description with Show more / Show less (Alpine), member ID, tier, address (format), email, phone, website, logo, eyebrow and tag chips.
- Organization Contact is out of scope (phase 2).

**Acceptance criteria:**

- Matches the current CHFA org card content (business type eyebrow, description, contact, website, attribute/certification chips).

<a id="38"></a>

### 3.8 — Directory CSS (Wicket theme tokens) and JS

**Depends on:** 3.2

assets/css/directory.css and assets/js/directory.js.

- Plugin-scoped --wd-\* layer on .wicket-directory mapped from Wicket theme tokens (--color-\*, --spacing-\*, --border-radius-\*, --box-shadow-\*, --font-size-\*, --layout-sidebar), each with a fallback value.
- No hard-coded colours or sizes; no Tailwind utilities in plugin markup; BEM .wicket-directory__\*.
- Container queries for cards; focus ring uses --box-shadow-focus.
- JS: only mobile collapse default; Alpine from the theme/base plugin (wicket-plugin-alpine-script on non-Wicket themes).
- Loaded only when the block is on the page, and in the editor.

**Acceptance criteria:**

- Overriding a --color-\* or --spacing-\* token in a child theme changes the directory.
- Readable on a non-Wicket theme.

## 4. Block editor UI

<a id="41"></a>

### 4.1 — Block editor sidebar, preview and build

**Depends on:** 3.1, 3.2

blocks/directory/index.js + edit.js, built with @wordpress/scripts into build/ (kept in the repo).

Settings sidebar (one PanelBody "Directory"):
- Select a Directory: SelectControl of published directories only (useEntityRecords postType wicket_directory, status publish).
- Default Sort Order: options depend on the selected directory's type; reset when the directory changes.
- Number of Results to Display (NumberControl, 1-50).
- Hide Keyword Search, Hide Location Search, Hide Filters, Hide Order By, Collapse Refine Results by default on mobile/tablet (ToggleControls).

Canvas: Placeholder until a directory is chosen; ServerSideRender afterwards. If the saved directory is no longer published, keep it and show a warning Notice.

package.json with build/start scripts.

**Acceptance criteria:**

- Changing any option updates the preview.
- Draft directories can't be picked; an existing selection that became Draft shows a warning.
- npm run build produces build/directory.

**Note from 1.2:** `useEntityRecords('postType', …)` requests `context=edit` by default, and that context needs `manage_options` for this CPT. Pass `context: 'view'` so editors (who place blocks but aren't admins) can list directories. The directory's `type` (needed for the Default Sort options) lives in the config meta, which is edit-context only. Editors can't read it, so expose `type` separately in the `view` context (e.g. `register_rest_field` or a small extra meta key) before building the sidebar.

## 5. Admin screens

<a id="51"></a>

### 5.1 — Admin meta box: Directory type and eligibility

**Depends on:** 1.3

src/Admin/DirectoryMetaBoxes.php + src/Admin/views/.

- Type (Individual / Organization) with a warning that changing it resets card and filter settings.
- Eligibility: require active membership, membership tiers, org types (organization only), opt-in data field (schema key, field, value).
- Save handler: nonce, manage_options, DirectoryConfig::sanitize, bump cache_version.

**Acceptance criteria:**

- Settings save and reload correctly.
- Invalid input is rejected or sanitized.

<a id="52"></a>

### 5.2 — Admin meta box: Card configuration

**Depends on:** 5.1

Card toggles for the selected type (see plan "Directory config" -> card).

- Contact rule fieldset repeated for address, email, phone and website: Type (Any + resource types), Only show if flagged "Show in Directory", Only show if primary (not for website: web addresses have no primary flag), with hint text explaining the rules.
- Address format radio.
- Eyebrow and tag-chip data-field pickers.
- Only the current type's toggles are shown.

**Acceptance criteria:**

- Every toggle maps to the card output.
- Hint text matches ContactResolver behaviour.

<a id="53"></a>

### 5.3 — Admin meta box: Filters (facets)

**Depends on:** 5.1, 2.7

Facet repeater: add, remove, reorder; editable label.

- Choices: data-field enums from wicket_get_schemas() and org type (organization only).
- Duplicate facets blocked.
- Note that show/hide of keyword, location, filters and Order by is a block setting.

**Acceptance criteria:**

- Configured facets appear in Refine Results in the same order with the given labels.

<a id="54"></a>

### 5.4 — Admin list table columns

**Depends on:** 1.2

src/Admin/ListColumns.php: add Type and Status columns to All Directories. No shortcode column.

**Acceptance criteria:**

- Columns show correct values; sortable by title and date as usual.

## 6. QA & registration

<a id="61"></a>

### 6.1 — Register the plugin in the QA suite and stack docs

**Depends on:** 1.1

Changes in qa/ and the stack root.

- qa/orchestration/component-map.json and qa/component-config.json.
- qa/composer.json: autoload-dev for Wicket\Directory\ and tests, test:unit:directory / coverage scripts.
- qa/pest.php: path helper, prefix map, unit-run detector, uses()->in('tests/Unit/Directory').
- qa/orchestration/run-pest-suite.php maps; qa/phpunit.xml source; qa/phpstan.neon paths.
- Stack root AGENTS.md component table.
- Local ~/.config/wicket-tools/config.toml init_dev entry (CLI defaults need a CLI release).

**Acceptance criteria:**

- wicket test runs the directory suite.
- phpstan scans the plugin.

<a id="62"></a>

### 6.2 — Unit tests for config, query and data classes

**Depends on:** 6.1

Pest tests in qa/tests/Unit/Directory/.

- ContactResolver: every truth-table branch for each record type.
- Query builders: each combination of eligibility, keyword, location, facets and sort -> expected Ransack array.
- RequestParams: sanitizing, unknown facet values rejected, page clamped, hidden-control params ignored.
- DirectoryConfig::sanitize: defaults and reset on type change.
- BlockAttributes: clamping and defaultSort fallback.

**Acceptance criteria:**

- All pass in wicket test.

<a id="63"></a>

### 6.3 — WordPress integration tests

**Depends on:** 6.1

qa/tests/WordPress tests with the API stubbed.

- CPT, meta and block are registered.
- Draft directory renders an empty string; published renders markup.
- Each block option works: every Hide toggle removes its control (and its GET param is ignored), resultsPerPage clamped to 1-50, invalid defaultSort falls back, collapseRefineOnMobile sets the initial state.

**Acceptance criteria:**

- All pass in wicket test.

## 7. Acceptance

<a id="71"></a>

### 7.1 — Theme compatibility check

**Depends on:** 3.8, 4.1

Manual checks.

1. Wicket theme (v2): cards, filters, search, pagination and chips use the theme's colours, spacing, radius and type.
2. Child theme overriding a few --color-\* / --spacing-\* tokens: directory follows.
3. Default WordPress theme (Twenty Twenty-Five): fallbacks give a readable layout.
4. Search row order [keyword][location][Search] with each Hide combination; keyword pre-fills after searching; Refine panel matches the listing block's filters.
5. Mobile/tablet: Refine collapse default, card stacking.

**Acceptance criteria:**

- Issues found are logged and fixed or ticketed.

<a id="72"></a>

### 7.2 — CHFA parity check: rebuild the org directory with the block

**Depends on:** 7.1, 5.3, 5.2

In the stack's local site, recreate CHFA's current organization directory as a directory config: active membership, orgmemberdir.optin opt-in, Business Types facet, eyebrow = business type, attribute/certification chips.

Place the block on a page and compare with the old child-theme template (member-directory.php): search, facets, sort, pagination, EN/FR, mobile collapse. Then switch the directory to Draft and confirm the page renders nothing and the editor shows a notice.

**Acceptance criteria:**

- Results and card content match the old template.
- Differences are documented and accepted or fixed.

<a id="73"></a>

### 7.3 — Documentation: README, AGENTS.md and plan updates

**Depends on:** 7.2

- README.md: what the plugin does, requirements, how to create a directory and place the block, theme overrides, hooks.
- AGENTS.md: remove "planned" markers as things land; update commands, architecture and hooks to match the code; add api-queries.md to the Documentation table.
- docs/engineering/mvp-plan.md: record any decisions that changed during the build.

**Acceptance criteria:**

- Docs match the shipped code.
