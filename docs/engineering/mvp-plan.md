---
title: "MVP Plan"
audience: [developer, agent]
status: approved
updated: 2026-09-29
---

> Implementation plan for the MVP (Individual + Organization directories). Source files referenced below don't exist yet; they are the planned layout. Update this doc as decisions change.

# `wicket-wp-directory` MVP plan

## Context

Member and organization directories are currently built as page templates in each client's child theme (e.g. CHFA `wicket-child/page-templates/member-directory.php` + `includes/member-directory-core.php`). Every one hard-codes its API query, filters, and card markup. It also echoes raw `$_GET` values (XSS risk), has no caching, and has to be re-copied for each client.

The "Member Directory Block" prototype artifact defines the product we're aiming for. It covers configurable directories, card toggles, typed contact-info rules, facets, a Gutenberg block, and Draft/Publish. This plan builds an **MVP plugin** that replaces the theme templates.

**Agreed MVP scope**
- **Directory types:** Individual and Organization only. Groups and group rosters come in phase 2.
- **Cards:** the prototype's *Standard Configurations* only, meaning toggles rendered by fixed PHP card templates the theme can override. No `[col]/{{field}}` merge-tag editor.
- **Deferred:** Organization Contact (Membership Owner / Relationship Types) goes to phase 2. The Developer panel UI is also deferred, but the plugin still provides its hooks.
- **Draft directories don't render.** Public visitors see nothing. Editors see a notice in the block.
- **Search is server-rendered from GET parameters.** Links are shareable and it works without JS. Alpine handles only collapse and see-more.
- **WordPress-native stack, no HyperFields or ACF:**
  - a custom post type plus `register_post_meta`;
  - classic PHP meta boxes for the admin screen;
  - a native dynamic block (`block.json` + `render_callback`, editor built with `@wordpress/scripts`, preview via `ServerSideRender`).
- **Reuse `wicket-wp-base-plugin`** for everything it already does well.

## Why this native stack
- **CPT `wicket_directory`** gives Draft/Publish, revisions, trash, capabilities, and a list table with no extra code. With `show_in_rest` on, the block's directory picker can query published directories through `core-data`.
- **Classic meta boxes** are plain PHP forms with a nonce and sanitizers. There's no build step for the admin screen, and the config lives in one post-meta array registered with a REST schema. To use the classic edit screen, filter `use_block_editor_for_post_type` to return false for this CPT.
- **A native dynamic block** keeps one render path. The frontend `render_callback` and the editor's `ServerSideRender` call the same PHP renderer, which satisfies the prototype's rule that the preview must match the live page. The only JS build is the small block editor sidebar.

## What to reuse from the base plugin
- **API:**
  - `wicket_api_client()` (`includes/helpers/helper-init.php`), calling `$client->post('organizations/query?…')` and `post('people/query?…')`.
  - For working Ransack examples, see `wicket-wp-admin-org-roster/src/Services/MdpClient.php` (`searchPersons()`, around line 1580) and the CHFA core template.
- **Included records:** `\Wicket\ResponseHelper::getIncludedRelationship()` from the SDK, for `emails,phones,addresses,web_addresses`.
- **Query-string fix:** the `preg_replace('/\%5B\d+\%5D/','%5B%5D', http_build_query(...))` trick, so the API accepts `page[]`-style arrays.
- **Facet options:**
  - `wicket_get_schema()` and `wicket_get_schemas_options()` in `helper-schemas.php`, for data-field enums with i18n labels.
  - `wicket_get_resource_types()` for org types and address/phone/email/web types.
  - `wicket_get_org_types_list()` for org types.
- **Language:** `wicket_get_current_language()` in `helper-multilang.php`. Use it instead of `ICL_LANGUAGE_CODE`.
- **Logging:** `Wicket()->log()->error(..., ['source' => 'wicket-directory'])`.
- **Alpine:** the script handle `wicket-plugin-alpine-script`, used only as a dependency on non-Wicket themes (Wicket themes already ship Alpine).
- **Components:** reuse the base plugin's `includes/components` through `get_component()` wherever one fits. See "Theme & component compatibility" below for the full mapping. `filter-form` is not used, because it only handles WP taxonomies.
- **Don't call these in listing loops:**
  - `wicket_search_organizations()`: one membership request per row and no paging.
  - `wicket_get_resource_type_name_by_slug()`: re-fetches all resource types on every call.
  - `wicket_get_*_connections_by_id()`: the static cache ignores the argument, so every row gets the first record's data.

## Plugin layout (`src/web/app/plugins/wicket-wp-directory`)

Follow the conventions of `wicket-wp-financial-fields` and `wicket-wp-portus`:
- the `Requires Plugins: wicket-wp-base-plugin` header, PHP 8.3, and `declare(strict_types=1)`;
- a singleton `Plugin::get_instance()->plugin_setup()` on `plugins_loaded`, with a dependency check that shows an admin notice when the base plugin is missing;
- an activation guard;
- namespace `Wicket\Directory\` and text domain `wicket-directory`;
- `.php-cs-fixer.dist.php`, `.ci/pre-push` and `.gitignore` copied from portus;
- `AGENTS.md` with `CLAUDE.md` as a symlink to it.

```
wicket-wp-directory.php
composer.json  package.json (@wordpress/scripts)  README.md  AGENTS.md
src/
  Plugin.php
  PostType/DirectoryPostType.php     CPT + register_post_meta('_wicket_directory_config', show_in_rest schema)
  Config/DirectoryType.php           enum: Individual | Organization
  Config/DirectoryConfig.php         typed value object: defaults(), fromPost(), sanitize(array)
  Admin/DirectoryMetaBoxes.php       meta boxes + save (nonce, manage_options) ; views in src/Admin/views/*.php
  Admin/ListColumns.php              Type and Status columns
  Query/RequestParams.php            parse + sanitize GET (namespaced per directory), clamp page
  Query/OrganizationQueryBuilder.php build Ransack filter/sort/page for organizations/query
  Query/PersonQueryBuilder.php       same for people/query
  Api/DirectoryRepository.php        execute query, transient cache, error logging → ResultPage
  Data/ContactResolver.php           shared typed-record resolver (addresses/emails/phones/web)
  Data/EntryMapper.php               API resource + included → OrganizationEntry / PersonEntry DTOs
  Data/FacetOptions.php              facet option lists from schemas/resource types (cached)
  Render/DirectoryRenderer.php       single render path (frontend block + editor ServerSideRender)
  Render/TemplateLoader.php          theme override: {theme}/wicket-directory/{file}.php → plugin templates/
  Block/DirectoryBlock.php           register_block_type(build/directory) with render_callback
  Block/BlockAttributes.php          sanitize/clamp block attributes → DisplayOptions value object
blocks/directory/  block.json  index.js  edit.js     → build/directory/
templates/  directory.php  parts/{search-bar,filters,results-header,pagination,no-results}.php
            cards/{card-individual,card-organization}.php   (compose base components; see below)
assets/css/directory.css  (plain CSS, BEM `.wicket-directory__*`, only Wicket theme tokens with
                           fallbacks + container queries; no Tailwind utilities — the base
                           plugin's Tailwind build only scans its own components)
assets/js/directory.js    (tiny: mobile collapse default; Alpine comes from theme/base plugin)
```

## Directory config (one post-meta array)

The config below is the prototype's model trimmed to Individual and Organization. `DirectoryConfig` owns the defaults and sanitization. If an admin changes the type, the card and facet settings reset, as they do in the prototype.

- **`type`:** `individual` or `organization`.
- **`eligibility`** decides which records can appear. These conditions are built into the base API query, not shown as visitor filters.
  - `require_active_membership` (bool, default true). The CHFA template uses `membership_entries_active_eq` for orgs; the people equivalent needs to be verified.
  - `membership_ids`: membership tiers to include. Options come from the MDP memberships list.
  - `org_types` (organization directories only).
  - `opt_in`: `{schema_key, field, value}` (for example `orgmemberdir.optin = true`). It becomes `search_query['data_fields.{key}.value.{field}']`.
- **`card`:** toggles taken from the prototype's `defaultCardFields` / `orgCardFields`, without Organization Contact.
  - **Individual:** salutation, middle name, suffix, post-nominal (data field), job title, member ID, membership tier, address, email, phone, website plus its label, and profile image (data field key).
  - **Organization:** org type, description with show-more, member ID, tier, address, email, phone, website, logo (data field key).
  - **Both:** an "eyebrow" data field and "tag chips" data fields. These are needed to match the current CHFA card: the business type label and the attribute/certification pills.
  - **Contact fields** (address, email, phone, website) each carry `{type: any|<resource type slug>, only_directory: bool, only_primary: bool}`.
  - **Address** also has `address_format` (`full`, `city_province_country` or `city_province`).
- **`facets`:** a list of `{source: data_field|org_type, schema_key, field, label}`. This defines *which* filters a directory offers. Whether keyword search, location search, the filters panel and Order by are *shown* is decided per block placement (see Block).
- **`cache_version`:** an integer, bumped on every save so cached results are invalidated.

**Facets differ from the prototype on purpose.** The prototype builds facet options from distinct values across all loaded records. That isn't possible with server-side paging. Instead, options come from the schema or resource-type enum, and the filter is applied as an API search condition. The CHFA template already works this way.

## Query and data layer
- **`RequestParams`** reads flat parameters prefixed per directory: `wd{ID}_keyword`, `wd{ID}_location`, `wd{ID}_sort`, `wd{ID}_pg`, and `wd{ID}_{facet_key}[]`. The prefix lets two blocks sit on one page, and flat names let `search-form`'s built-in `$_GET[url-param]` pre-fill work unchanged. Everything is sanitized with `sanitize_text_field` / `sanitize_key`, and each facet value is checked against its allowed options.
- **Query builders** produce `['filter' => [...]]` from eligibility + keyword + location + facets, plus `sort`, `page[size|number]` and `include=emails,phones,addresses,web_addresses`.
  - **Org keyword:** `legal_name_{lang}_cont`.
  - **Person keyword:** an OR group over `given_name_cont`, `family_name_cont`, `full_name_cont` and `identifying_number_eq`.
  - **Location:** an OR group over `addresses_city_i_cont` and `addresses_state_name_i_cont`.
  - **Sort:** individual uses `given_name` / `family_name` (asc or desc); organization uses `legal_name_{lang}` (asc or desc).
- **`DirectoryRepository`** caches each response in a transient keyed by `md5(dir_id, cache_version, lang, params)`. The TTL defaults to 10 minutes and can be changed with the `wicket_directory/cache_ttl` filter. On an API exception it logs the error and returns an empty page, and the page shows a friendly "unavailable" message rather than dying.
- **`ContactResolver`** implements the prototype's truth table once and is used for all four record types:
  1. Filter by type.
  2. If neither `only_directory` nor `only_primary` is set: take the first record flagged show-in-directory, else the first primary record, else nothing.
  3. If either flag is set: keep only records matching the set flags (AND when both are set). There's no fallback.
  4. An empty result hides the row. We don't show "Not provided".
- **`EntryMapper`** turns API resources into flat DTOs. Data-field enum keys become labels through `wicket_get_schemas_options()`, with schemas cached once per request.
- **Hooks for developers** (these replace the deferred Developer panel):
  - `wicket_directory/query_args` and `wicket_directory/query_args_{slug}`
  - `wicket_directory/entry` and `wicket_directory/entry_{slug}`: modify entry data before the card renders
  - `wicket_directory/before_render` and `wicket_directory/before_render_{slug}`
  - `wicket_directory/card_template`

## Rendering
`DirectoryRenderer::render(int $dir_id, array $block_attrs)` handles everything:
1. Load the directory. If it's missing or not `publish`, return an empty string, or an editor notice when rendering the editor preview.
2. Parse the request, query the API, and map the results.
3. Load the templates: search bar, Refine Results panel, results header with the "X–Y of N" count and Order by, cards, pagination, and no-results.

Rules for all templates:
- Every output is escaped (`esc_html`, `esc_attr`, `esc_url`).
- Every string is translatable. That also replaces CHFA's hard-coded French no-results message.
- The theme can override any template at `wicket-directory/…`.
- Templates compose base components and use only Wicket theme CSS variables (see "Theme & component compatibility").
- Cards use a container query to stack their columns on narrow widths.
- The Refine panel collapses on mobile. The `collapseRefineOnMobile` attribute controls its default state.

## Theme & component compatibility

### CSS variables (Wicket theme `assets/styles/variables/_theme-json.scss`)
The Wicket theme exposes its design tokens as CSS custom properties on `:root`: `--color-*` (text, interactive, state, bg, border), `--spacing-25…1000`, `--border-radius-*`, `--box-shadow-4|8|12|focus`, `--font-size-heading-*|body-*|label-*|button-label-*`, `--line-height-*`, `--letter-spacing-*`, `--layout-*`. Client themes set the underlying values.

- `directory.css` uses **only these tokens**, no hard-coded colours or sizes. It maps them once onto a small plugin-scoped layer, so a client theme can retune the directory without touching the rest of the site:
  ```css
  .wicket-directory {
    --wd-text:        var(--color-text-content, #232a31);
    --wd-text-muted:  var(--color-text-content-secondary, #5b6670);
    --wd-accent:      var(--color-text-accent, #0a5c8a);
    --wd-card-bg:     var(--color-bg-card, #fff);
    --wd-card-border: var(--color-border-card, #dfe3e7);
    --wd-panel-bg:    var(--color-bg-light, #f5f7f9);
    --wd-radius:      var(--border-radius-150, .75rem);
    --wd-shadow:      var(--box-shadow-4, 0 4px 8px rgba(35,42,49,.2));
    --wd-gap:         var(--spacing-200, 1rem);
    --wd-sidebar:     var(--layout-sidebar, 322px);
    /* …heading/body font sizes, state colours for the "unavailable" alert, focus ring */
  }
  ```
- The fallbacks keep the directory readable on non-Wicket themes.
- The card title, eyebrow, meta and chips use `--font-size-heading-xs`, `--font-size-label-*` and `--letter-spacing-loose`. The focus ring uses `--box-shadow-focus`.
- The plugin CSS is loaded only when the block is on the page (`block.json` `style`), and the same file is loaded in the editor.

### Base components used (`wicket-wp-base-plugin/includes/components`)
| UI piece | Component | Notes |
|---|---|---|
| Keyword search + Search button | `search-form`, as-is | `url-param` = the flat `wd{ID}_keyword`, so the component's own `$_GET` lookup pre-fills it. Its submit button submits the whole directory `<form>`. Placeholder varies by type ("Search by name, member ID" / "Search by organization"). With Hide Keyword Search on, only the `button` component is rendered as the submit. |
| Location field | `icon` + an input with the `component-search-form` classes | Rendered next to the keyword field. `search-form` puts its input and button in one wrapper, so the row is our flex container with `.component-search-form { display: contents }`, scoped to `.wicket-directory__search`. That lets `order` place it as [keyword] [location] [Search]. |
| Refine Results panel | **not used**, `parts/filters.php` | `filter-form` only works with WP taxonomies (`get_terms`), post types and a date range, so it doesn't fit MDP facets. Our own template gives checkbox groups, see-more after 5, a selected count, Apply Filters, Clear All (the `button` component), and mobile collapse driven by `collapseRefineOnMobile`. It uses the same `component-filter-form__*` class names, so the theme's `filter-form.scss` styles it consistently with the site's other listings. |
| Card wrapper | `card-listing` **classes** | `card-listing`'s args are post-shaped: term objects, attachment IDs, excerpt. So `cards/card-*.php` output the same `component-card-listing` / `__content-type` / `__title` / `__excerpt` structure, and theme card styles apply unchanged. The contact columns are our own markup inside it. |
| Chips (tags, business types, certifications) | `tag` | One per data-field value. `link` is left empty. |
| "Visit Website" CTA | `button` | Uses `a_tag`, `variant: secondary`, `suffix_icon: fa-solid fa-arrow-up-right-from-square`, `link_target: _blank`. |
| Email / phone lines | `link` + `icon` | Same pattern as `card-contact` (`fa-regular fa-envelope` / `fa-phone`). |
| Description show-more | `link`-styled toggle | Uses Alpine `x-data`, as the theme's filter see-more does. |
| Pagination | the theme's `wicket_pagination()` | Called when `function_exists()` is true. It wraps core `paginate_links()` with a custom `base`/`format` for our namespaced page parameter. Otherwise the same `nav.wicket-pagination .page-numbers` markup comes from `paginate_links()`, so `pagination.scss` still styles it. |
| API-unavailable / draft notice | `alert` | |
| Order by | native `<select>` inside `.form` | Picks up the theme's form styles. There is no base component for it. |

- **Profile photo / logo:** the base `image` component only takes WP attachment IDs. The MDP returns URLs, so these use a plain `<img loading="lazy">` in the card.
- **`WICKET_WP_THEME_V2`:** base components switch between BEM (v2) and Tailwind classes themselves, so we get both for free. Our own markup uses BEM only and is styled by `directory.css`.
- **Escaping:** `button`, `link` and `icon` echo their arguments without escaping. Our templates pass pre-escaped values: `esc_url()` for links, `esc_html()` for labels and text.
- **No changes to the base plugin.** Components are used exactly as they ship today.

## Block `wicket/directory` (dynamic) — the only way to embed a directory
There is **no shortcode**. The block is the single embed point, and all per-placement display options live in its Settings sidebar (one `PanelBody`, "Directory"):

| Control | Attribute | Type / default | Behaviour |
|---|---|---|---|
| Directory | `directoryId` | int, `0` | `SelectControl` of **published** directories only (`useEntityRecords('postType','wicket_directory',{status:'publish',per_page:-1})`). With none selected the canvas shows a Placeholder prompting a choice. |
| Default Sort Order | `defaultSort` | string, `''` (= first option for the type) | Options depend on the selected directory's type. Individual: First Name A–Z / Z–A, Last Name A–Z / Z–A. Organization: Name A–Z / Z–A. Reset to the type's first option when the directory changes. Visitors can still change it via Order by unless that's hidden. |
| Number of Results to Display | `resultsPerPage` | int, `6` | `NumberControl`, clamped server-side to 1–50. Page size before pagination. |
| Hide Keyword Search | `hideKeyword` | bool, `false` | `ToggleControl`. |
| Hide Location Search | `hideLocation` | bool, `false` | `ToggleControl`. |
| Hide Filters | `hideFilters` | bool, `false` | Hides the Refine Results panel. Help text notes it only appears when the directory has facets configured. |
| Hide Order By | `hideOrderBy` | bool, `false` | Hides the sort select; `defaultSort` still applies. |
| Collapse Refine Results by default on mobile/tablet | `collapseRefineOnMobile` | bool, `false` | Sets the panel's initial collapsed state below the tablet breakpoint. |

- **Editor behaviour:**
  - The canvas renders the real output with `ServerSideRender` (same PHP renderer as the frontend), so toggles update the preview live.
  - If a saved `directoryId` is no longer published (Draft/trashed), keep the selection and show a warning `Notice` in the sidebar and canvas; the frontend renders nothing.
  - The block is marked `"supports": {"html": false}` and uses `apiVersion: 3`, category `wicket`.
- **Server side:** `BlockAttributes` sanitizes and clamps every attribute (unknown `defaultSort` falls back to the type default) before `DirectoryRenderer` uses them. Hidden controls are never rendered, and their GET params are ignored, so a hidden keyword box can't be driven through the URL.

## Admin screen (classic meta boxes)
All Directories is the native list table, with Type and Status columns. No shortcode column; directories are placed with the block.

The Edit screen has these meta boxes:
1. **Directory:** Type and Eligibility.
2. **Card:** the toggles, with the contact-rule fieldset repeated for each contact field and hint text explaining the truth table.
3. **Filters:** a facet repeater with add, remove and reorder. Facet choices come from `wicket_get_schemas()` and org types. (Show/hide of keyword, location, filters and Order by is a block setting, not a directory setting.)

Publish and Save Draft are WordPress's own. The block editor preview serves as the live preview for the MVP.

## Stack registration (file changes only, in each repo's working tree)
- **`qa`:**
  - `orchestration/component-map.json` and `component-config.json`;
  - `composer.json` (autoload-dev + `test:unit:directory`);
  - `pest.php` (path helper, prefix map, detector, `uses()`);
  - `orchestration/run-pest-suite.php` maps;
  - `phpunit.xml` and `phpstan.neon`.
- **Stack root:** the component table in `AGENTS.md`.
- **Local only:** the `~/.config/wicket-tools/config.toml` `init_dev` entry. Adding it to the CLI's built-in defaults needs a CLI release.

## Implementation order
0. **API spike, done first and short.** Confirm these against a staging MDP, using `wicket_api_client()` in `wp shell`:
   - `people/query` filter names for active membership, tier (`membership_uuid_in`?), keyword and address;
   - the contact record fields for type (`type` / `phone_type`) and consent (`consent_directory` / `consent`) on addresses, emails, phones and web addresses;
   - how a person's membership tier comes back (`include=person_memberships`?).

   Write the findings into the plugin's `docs/engineering/api-queries.md`.
1. Scaffold: main file, composer, `Plugin`, dependency guard, CPT and meta, `DirectoryConfig`.
2. Query and data layer: `RequestParams`, the query builders, `DirectoryRepository`, `ContactResolver`, `EntryMapper`, `FacetOptions`.
3. The native block's PHP side (`block.json`, `DirectoryBlock`, `BlockAttributes`) together with the renderer, templates and CSS/JS, so the block is the embed point from the start.
4. The block editor UI: the Settings sidebar controls above, placeholder, draft notice, `ServerSideRender`, and the `@wordpress/scripts` build. The build output (`build/`) is kept in the repo, not gitignored.
5. Admin meta boxes and list columns.
6. QA registration and tests.
7. Phase 2 backlog, not part of this build:
   - Groups directory and group roster;
   - Organization Contact;
   - Developer panel UI;
   - the merge-tag Advanced editor;
   - an admin live preview.

## Verification
- **Unit tests** (Pest, in `qa/tests/Unit/Directory/`):
  - `ContactResolver`: every truth-table branch for each record type;
  - the query builders: each combination of eligibility, keyword, location, facets and sort produces the expected Ransack array;
  - `RequestParams`: sanitizing, rejecting unknown facet values, clamping the page number;
  - `DirectoryConfig::sanitize`: defaults, and the reset on type change.
- **WordPress tests** (`qa/tests/WordPress`):
  - the CPT, meta and block are registered;
  - a draft directory renders an empty string, a published one renders its markup (with the API stubbed);
  - each block option works: every Hide toggle removes its control from the markup (and its GET param is ignored), `resultsPerPage` is clamped to 1–50, an invalid `defaultSort` falls back to the type default, and `collapseRefineOnMobile` sets the panel's initial state.
- **Unit:** `BlockAttributes` sanitizing and clamping.
- **Manual:**
  1. In the stack's local site, rebuild CHFA's current org directory as a directory config: active membership, `orgmemberdir.optin`, a Business Types facet, eyebrow = business type, and chips.
  2. Add the block to a page and compare it with the old template for search, facets, sort, pagination, EN/FR, and mobile collapse.
  3. Switch the directory to Draft and check that the page renders nothing and the editor shows a notice.
- **Theme compatibility (manual):**
  1. Render the block on the Wicket theme (v2) and check that cards, filters, search, pagination and chips pick up the theme's colours, spacing, radius and type.
  2. Override a few `--color-*` / `--spacing-*` values in a child theme and confirm the directory follows.
  3. Render on a default WordPress theme (Twenty Twenty-Five) and confirm the fallbacks give a readable layout.
  4. Check the search row order ([keyword] [location] [Search]) with each Hide combination. Check that the keyword value pre-fills after a search and that the Refine panel matches the look of the theme's listing-block filters.
- **Code style:** `composer cs:lint` in the plugin, and `wicket test` for the suite.
