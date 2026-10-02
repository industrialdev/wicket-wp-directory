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
| 1.3 | [DirectoryConfig value object and DirectoryType enum](#13) | 1.2 | To do |
| 2.1 | [RequestParams: parse and sanitize visitor GET parameters](#21) | 1.3 | To do |
| 2.2 | [OrganizationQueryBuilder for organizations/query](#22) | 0.1, 2.1 | To do |
| 2.3 | [PersonQueryBuilder for people/query](#23) | 0.1, 2.1 | To do |
| 2.4 | [DirectoryRepository: execute queries with caching and error handling](#24) | 2.2, 2.3 | To do |
| 2.5 | [ContactResolver: shared rules for address, email, phone and website](#25) | 0.1 | To do |
| 2.6 | [EntryMapper and entry DTOs](#26) | 2.4, 2.5 | To do |
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
- The meta REST schema lists only the top-level keys (`type`, `eligibility`, `card`, `facets`, `cache_version`), with nested objects left open (`additionalProperties: true`). Core strips any object property not declared in the schema, so a new top-level config key must be added there too. The `sanitize_callback` only coerces to an array; ticket 1.3 should route it through `DirectoryConfig::sanitize()`.

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

Methods: defaults(type), fromPost(post), sanitize(array). Changing type resets card and facet settings. cache_version increments on every save.

**Acceptance criteria:**

- sanitize() never trusts input: unknown keys dropped, types coerced, enums validated.
- Type change resets card/facets to that type's defaults.
- Covered by unit tests (see the unit tests ticket).

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

<a id="25"></a>

### 2.5 — ContactResolver: shared rules for address, email, phone and website

**Depends on:** 0.1

Add src/Data/ContactResolver.php, used for all four record types.

Rules (from the prototype):
1. Filter by type ("any" keeps all).
2. Neither only_directory nor only_primary set: first record flagged show-in-directory, else first primary, else nothing.
3. Either flag set: keep only records matching the set flags (AND when both); no fallback.
4. Empty result hides the row on the card (no "Not provided").

Fields (verified in 0.1): `type` and `consent_directory` on all four record types; `primary` on emails, phones and addresses. **web_addresses have no `primary`**: proposed fallback is the first record of the matching type, and only_primary doesn't apply (confirm before building).

**Acceptance criteria:**

- One implementation, four call sites.
- Every truth-table branch unit tested for each record type.

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
