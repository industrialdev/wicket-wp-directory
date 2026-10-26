---
title: "MVP Tickets"
audience: [developer, agent]
updated: 2026-10-26
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
| 2.7 | [FacetOptions: facet choices from schemas and resource types](#27) | 1.3 | Done |
| 3.1 | [Block registration (server side) and BlockAttributes](#31) | 1.1 | Done |
| 3.2 | [DirectoryRenderer and TemplateLoader (single render path)](#32) | 3.1, 2.6, 2.7 | Done |
| 3.3 | [Search bar template (keyword + location)](#33) | 3.2 | Done |
| 3.4 | [Refine Results filters template](#34) | 3.2 | Done |
| 3.5 | [Results header, Order by and pagination](#35) | 3.2 | Done |
| 3.6 | [Individual card template](#36) | 3.2 | Done |
| 3.7 | [Organization card template](#37) | 3.2 | Done |
| 3.8 | [Directory CSS (Wicket theme tokens) and JS](#38) | 3.2 | Done |
| 4.1 | [Block editor sidebar, preview and build](#41) | 3.1, 3.2 | Done |
| 5.1 | [Admin meta box: Directory type and eligibility](#51) | 1.3 | Done |
| 5.2 | [Admin meta box: Card configuration](#52) | 5.1 | Done |
| 5.3 | [Admin meta box: Filters (facets)](#53) | 5.1, 2.7 | Done |
| 5.4 | [Admin list table columns](#54) | 1.2 | Done |
| 5.5 | [Admin edit screen: designer's layout](#55) | 5.1, 5.2, 5.3 | Done |
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
- `$facet_options` is `facet key => allowed values` (2.7 supplies it). A facet missing from the map accepts no values. Selected values are checked strictly against the allowed strings and de-duplicated. They aren't passed through `sanitize_text_field()` first: the allow-list match is the sanitizing, and `sanitize_text_field()` turned enum values such as `< 1` into `&lt; 1`, so they could never be selected (fixed in 2.7). A scalar `wd{ID}_{key}=x` is accepted as well as `[]`. Facets with nothing selected are left out of `facets`, so builders never send `''`.
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

**Outcome:** `src/Data/FacetOptions.php`, plus a per-language label transient in `EnumLabels` and a fix in `RequestParams`. The qa unit suite is wired for the plugin (the unit part of 6.1), with tests in `qa/tests/Unit/Directory/`: `FacetOptionsTest` and `EnumLabelsCacheTest`, 24 tests. Also checked with `wp eval-file` against the staging tenant. EN/FR org types ("Company" → "Compagnie") and `practice_details.experienceRange` (`< 1` → "Less than 1") come from the enums. `eligibility.org_types` narrows the org-type facet. A warm transient means neither `json_schemas` nor `resource_types` is requested. Notes for later tickets:
- **`(new FacetOptions())->for_config($config, $lang = null)`** returns `facet key => list<{value, label}>`, in config order, with each facet's options in enum order. Keys come from `RequestParams::facet_key()`. `$lang` defaults to `wicket_get_current_language()` and is normalised like the query (`QueryBuilder::language()`). `for_facet($facet, $config, $lang)` gives one facet's list (5.3's preview can use it). The constructor takes an optional `EnumLabels`; it defaults to `EnumLabels::shared()`.
- **3.2** builds the options once per render, then passes `FacetOptions::values($options)` to `RequestParams::from_request()` as `$facet_options` and the full options to 3.4. Values are always strings. Numeric enum keys come back as PHP int array keys from `EnumLabels`, and `FacetOptions` casts them.
- **3.4** should hide a facet whose list is `[]`. That happens for a field that isn't an enum, a schema key that no longer exists, an org-type facet whose eligible types are all unknown, or a failed load. The facet's heading is `$facet['label']`. An empty label needs a template fallback (e.g. "Organization type"). `FacetOptions` doesn't derive one from the schema.
- **Org-type facet.** Options follow the `resource_types` order. When `eligibility.org_types` is set, only those types are offered: the same intersection `OrganizationQueryBuilder` makes, so a visitor never sees a choice that can't match. Unknown eligible slugs are skipped. On an individual config it is always `[]`, and nothing is loaded.
- **Label transient.** `EnumLabels::shared()` (so `EntryMapper` and `FacetOptions` by default) keeps every resolved label map in one transient per language, `wicket_directory_labels_{LABELS_CACHE_FORMAT}_{lang}`. It holds `schema_key|field` and `org_types` maps, is read once per language per request and written only on a miss. While it's warm, neither source loads, so a cached results page no longer makes a `json_schemas` request. The TTL is `LABELS_CACHE_TTL` (3600), changed with `wicket_directory/labels_cache_ttl` (`$ttl, $lang`). A value of 0 or less disables reads and writes. Maps from a failed load (a thrown error, a `json_schemas` response with no data, or an empty org-type list) aren't stored, so the next request retries. A non-enum field's empty map is stored. Malformed entries are ignored and rebuilt. Schema edits in the MDP show after at most the TTL; nothing clears the transient on save, because directory saves don't change labels. `new EnumLabels()` without `persistent: true` behaves as before.
- **5.3** still has to list the *candidate* data fields (every enum field in `wicket_get_schemas()`). `FacetOptions` only resolves the options of facets that are already configured.
- **Tests.** The qa wiring (composer autoload and scripts, `pest.php` path helper, prefix map, bootstrap and `uses()`, `run-pest-suite.php` maps, `phpunit.xml` source, `package-map.json`, and `package-config.json` as `development`) is in the qa working tree. `development` keeps the package out of `composer test:unit`, so run `composer test:unit:directory` in the PHP container. `wicket test unit:directory` answers `unknown component "directory"` until the CLI registry has the package. Left for 6.1:
  - the CLI registry;
  - the stack root docs;
  - **phpstan.** Adding the plugin to `qa/phpstan.neon` gives 20 errors in existing code. Eighteen are false positives from `EntryMapper`'s string-keyed spread into the entry constructors. The other two are `Entry::with()`'s unsafe `new static()` and `WICKET_DIRECTORY_BASENAME`, which `phpstan-bootstrap.php` doesn't define. The plugin was therefore left out of phpstan.
- **qa quirk.** `uses(...)->in('tests/Unit/X')` in `qa/pest.php` resolves against `qa/tests/`, so it never applies (Portus's base class doesn't load either). The Directory line uses `__DIR__ . '/tests/Unit/Directory'`. The other packages weren't changed.

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

**Outcome:** `blocks/directory/block.json`, `src/Block/DirectoryBlock.php` and `src/Block/BlockAttributes.php`, booted from `Plugin::plugin_setup()`. Unit tests: `qa/tests/Unit/Directory/BlockAttributesTest.pest.php` and `DirectoryBlockTest.pest.php`. Checked with `wp eval` on the stack: the block registers in category `wicket` with its eight attributes, the editor script resolves with its dependencies, and `render_block()` returns `''`. Not checked in a browser. Notes for later tickets:
- **`BlockAttributes::from_array($attributes)`** gives `directory_id` (0 or more), `default_sort`, `results_per_page` (clamped to 1–50; a non-integer gives 6) and the five toggles as real booleans. Numeric strings are accepted for the integers, because REST and hand-written block markup can send them. The default sort needs the directory type, so `default_sort` holds the editor's sanitized token as given, and **`sort(DirectoryType $type)`** resolves it: an unset, unknown or other-type token gives the type's default. Pass `$attributes->sort($config->type)` and the `hide_*` flags to `RequestParams::from_request()`.
- **`DirectoryBlock::render()`** sanitizes the attributes and returns `''`. 3.2 wires in `DirectoryRenderer` here. The flow is render callback → `BlockAttributes` → renderer, so the renderer can take a `BlockAttributes` (with `directory_id`) instead of the plan's `render(int $dir_id, array $block_attrs)`.
- **Block category.** The base plugin adds the `wicket` category only when ACF is active. `DirectoryBlock::add_category()` adds it when no other plugin has.
- **Editor script (interim).** The inserter only lists blocks that are registered in JS, so `blocks/directory/index.js` is a plain-JS (no build) `registerBlockType` with a `ServerSideRender` canvas, and `index.asset.php` lists its dependencies. 4.1 replaces both with the `@wordpress/scripts` source and build, and changes the path in `DirectoryBlock::register_block()` from `blocks/directory` to `build/directory`.
- **Style.** `block.json` `style` is `file:../../assets/css/directory.css`. The path stays the same from `build/directory`. The file doesn't exist yet (3.8), so WordPress registers the handle without a source.

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

**Outcome:** `src/Render/DirectoryRenderer.php`, `src/Render/TemplateLoader.php`, `templates/directory.php` and `templates/parts/notice.php`. `DirectoryBlock::render()` now calls the renderer. Unit tests: `qa/tests/Unit/Directory/DirectoryRendererTest.pest.php` and `TemplateLoaderTest.pest.php` (40 tests), plus one in `DirectoryBlockTest`; the Directory bootstrap gained a minimal `WP_Post`. Checked with `wp eval` on the stack: the published staging directory renders the wrapper with 2 items for `resultsPerPage: 2` (empty `<li>`s until 3.6), and a missing ID in a REST request as an admin renders the real `alert` notice. Not checked in a browser. Notes for later tickets:
- **`(new DirectoryRenderer())->render(BlockAttributes $attributes)`** instead of the plan's `render(int $dir_id, array $block_attrs)`. The constructor takes optional `DirectoryRepository`, `EntryMapper`, `FacetOptions`, `TemplateLoader` and an `is_preview` callable, so tests pass fakes.
- **States.** No directory picked, a missing/trashed/non-directory post, and any status other than `publish` render `''` for visitors and make no API or label calls. In the editor preview each gets its own translated message through `parts/notice.php` (the base `alert` component, content passed through `esc_html()`). A `Throwable` while rendering is logged (`Wicket()->log()->error()`, `source` `wicket-directory`, `directory_id`), output buffers are unwound, and visitors get `''`.
- **Editor preview** = a REST request (`wp_is_serving_rest_request()`, else `REST_REQUEST`) by a user with `edit_posts`. ServerSideRender's block-renderer endpoint needs that capability, so visitors never see notices. Anonymous REST renders (e.g. `content.rendered`) behave like the frontend.
- **Order.** Load the post → `FacetOptions::for_config()` (skipped when `hideFilters` is on, so no label load) → `RequestParams::from_request()` with `$attributes->sort($type)` and the hide flags → `before_render`, then `before_render_{slug}` (only for a non-empty slug), with `($config, $params, $directory)` → `DirectoryRepository::fetch()` → `EntryMapper::map()` → `directory.php`. The actions run inside the output buffer, so anything a callback prints comes before the directory. They don't fire for a directory that doesn't render.
- **`wicket_directory/card_template`** runs once per render with `($file, $config, $directory)`. `$file` is `cards/card-{type}` as `TemplateLoader` located it (theme override, else plugin), or `''` while 3.6/3.7 haven't added the plugin cards. A return that isn't an existing file keeps the located one. There is no `_{slug}` variant: the post is in the args.
- **`TemplateLoader`.** `locate($name)` takes names like `directory`, `parts/search-bar`, `cards/card-individual` (lowercase, digits, `-`, `_`, `/`; anything else gives `''`, so no path traversal), asks `locate_template(['wicket-directory/{name}.php'])` (child, then parent theme), then `templates/{name}.php`. `render()` returns a string, `load()` prints, and `render_file()` / `load_file()` take an absolute path. A template sees only `$args` (included from a static closure). A missing template prints nothing, so `directory.php` already calls `parts/search-bar`, `filters`, `results-header`, `no-results` and `pagination`, and they appear as 3.3–3.5 add them.
- **`directory.php` args** (also passed to every part, and to the card with `entry` added): `directory` (`WP_Post`), `config`, `attributes` (`BlockAttributes`), `params` (`RequestParams`), `page` (`ResultPage`), `entries`, `facet_options` (full `{value, label}` lists for 3.4), `show_filters` (some facet has choices; `false` when `hideFilters` is on), `card_template`, `hidden_fields`, `html_id` (`wicket-directory-{ID}`), `is_preview`, `lang` and `templates` (the loader). The docblock in `directory.php` lists the types.
- **Markup.** `div#wicket-directory-{ID}.wicket-directory.wicket-directory--{type}` (+ `--has-filters`) → `form.wicket-directory__form[method=get]` (no `action`, so it submits to the current URL) → search bar → `.wicket-directory__body` → filters + `.wicket-directory__results` (results header, `ul.wicket-directory__list > li.wicket-directory__item` per card or `no-results`, then pagination). 3.8 styles these classes. 3.5's no-results part covers both the empty and the `unavailable` page; `directory.php` loads it whenever there are no entries.
- **Hidden fields.** A GET form replaces the whole query string, so the form re-sends every current query var that doesn't start with this directory's `wd{ID}_` prefix (another directory's params, `page_id`, …) as escaped hidden inputs. Arrays become `name[key]`, up to three levels. This directory's own params are left out, so a new search drops `wd{ID}_pg` and starts on page 1. 3.3–3.5 add their own fields. The editor preview gets no hidden fields (the REST request's own vars aren't the page's).
- **Same directory twice on one page** shares its `wd{ID}_` params and its `html_id`. Not handled in the MVP.

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

**Outcome:** `templates/parts/search-bar.php` and the first rules in `assets/css/directory.css` (the search row only). Unit tests: `qa/tests/Unit/Directory/SearchBarTest.pest.php` (9 tests); `DirectoryRendererTest` now checks the row is in the full render, and its `get_component` stub returns markup when `$output` is `false`. Checked with `render_block()` on the stack (Wicket theme): each Hide combination gives the expected fields and one Search button, a `<script>` keyword comes out escaped, and the block's `wicket-directory-style` handle now loads `directory.css`. Not checked in a browser. Notes for later tickets:
- **Markup.** `div.wicket-directory__search[role=search]` → `div.wicket-directory__keyword` (the `search-form` component, which holds the keyword field and its Search button) → `div.wicket-directory__location.component-search-form.form` (`icon` `fa-solid fa-location-dot` + `input.wicket-directory__location-input`, `name`/`id` `wd{ID}_location`, `aria-label` "Location"). With Hide Keyword Search on, a `button` component (`.wicket-directory__submit`, `type=submit`, variant from the base `wicket_search_form_button_variant` filter, like `search-form`'s own button) comes last instead. With both searches hidden the part renders nothing.
- **Keyword pre-fill.** `search-form` reads `$_GET[url-param]` and prints it unescaped. The part sets `$_GET[wd{ID}_keyword]` to `esc_attr($params->keyword)` (the sanitized value, `''` in the editor preview or when hidden) for that one call, then restores the request's own value, or unsets the key if there wasn't one. A theme override of this part must keep that swap. `classes` is passed as `[]`, so the component adds no Tailwind layout classes.
- **Placeholders.** Individual "Search by name, member ID", organization "Search by organization", location "Search by city, province or state" (matches city or state/province name, per the query builders).
- **Order and focus.** `directory.css` unwraps `.wicket-directory__keyword`, its `.component-search-form` and every `.wicket-base-plugin` wrapper (the base plugin wraps each component on non-Wicket themes) with `display: contents`, so the keyword field, location field and button are flex items; `order` puts the location second and `.component-button` last. Tab order follows the DOM: keyword, Search, location. Enter in either field submits the form.
- **3.5:** with both searches hidden there is no Search button, so if Hide Filters is also on, Order by has no submit. 3.5 needs its own submit (or 3.8's JS) for that case.
- **3.8:** fold the `--wd-search-*` properties into the token layer. The location icon is placed by our CSS (the keyword icon by `search-form`'s Tailwind classes, which only exist on the Wicket theme); check both on a non-Wicket theme. The location wrapper's mobile padding from the theme's `.component-search-form` rule is reset to `0`, because the keyword wrapper is `display: contents` and gets none.

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

**Outcome:** `templates/parts/filters.php`, the panel's rules in `assets/css/directory.css`, and a new `clear_url` arg from `DirectoryRenderer`. Unit tests: `qa/tests/Unit/Directory/FiltersTest.pest.php` (12 tests), plus 3 in `DirectoryRendererTest` (its `get_component` stub now records args, and it stubs `remove_query_arg`). Checked with `render_block()` on the stack against the staging tenant's `practice_details` enums: the visitor's choices are pre-checked, a value that isn't in the enum is dropped, the 10-option facet shows five and then See more, and Clear All on `/?page_id=5&wd1818_…&wd1818_pg=2` links to `/?page_id=5`. Also checked in headless Chromium (Playwright) on the stack with the Wicket theme, with no JS errors. On desktop: See more / See less (with `aria-expanded`), the live count going 1 → 2 → 1 → hidden, each facet's collapse, and Apply Filters followed by Clear All. On mobile, `collapseRefineOnMobile` starts the panel collapsed. The toggle button, a tap on the bar, Enter and Space each toggle it once. The panel collapses at 1023px and is open at 1024px. The theme's `filter-form` styles apply, including the highlighted open bar on mobile. Notes for later tickets:
- **Markup.** `div#wicket-directory-{ID}-filters.wicket-directory__filters.component-filter-form` → `.component-filter-form__top` (the `icon` `fa-solid fa-sliders`, the "Refine Results" heading, and a `.wicket-directory__filters-toggle` button with plus/minus icons) → `#…-filters-group.component-filter-form__group` → one `.component-filter-form__filter-section.wicket-directory__facet` per facet (a `.wicket-directory__facet-toggle` button holding the label, the `(n)` count and the carets; then `div[role=group]#…-filters-{n}` → `ul.wicket-directory__options > li.wicket-directory__option` with a checkbox and label, and See more / See less) → `.wicket-directory__filters-actions` (Apply Filters `button` component, `type=submit`, and Clear All `button` with `a_tag`). The `component-filter-form__*` classes are the base `filter-form`'s, so the theme's `filter-form.scss` styles the panel. No Tailwind classes are used, and the base's hard-coded `#232A31` filter SVG is replaced by the icon.
- **Fields.** Each checkbox is `wd{ID}_{facet_key}[]` with the option's value, inside the directory form. Apply Filters submits it together with the keyword, location and (with 3.5) Order by, so selections persist across sorts. A new search drops `wd{ID}_pg`. 3.5's page links must keep the current query (facets included).
- **Which facets.** Facets are rendered in config order, and a facet with no choices is skipped, so the part renders nothing if none has any (`directory.php` already skips it then, and when Hide Filters is on). An empty label falls back to "Organization type" for the org-type facet and to the raw field key for a data field (e.g. `breedSpecialties`). 5.3 should require a label, or fill one in.
- **Clear All** links to `clear_url`: the current `REQUEST_URI` with every query var starting `wd{ID}_` removed (`remove_query_arg()`), so it resets this directory's search, filters, sort and page, and keeps other directories' params and `page_id`. In the editor preview it is `#`. The part passes it through `esc_url()`, because `button` doesn't escape its link.
- **Alpine.** The panel's `x-data` is `showFilters: true`, or with `collapseRefineOnMobile`, `!window.matchMedia('(max-width: 1023.98px)').matches` (the theme's `lg` breakpoint). The whole top bar toggles it. The toggle button is there for keyboard and screen readers (`aria-expanded`, `aria-controls`, labelled by the heading); clicks on it bubble to the bar. Each facet has `open`, `showAll` and `selectedItemsCount`, like the base. The count is also printed by the server, and `showAll` starts `true` when a selected option is behind See more. Without Alpine the panel is open, every option shows, and the toggles and See more stay hidden by `x-cloak`. `directory.css` adds `.wicket-directory [x-cloak]` for non-Wicket themes.
- **CSS.** Below 1024px, `.wicket-directory__filters--collapsed` hides the group. From 1024px the toggle button is hidden and the panel is always open. New tokens: `--wd-filters-gap` (`--spacing-100`) and `--wd-filters-option-gap` (`--spacing-150`). The `.wicket-directory .wicket-directory__…-toggle` / `__see-more` reset strips theme button chrome.
- **3.8:** fold the `--wd-filters-*` properties into the token layer, add the two-column `.wicket-directory__body` layout (`--layout-sidebar`), and check the panel on a non-Wicket theme. The plan's `directory.js` collapse default isn't needed any more (Alpine's `x-data` does it), unless 3.8 wants to avoid the brief open state before Alpine starts on a collapsed mobile panel. Clicking the top bar on desktop flips `showFilters` with no visible effect; harmless unless the window is then narrowed.

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

**Outcome:** `templates/parts/results-header.php`, `templates/parts/pagination.php`, `templates/parts/no-results.php` and their rules in `assets/css/directory.css`. No renderer change: `directory.php` already loaded the three parts. Unit tests: `qa/tests/Unit/Directory/ResultsHeaderTest.pest.php` (8), `PaginationTest.pest.php` (6) and `NoResultsTest.pest.php` (5), plus 3 in `DirectoryRendererTest` (its fake API's page meta is now `$this->pageMeta`, and it stubs `number_format_i18n`). Checked with `render_block()` on the stack (Wicket child theme) against directory 1818: the count, the selected sort, and the theme's page links keep `page_id`, keyword, location, sort, array facet params and another directory's `wd7_pg`; core `paginate_links()` with the part's args gives the same URLs; a keyword that matches nobody gives the no-results message with its hint and no header or page links. The core fallback branch was only unit tested, because the theme's helper can't be undefined in one process. Not checked in a browser. Notes for later tickets:
- **Results header.** `div.wicket-directory__results-header` → `p.wicket-directory__count` ("Displaying X–Y of N results", `_n()` on the total, numbers through `number_format_i18n()`) → `div.wicket-directory__sort.form` (label "Order by" and `select#wd{ID}_sort.wicket-directory__sort-select`, `name` `wd{ID}_sort`, options from `DirectoryType::sort_options()` with `RequestParams::$sort` selected). The header isn't rendered for an empty or unavailable page. Hide Order By drops the select and keeps the count; the block's default sort still applies.
- **Sort submit.** The select is a field of the directory form, so a sort keeps the search and filters, and drops `wd{ID}_pg` (back to page 1). `onchange="this.form.submit()"`, like the theme's listing block, so it works without Alpine and covers 3.3's case where no Search or Apply button exists. No-JS visitors get a Sort `button` (`secondary`, `type=submit`) inside `<noscript>`.
- **Pagination.** `div.wicket-directory__pagination`, only when `total_pages` is 2 or more. Args are `base` = `add_query_arg('wd{ID}_pg', '%#%')` on the current URL, `format` `''`, `total` and `current` from `ResultPage` (so a page past the end shows the last page's links). `paginate_links()` adds the URL's other query vars itself. With the theme, `wicket_pagination()` gets exactly those args: don't pass `type`, because its helper implodes an array and breaks on `plain`. Without it, `paginate_links()` (`type` `plain`, our own translated Previous / Next with Font Awesome arrows) is wrapped in the same `nav.wicket-pagination[aria-label]`, so `pagination.scss` matches.
- **Editor preview.** A `paginate_links` filter, added only around the call, points every link at `#` (the REST request's URL isn't the page's), like `clear_url`. Its callback isn't a `static` closure, because Brain Monkey can't name one in `add_filter()`.
- **No results.** `div.wicket-directory__no-results` with "No results found." and, when the visitor has a keyword, location or facet, a hint to change the search. An `unavailable` page gets `div.wicket-directory__unavailable` with the `alert` component instead (escaped content).
- **CSS.** New tokens `--wd-results-gap` (`--spacing-200`) and `--wd-sort-gap` (`--spacing-150`). The header is a wrapping flex row (count left, Order by right).
- **4.1:** wrap the `ServerSideRender` canvas in `Disabled`. Otherwise Order by's `onchange`, and 3.3/3.4's Search and Apply Filters, submit the directory form inside the editor.
- **3.8:** fold the `--wd-results-*` / `--wd-sort-*` properties into the token layer, and style `.wicket-pagination` on non-Wicket themes (it has no rules there). The theme's own prev/next text carries Tailwind classes; that's the theme's markup, not ours.

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

**Outcome:** `templates/cards/card-individual.php` and the card layout rules in `assets/css/directory.css`. No renderer change: `DirectoryRenderer` already located `cards/card-{type}` and `directory.php` already loaded it per entry. Unit tests: `qa/tests/Unit/Directory/CardIndividualTest.pest.php` (25 tests). They cover every field, escaping, the args passed to each component, each empty row hidden, several picked contacts, and the name, job title and member ID toggles mapped through `EntryMapper` and then rendered. Checked with `render_block()` on the stack against directory 1818 (staging people, every toggle switched on in memory): member IDs, tiers, emails and phones render, and empty rows are hidden. Also checked in headless Chromium (Playwright) on the Wicket theme, with a `wicket_directory/entry` callback that filled in a photo, eyebrow, post-nominal, job title, two addresses, a website and chips. The theme's card-listing styles apply. At 1280px the photo and the three columns sit side by side; at 390px they stack. No JS errors. Notes for later tickets:
- **Markup.** `article.component-card-listing.wicket-directory-card.wicket-directory-card--individual` → `.wicket-directory-card__media > img.wicket-directory-card__image` (`loading="lazy"`, `decoding="async"`, `alt=""`, because the name follows) → `.component-card-listing__content.wicket-directory-card__content`, which holds:
  - the eyebrow (`.component-card-listing__content-type`);
  - `h3.component-card-listing__title.wicket-directory-card__title`;
  - `p.wicket-directory-card__job-title`;
  - `.wicket-directory-card__columns`: `dl.wicket-directory-card__details` (Member ID, Membership), `.wicket-directory-card__addresses` (one `icon` `fa-regular fa-location-dot` plus `<address>` per address, lines joined with `<br>`) and `ul.wicket-directory-card__contacts` (emails, then phones);
  - `.wicket-directory-card__actions` (one Visit Website `button` per website);
  - `.card__topics.wicket-directory-card__tags` (one `tag` per chip). `.card__topics` gets the theme's chip row styles from `card-listing.scss`.
  
  No Tailwind classes. 3.7 should reuse the `wicket-directory-card__*` blocks so one set of rules styles both cards.
- **Title.** `name`, plus the post-nominal after a comma when it is set (`%1$s, %2$s`, translatable: "Ada Lovelace, FRS"). The salutation, middle name and suffix are already in `name` (2.6), so the template never reads the toggles. The card is an `h3`; a theme override can change the level.
- **Components.** Each value is escaped first, because these components print their args as given:
  - Email: `link` with `esc_url('mailto:…')` and `icon_start` `fa-regular fa-envelope` (screen-reader text "Email").
  - Phone: `link` to the entry's `uri` with `fa-regular fa-phone`. The text is `%1$s ext. %2$s` when there is an extension. A phone whose `uri` a filter emptied is shown as plain text with the icon.
  - Website: `button` with `a_tag`, `variant` `secondary`, `suffix_icon` `fa-solid fa-arrow-up-right-from-square`, `link_target` `_blank` and `rel="noopener"`. The label is `card.website_label`, or "Visit Website".
  - Chips: `tag` gets the **raw** label, because it calls `esc_html()` itself. Passing an escaped label would double-encode `&`.
- **Empty rows.** Each row, column and wrapper renders only when its value is non-empty. An entry with only a name renders just the title.
- **CSS.** `.wicket-directory__list` is a grid with `--wd-list-gap`. Each `li.wicket-directory__item` is an inline-size container named `wd-card`. The card stacks by default, and at `@container wd-card (min-width: 36rem)` the media and content, and then the columns, become rows. `.wicket-directory .wicket-directory-card` overrides the theme's viewport-based `md:flex-row`, so a narrow card in a wide page still stacks. New tokens: `--wd-list-gap`, `--wd-card-gap` (`--spacing-200`), `--wd-card-row-gap` (`--spacing-100`), `--wd-card-image-size` (`--spacing-1000`, a round 5rem photo), `--wd-card-text-muted` (`--color-text-content-secondary`) and `--wd-card-label-size` (`--font-size-label-md`).
- **3.8:** fold the `--wd-card-*` properties into the token layer. Give the card its own chrome (background, border, radius, padding, shadow) on non-Wicket themes, where `component-card-listing` has no rules. Check the chips there too: `tag` gets Tailwind classes without `WICKET_WP_THEME_V2`.

<a id="37"></a>

### 3.7 — Organization card template

**Depends on:** 3.2

templates/cards/card-organization.php.

- Same structure as the individual card.
- Toggles: org type, description with Show more / Show less (Alpine), member ID, tier, address (format), email, phone, website, logo, eyebrow and tag chips.
- Organization Contact is out of scope (phase 2).

**Acceptance criteria:**

- Matches the current CHFA org card content (business type eyebrow, description, contact, website, attribute/certification chips).

**Outcome:** `templates/cards/card-organization.php` and its rules in `assets/css/directory.css`. No renderer or mapper change: `DirectoryRenderer` already located `cards/card-{type}`, and 2.6 already maps the org type, description and logo. Unit tests: `qa/tests/Unit/Directory/CardOrganizationTest.pest.php` (24 tests). They cover every field, escaping, the args passed to each component, the description's short and long forms, each empty row hidden, several picked contacts, and the org type, description and member ID toggles mapped through `EntryMapper` and then rendered. Checked with `wp eval` on the stack: directory 1818's config was swapped in memory for an organization one with every toggle on. Five staging orgs render with their org type labels (e.g. "Task Force"), member IDs, addresses and phones. A `wicket_directory/entry` callback added a logo, an eyebrow, chips and descriptions: the long description collapses to its first 40 words with Show more, the short one shows in full with no toggle, line breaks become `<br>`, and nothing is double-encoded. Staging orgs have no descriptions of their own. Not checked in a browser, so the Alpine toggle is unit tested only (it follows 3.4's See more pattern). Notes for later tickets:
- **Markup.** The same structure as the individual card, with `wicket-directory-card--organization`:
  - the logo is `img.wicket-directory-card__image.wicket-directory-card__image--logo` (`alt=""`, lazy);
  - `p.wicket-directory-card__org-type` (the org type's label) takes the job title's place under the `h3`;
  - `div.component-card-listing__excerpt.wicket-directory-card__description` comes before the columns.
  
  The details, addresses, contacts, Visit Website and chips are the individual card's markup, components and args. There is no post-nominal: the title is the name.
- **Description.** Plain text, escaped, line breaks through `nl2br()`. Up to 40 words (`wp_trim_words()`, which counts characters for languages that don't split words with spaces) shows in full with no Alpine. A longer one gets `x-data="{ expanded: false }"`:
  - `p.wicket-directory-card__description-excerpt` holds the first 40 words and `…` (translatable `%s…`), with `x-show="!expanded" x-cloak`;
  - `p.wicket-directory-card__description-text` holds the full text, with `x-show="expanded"`;
  - `button.wicket-directory-card__description-toggle` (Show more / Show less, `x-cloak`, `aria-expanded`) controls the wrapper `#{html_id}-{entry id}-description`. The entry ID goes through `sanitize_key()`.
  
  Without Alpine the full text shows and the excerpt and toggle stay hidden. With Alpine the full text shows briefly before Alpine starts, as with 3.4's panel. The word count is fixed in the template; a theme override can change it.
- **Eyebrow and chips.** CHFA's business type label and attribute/certification pills are the card config's `eyebrow` and `tag_chips` data fields. 7.2 sets them up.
- **CSS.** The org type shares the job title rule. The logo keeps its shape: `--wd-card-image-size` high, up to twice as wide, `object-fit: contain`, no rounding. The description's paragraphs have no margins, and the toggle is a bare, underlined button.
- **3.8:** fold the toggle's reset into the shared bare-button rule, and check the logo and toggle on a non-Wicket theme.

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

**Outcome:** `assets/css/directory.css` rebuilt on one `--wd-*` layer, `blocks/directory/block.json` loads it as `viewStyle` + `editorStyle`, `DirectoryBlock::enqueue_style()`, a `wicket_theme` renderer arg with a `wicket-directory--standalone` modifier, and a server-printed collapsed Refine panel. **No `assets/js/directory.js`:** Alpine's `x-data` already sets the mobile collapse default (3.4), and the brief open state before Alpine starts is fixed in CSS instead (below), so a script had nothing left to do. Alpine comes from the theme, or from the base plugin's `wicket-plugin-alpine-script`, which it enqueues on every frontend page of a non-Wicket theme, so the directory declares no dependency. Unit tests: `qa/tests/Unit/Directory/DirectoryCssTest.pest.php` (5, a guard on the token rules), plus 4 in `DirectoryBlockTest` (5 with datasets), 2 in `FiltersTest` and 1 in `DirectoryRendererTest`; the suite is 227 tests, all passing. Checked in headless Chromium (Playwright) with markup from `render_block()` on the stack (directory 1818 with an in-memory organization config and the org-type facet; nothing written to the DB): on the Wicket child theme's page CSS, and on a bare page with only the base plugin's non-Wicket CSS and Alpine (markup rendered with `--skip-themes`), in light and dark, at 1440/1280 and 390px, and with JS off. No JS errors. Notes for later tickets:
- **Loading.** Classic themes enqueue every block's `style` on every page (`wp_enqueue_registered_block_scripts_and_styles()` unless assets load on demand), so `style` became `viewStyle` (enqueued only when the block renders) and `editorStyle` (the editor iframe). A view style enqueued during render prints in the footer on a classic theme, so `enqueue_style()` (on `wp_enqueue_scripts`) enqueues `generate_block_asset_handle('wicket/directory', 'viewStyle')` (`wicket-directory-view-style`) in the `<head>` when `is_singular()` and the queried post `has_block()`. A block in a widget, synced pattern or template still gets the footer load. `build/directory` (4.1) keeps the same `../../assets` paths.
- **Token layer.** The first `.wicket-directory {}` rule is the only place that reads theme tokens or holds literal values: colours (`--wd-text`, `--wd-text-muted`, `--wd-accent`, `--wd-interactive`, `--wd-interactive-bg`, `--wd-interactive-text`, `--wd-card-bg`, `--wd-card-border`, `--wd-panel-bg`, `--wd-divider`), shape (`--wd-border-width`, `--wd-radius`, `--wd-radius-sm`, `--wd-shadow`, `--wd-focus-ring`, `--wd-focus-outline`), spacing (`--wd-space-xs|sm|md|lg|xl` = `--spacing-50|100|150|200|400`, `--wd-icon-space`, `--wd-image-size`, `--wd-sidebar` = `--layout-sidebar`) and type (`--wd-font-size-title`, `--wd-font-size-label`, `--wd-letter-spacing`). The per-part properties from 3.3–3.7 (`--wd-search-*`, `--wd-filters-*`, `--wd-results-*`, `--wd-sort-*`, `--wd-list-gap`, `--wd-card-*`) are gone. Every other rule uses only `--wd-*`. `DirectoryCssTest` enforces this: theme tokens only in the layer and each with a fallback, every `--wd-*` used is defined, and no hex/`rgb()`/`hsl()` or `px`/`rem`/`em` outside the layer except in `@media`/`@container` conditions. Checked: overriding `--spacing-200`, `--color-text-content-secondary` and `--layout-sidebar` on `:root` changes the list gap, the org-type colour and the sidebar on both themes; `--color-bg-card` changes the standalone card (the Wicket theme's card uses its own `--bg-card`).
- **Layout.** `.wicket-directory` is an inline-size container `wd-directory`. The form and `.wicket-directory__body` are grids. At `@container wd-directory (min-width: 64rem)` a directory with filters puts the panel in a `--wd-sidebar` column beside the results. 64rem needs at least a 1024px viewport, so the panel never sits in a column while it can collapse; between 1024px and 64rem plus the page's padding it is open above the results.
- **Mobile collapse without the flash.** With `collapseRefineOnMobile` the panel is printed with `wicket-directory__filters--collapsed` (not in the editor preview, which runs no Alpine), so below 1024px it starts collapsed before Alpine runs; Alpine's `:class` then owns the class. `@media (scripting: none)` sets the group back to `display: revert`, so a visitor without JS still gets the open panel. If JS runs but Alpine never loads, a panel printed collapsed stays collapsed on mobile (the toggle is `x-cloak`); both Wicket themes and the base plugin always load Alpine.
- **Focus ring and bare buttons.** One reset for the bare buttons (`__filters-toggle`, `__facet-toggle`, `__see-more`, `-card__description-toggle`), and `:focus-visible` on those and the facet checkboxes draws `--wd-focus-ring` (`--box-shadow-focus`) plus a transparent outline for forced-colours mode.
- **Standalone.** `DirectoryRenderer` passes `wicket_theme` (`is_wicket_theme_active()`, `false` when the base helper is missing), and `directory.php` adds `wicket-directory--standalone` without it. Those rules give the Refine panel (panel background, dividers, bold headings, action row), the card (background, border, radius, padding, shadow, title size, eyebrow) and `.wicket-pagination` (centred wrapping row, current page filled) a look of their own. A panel or card sets its own text and link colours with its background, so it stays readable on a dark theme; page links inherit the page's text colour. The location and keyword icons, the chips and the logo/description toggle render correctly there.
- **7.1:** on a non-Wicket theme the base plugin's `button` styles read `--wp--preset--color--primary`, which a theme only has if its palette defines a `primary` slug (Twenty Twenty-Four/Five don't). Without it Search, Apply Filters and Visit Website have no fill and read as bold text. That's the base plugin's fallback CSS for every component, not this plugin's; log it there if it matters. Also check Twenty Twenty-Five itself: the harness was a bare page, not a real default theme.

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

**Outcome:** `package.json` (`@wordpress/scripts` 30, `build`/`start`/`lint:js`/`format`, Node pinned through Volta like `wicket-wp-admin-org-roster`), `blocks/directory/index.js` + `edit.js` built into `build/directory/` (`index.js`, `index.asset.php`, a copy of `block.json`), and the hand-written `blocks/directory/index.asset.php` removed. `DirectoryBlock::register_block()` registers from `build/directory`. `DirectoryPostType` adds a read-only `directory_type` REST field, and `DirectoryBlock::enqueue_editor_data()` gives the editor the sort options. `.php-cs-fixer.dist.php` excludes `build/`. Unit tests: 5 new in `qa/tests/Unit/Directory/DirectoryBlockTest.pest.php` (the build-path test replaces the `blocks/directory` one) and `DirectoryPostTypeTest.pest.php` (8 with datasets); the suite is 240 tests, all passing. `npm run build` and `npm run lint:js` pass. Checked on the stack with `wp eval`: the block registers from `build/directory` with the built script's dependencies and the same `directory.css`, the editor data prints before the script, and `GET /wp/v2/wicket_directory?status=publish&context=view` returns `directory_type` for a visitor and an admin (`edit` stays admin-only). Checked by hand in the block editor on the stack (directory 1818): the sidebar, the Default Sort options for an Individual directory, preview updates for Results, the hide toggles and the sort, Collapse disabled while Hide Filters is on, the placeholder on a new block, the unpublished warning with the "Unavailable directory" option after switching the directory to Draft, and no console errors. Notes for later tickets:
- **`directory_type` REST field.** `view` and `edit` contexts, read-only, `individual|organization` (`DirectoryConfig::from_post()`, so a directory with no config gives the default type). Published directory titles were already readable by anyone in `view`; the type is the only config value added. The sidebar requests `status: 'publish', per_page: -1, orderby: 'title', context: 'view', _fields: id,title,directory_type`.
- **Sort options** come from `DirectoryType::sort_options()` through `window.wicketDirectoryEditor.sortOptions` (`{type: [{value, label}]}`, default first), printed `before` the `wicket-directory-editor-script` handle on `enqueue_block_editor_assets`. Nothing about sort tokens is duplicated in JS. Default Sort Order is shown only once the selected directory's type is known. Picking another directory resets `defaultSort` to `''` (the type's default); an unknown or other-type token shows the default, as `BlockAttributes::sort()` renders it.
- **Number of Results** is a `RangeControl` (slider + number field, 1–50, clamped in JS too), not `NumberControl`: `__experimentalNumberControl` is still experimental and `@wordpress/no-unsafe-wp-apis` rejects it.
- **Unpublished selection.** When the saved `directoryId` isn't in the published list, the select keeps it as a disabled "Unavailable directory (ID n)" option and a warning `Notice` sits above it; the canvas preview shows the renderer's own notice. A failed list request shows an error `Notice` instead, not the warning.
- **Canvas.** `directoryId` 0 → `Placeholder` with the same directory select (or a "no published directories" message). Otherwise `ServerSideRender` inside `<Disabled>`, so links, the form and pagination in the preview can't navigate the editor. `Collapse Refine Results…` is disabled while `Hide Filters` is on.
- **Translations.** `block.json` has `textdomain` and the script depends on `wp-i18n`, so WordPress calls `wp_set_script_translations()` for the editor script itself. JS strings need a `wicket-directory-{locale}-{md5}.json` from `wp i18n make-json` when the plugin ships translations.
- **Lint.** `wp-scripts lint-js` needs `typescript` installed (its `@typescript-eslint` plugin fails to load without it), so it is a devDependency.

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

**Outcome:** `src/Admin/DirectoryMetaBoxes.php`, `src/Admin/MembershipTiers.php`, `src/Admin/views/directory.php` and `assets/js/admin.js`, registered from `Plugin::plugin_setup()` in the admin only. Unit tests: `qa/tests/Unit/Directory/DirectoryMetaBoxesTest.pest.php` and `MembershipTiersTest.pest.php`. Notes for later tickets:
- **Every box posts under `wicket_directory[...]`.** The save handler (`save_post_wicket_directory`) lays each posted section listed in `DirectoryMetaBoxes::SECTIONS` over the stored config, then calls `DirectoryConfig::sanitize($merged, $previous)`. A posted section replaces the stored one **whole**, so a box must render every field of its section. 5.2 adds `card` and 5.3 adds `facets` to `SECTIONS`; until then posted `card`/`facets` are ignored. Posted `cache_version` is always ignored.
- The save skips autosaves, revisions, a missing or invalid nonce (`_wicket_directory_nonce`, action `wicket_directory_save`), and users without `manage_options` or `edit_post`. REST and code writes don't go through it.
- Boolean checkboxes post a hidden `0` first. Checkbox lists post nothing when empty, which the whole-section replace saves as `[]`.
- Views are plain includes from `src/Admin/views/` (`$args`, like the frontend templates), not theme-overridable. Rows for one type carry `data-wd-type`; `assets/js/admin.js` (plain script, no build; linted by `npm run lint:js`) shows them for the selected radio and swaps `data-wd-type-changed` / `data-wd-type-unchanged` elements while the type differs from the saved one.
- Tiers come from `MembershipTiers` (`GET memberships`, 100 per page, up to 10 pages, no cache), filtered by `attributes.type`, labelled `name_{lang}` → `name` → `name_en` → slug, inactive ones marked, sorted by label. They're listed for the **saved** type only: a type change clears them on save, so the box says to save first. Org types come from `ResourceTypes` (since 5.2; one uncached `resource_types` request also serves the card's contact types).
- Saved tiers or org types that the MDP no longer lists, or that couldn't be loaded, still render checked as "Unknown tier (…)" / "Unknown type (…)", so a save never drops them silently. A failed tier load shows an error notice.
- The opt-in is three text inputs (schema key, field, value). A boolean stored value shows as `true` / `false`.
- Verified in the local stack against the staging MDP: 28 individual and 18 organization tiers, save, type-change reset and the nonce guard. The box hasn't been checked visually in a browser.

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

**Outcome:** a **Card** box (`render_card_box()`, view `src/Admin/views/card.php`) and `card` in `DirectoryMetaBoxes::SECTIONS`. New data sources `src/Admin/SchemaFields.php` and `src/Admin/ResourceTypes.php`. Unit tests: `qa/tests/Unit/Directory/DirectoryCardBoxTest.pest.php`, `SchemaFieldsTest.pest.php`, `ResourceTypesTest.pest.php`. Notes for later tickets:
- `DirectoryMetaBoxes::card_fields($type)` lists the type's settings with their admin labels: boolean `toggles`, single data-field `refs` (with the field kinds each accepts) and `contacts`; `tag_chips`, `address_format` and `website_label` have their own controls. A test checks that these cover every key of `DirectoryConfig`'s card defaults, so a new card key fails until it gets a control.
- Only the saved type's card is printed. While the type radio differs from the saved type, `admin.js` (now document-wide, so it reaches every directory box) swaps the card for a "save first" note: a type change resets the card anyway.
- **Data-field pickers post `"schema_key.field"`.** `DirectoryConfig::sanitize()` now accepts that string wherever it takes a data-field reference (card refs, tag chips, facets), as well as `{schema_key, field}`. Neither part may contain a dot, so the split is unambiguous. 5.3's facet pickers can post the same way.
- `SchemaFields` loads `GET json_schemas?include=json_schema_resources` once (no cache). A schema's resource type lives on its `json_schema_resources` (`people` / `organizations`), so a directory gets the schemas bound to its type plus unbound ones. Only top-level text/number fields (`text`) and lists of them (`list`) are offered, with an `enum` flag for 5.3; booleans, objects and repeaters are left out. Image pickers (profile image, logo) offer `text` fields only. Labels come from `ui:i18n` in the admin language, then English, then the schema `title`.
- Contact type choices come from `ResourceTypes::for(ContactResolver::RELATIONSHIPS[$field])`, plus `Any`. Website has no "only primary" box, and its hint says why.
- As in the Directory box, saved data fields and contact types the MDP no longer lists stay selected ("Unknown field (…)" in an "Unavailable" group, "Unknown type (…)"), and a failed load shows a notice.
- Tag chips are a checkbox list in schema order, so saving re-orders them to that order.
- Verified in the local stack against the staging MDP: both box types render, and posting the rendered form back through `wp_update_post` gives the same config with `cache_version` + 1. A unit test does the same round trip. The boxes haven't been checked visually in a browser.

<a id="53"></a>

### 5.3 — Admin meta box: Filters (facets)

**Depends on:** 5.1, 2.7

Facet repeater: add, remove, reorder; editable label.

- Choices: data-field enums from wicket_get_schemas() and org type (organization only).
- Duplicate facets blocked.
- Note that show/hide of keyword, location, filters and Order by is a block setting.

**Acceptance criteria:**

- Configured facets appear in Refine Results in the same order with the given labels.

**Outcome:** a **Filters** box (`render_filters_box()`, view `src/Admin/views/filters.php`), `facets` in `DirectoryMetaBoxes::SECTIONS`, and a facet repeater in `assets/js/admin.js`. Unit tests: `qa/tests/Unit/Directory/DirectoryFiltersBoxTest.pest.php` (including a save → Refine Results check of order and labels, and a render → submit → save round trip over all three boxes). Notes for later tickets:
- **Rows post `facets[i][source|schema_key|field|label]`** in DOM order. A hidden `facets` marker comes first, so removing every row still posts the section (PHP turns the `''` into an array once rows follow it) and saving clears the facets. `admin.js` renumbers the names after every add, move and remove.
- **Choices** are `DirectoryMetaBoxes::facet_choices($type, $schemas)`, keyed by `RequestParams::facet_key()`: Organization type first (organization directories only), then every `SchemaFields` field with `enum: true`, grouped by schema. Picker options already in the list are disabled, and `DirectoryConfig::sanitize()` still drops duplicates and an org-type facet on an individual directory.
- A new row starts with the field's readable label. The label input's placeholder is what visitors see if the label is emptied: the raw field key, or "Organization type" (the fallback in `templates/parts/filters.php`).
- A saved facet the picker no longer offers (not an enum any more, schema gone, or a failed load) stays in the list, marked "Not available", and saves unchanged. Visitors don't see it, because `FacetOptions` gives it no choices.
- Add, move and remove need `admin.js`; without it, existing labels can still be edited and saved. The box also says that showing keyword, location, filters and Order by is a block setting.
- Verified: in the local stack against the staging MDP (choices: org type, `organization_member_services.coreServices`, `organization_operational_information.yearsSinceFoundedRange`; round trip unchanged; removing all clears), and `admin.js` driven in jsdom against the real rendered markup (add, duplicate blocked, move, remove all, type switch). Not checked visually in WP admin: the browser pane can't reach the local stack.

<a id="54"></a>

### 5.4 — Admin list table columns

**Depends on:** 1.2

src/Admin/ListColumns.php: add Type and Status columns to All Directories. No shortcode column.

**Acceptance criteria:**

- Columns show correct values; sortable by title and date as usual.

**Outcome:** `src/Admin/ListColumns.php`, registered from `Plugin::plugin_setup()` in the admin only. Unit tests: `qa/tests/Unit/Directory/ListColumnsTest.pest.php`. Notes:
- Columns `wicket_directory_type` and `wicket_directory_status` go right after Title (at the end if a list has no title column). Type is `DirectoryType::label()` from `DirectoryConfig::from_post()`, so an unconfigured directory shows the default, Individual. Status is the registered status label; every status but `publish` adds "Not shown on the site", because only published directories render.
- Neither new column is sortable (the type lives inside the serialized config). Title and Date stay WordPress's own.
- Verified with `WP_Posts_List_Table` in the local stack: columns `cb, title, Type, Status, date`, sortable `title` and `date`, and correct cells for a published organization directory and a draft individual one.

<a id="55"></a>

### 5.5 — Admin edit screen: designer's layout

**Depends on:** 5.1, 5.2, 5.3

Rebuild the edit screen after the designer's "Member Directory Block" prototype (WP Admin Config → Edit Directory), within the MVP scope: no shortcode panel, no phase 2 parts (Advance Configurations, Developer tab, Groups, Organization Contact, live preview). The saved config doesn't change.

**Acceptance criteria:**

- The posted fields and the saved config are the same as before; a rendered box posts back unchanged.
- The box works without `admin.js` (every panel and option shown).

**Outcome:** the three boxes are now one **Directory settings** box (`render_box()`, `views/settings.php`): the Directory panels, then **Card View** and **Filters** tabs. `render_directory_box()`, `render_card_box()` and `render_filters_box()` print the parts; only `render_box()` prints the nonce. New `views/chip-picker.php`, `assets/css/admin.css` (enqueued with the script) and an `enter_title_here` placeholder for the directory name. Unit tests: the three box tests in `qa/tests/Unit/Directory/` were rewritten for the new markup and their round trips now go through `render_box()`. Notes:
- **Type** is a select (was radios). `admin.js` listens to it.
- **Chip pickers** (tiers, org types, tags): the "Add" menu is a `<details>` of the same checkboxes as before, so they post as before; the chips only mirror what's ticked.
- **Card View** is one checklist in `DirectoryMetaBoxes::card_groups($type)` order (a test checks it lists every card key once). A data-field item (profile image, logo, post-nominal, eyebrow) has an unposted checkbox: unticking it disables the picker, so the reference saves as off. Contact rules reveal format, type, flags and four hints (`data-wd-hint`: none, directory, primary, both; website has two), and the script shows the one that matches the flags.
- **Filters**: rows are a card list with arrow, field code, label and delete buttons; the add picker is a searchable popover (Enter adds the first match). Choices carry their code (`org_type` or `schema_key.field`).
- The tab bar stays `hidden` without the script; the last tab is remembered in `sessionStorage`.
- Not in the prototype but kept: Only list active members, the opt-in data field, Eyebrow, Tags, Description. In the prototype but not in the config (left out): Designation, Employer, Person ⟷ Org relationship types, the "Display Contact Details" parent toggle.
- Verified: unit tests; `admin.js` driven in jsdom against the box rendered by the local stack for a real directory (tabs, chips, reveals, hints, facet add/move/remove/search, type switch), and the untouched form, posted with the script running, sanitizes back to the stored config. Not checked visually in WP admin.

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
