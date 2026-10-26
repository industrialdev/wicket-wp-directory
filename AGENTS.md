# What This Plugin Does

Wicket Directory adds configurable public directories of **Individuals** and **Organizations** from the Wicket member data platform (MDP). Visitors can search, filter, sort and page through them. Admins set up a directory once in WP Admin and place it on any page with the **Wicket Directory** Gutenberg block. It replaces the per-client member-directory page templates that used to live in child themes.

> **Status: in development (scaffold landed).** The approved plan is `docs/engineering/mvp-plan.md`. Read it before writing code; it is the source of truth for scope, data model and decisions. Classes and paths below are the planned layout unless marked as landed. Update this file as they land.

MVP scope: Individual and Organization directories only. These are deferred to phase 2, so don't build them without being asked:
- Groups directories and group rosters
- Organization Contact (Membership Owner / Relationship Types)
- the Developer panel UI
- the `[col]/{{field}}` merge-tag card editor

## Commands

The composer scripts exist (ticket 1.1), and the npm scripts (ticket 4.1). npm pins Node through Volta (`package.json` `volta`), so run them with `volta run npm …` if Volta isn't your default. Composer isn't installed on the host: run it in the stack's PHP container (`docker exec -w /var/www/html/web/app/plugins/wicket-wp-directory wicket-wp-stack-php-1 composer …`).

```bash
composer install          # Install dependencies (including dev)
composer cs:lint          # Check code style (dry run)
composer cs:fix           # Fix code style
composer production       # Fix style → remove dev deps → optimise autoloader (run before tagging)
npm run build             # Build the block editor script (@wordpress/scripts) into build/
npm run start             # Watch mode for the block editor script
npm run lint:js           # Lint blocks/ and assets/js/ (@wordpress/eslint-plugin)
php -l <file>             # Quick PHP syntax check
```

`build/` is kept in the repo because releases don't run a JS build. Rebuild after any change under `blocks/`, including `block.json`: the block registers from the copy in `build/directory/`. `qa`'s `DirectoryBlockTest` fails when the two differ.

Tests live in the shared QA suite at `../../../../../../qa/`, never in this repo. See [Tests](#tests). Read `qa/README.md` and `qa/AGENTS.md` before adding any.

## Tests

**Every time you write or change code, write unit tests for it in the same task.** A change without tests isn't done, however small it is.

- **No Browser tests** Don't write browser tests for now.
- **Run them** with `wicket test unit:directory` before handing off. If any fail, report the output. Until a `wicket` CLI release registers the package, the CLI answers `unknown component "directory"`: run `docker exec -w /var/www/qa wicket-wp-stack-php-1 composer test:unit:directory` instead.
- Tests live in `qa/tests/Unit/Directory/`. `AbstractTestCase` stubs the WordPress functions the config, query and data classes call. `Support/Fixtures` holds MDP payloads. Guard each file with `wicket_skip_if_missing()` on a symbol the change introduces, so qa `main` stays green while the plugin PR is open.

## Architecture (planned)

### Bootstrap

```
wicket-wp-directory.php         (landed) header: Requires Plugins: wicket-wp-base-plugin, PHP 8.3; WICKET_DIRECTORY_* constants
  dependency guard               (landed) function_exists('Wicket') → else admin notice + early return; activation hook checks PHP + base
  Plugin::get_instance()         (landed) singleton; plugin_setup() on plugins_loaded:100 (after the base plugin's :99 setup); text domain
    DirectoryPostType            (landed) CPT `wicket_directory` (caps → manage_options, classic editor) + `_wicket_directory_config` post meta (REST `edit` context only)
                                 + read-only `directory_type` REST field (`view` context), so the block editor knows each directory's type
      → Config\DirectoryConfig   (landed) typed config: defaults(type), from_post(post), sanitize(input, ?previous), to_array(); Config\DirectoryType enum
    Admin\DirectoryMetaBoxes     (landed, admin only) one "Directory settings" meta box (render_box → views/settings.php); posts `wicket_directory[...]`;
                                 save_post handler lays the posted SECTIONS (`type`, `eligibility`, `card`, `facets`) over the stored config
                                 → DirectoryConfig::sanitize(…, previous) (nonce, manage_options + edit_post). Directory panels on top (views/directory.php:
                                 type select, chip pickers for tiers/org types, active-members, opt-in), then tabs Card View (views/card.php; one checklist in
                                 card_groups(type) order, card_fields(type) lists its controls; ticked items reveal their options) and Filters
                                 (views/filters.php; facet_choices(type, schemas) = org type + enum data fields; a hidden `facets` marker lets "no rows" save;
                                 searchable picker); views/chip-picker.php is the shared multi-select. Data-field pickers post "schema_key.field".
                                 assets/js/admin.js (no build): type toggles, tabs, popovers, chips, reveals and contact hints, facet repeater;
                                 assets/css/admin.css styles the box. Everything posts the same without the script
      → Admin\MembershipTiers    (landed) tier choices from GET memberships, filtered by type
      → Admin\ResourceTypes      (landed) one GET resource_types: org types and contact record types (ContactResolver::RELATIONSHIPS)
      → Admin\SchemaFields       (landed) GET json_schemas?include=json_schema_resources: the type's text/list data fields, with an enum flag
    Admin\ListColumns            (landed, admin only) Type and Status columns after Title on All Directories; non-published rows say "Not shown on the site"
    Block\DirectoryBlock         (landed) dynamic block `wicket/directory` registered from build/directory (built from blocks/directory); adds the `wicket` category if missing;
                                 render_callback → DirectoryRenderer; enqueue_style() puts directory.css in the <head> of a post that has the block;
                                 enqueue_editor_data() prints window.wicketDirectoryEditor.sortOptions (DirectoryType::sort_options() per type) before the editor script
      blocks/directory/edit.js   (landed) sidebar (published directories via useEntityRecords, context view), Placeholder, ServerSideRender in <Disabled>, unpublished-selection Notice
      → Block\BlockAttributes    (landed) from_array(attributes): sanitized, resultsPerPage clamped 1–50; sort(DirectoryType) resolves defaultSort
```

Namespace `Wicket\Directory\` → `src/`. Text domain `wicket-directory`.

### Request flow

```
Block render_callback / ServerSideRender
  → Block\BlockAttributes (landed; sanitize, clamp; sort(type) → type default for an invalid defaultSort)
  → Render\DirectoryRenderer   (landed) render(BlockAttributes)
      → load directory post (missing/trashed/not `publish` → '' for visitors; parts/notice.php in the editor preview)
      → Data\FacetOptions        (landed) for_config(config, lang) → facet key => [{value, label}] from enums; values() → RequestParams' allow-list
      → Query\RequestParams      (landed) flat GET params prefixed `wd{ID}_…`; sort tokens from DirectoryType::sort_options()
      → do_action before_render, before_render_{slug} (before the API query; echoed output comes first)
      → Query\OrganizationQueryBuilder | PersonQueryBuilder (landed) → Query\DirectoryQuery (landed; path() + body(), or matches_nothing)
      → Api\DirectoryRepository  (landed) builder by type → API + transient cache → Api\ResultPage (landed; `unavailable` on API failure)
      → Data\EntryMapper (landed; map(page, config, slug, lang) → Data\PersonEntry | Data\OrganizationEntry, immutable, raw values, empty = hidden)
          uses Data\ContactResolver (landed; static resolve(field, records, rule), the truth table)
          and Data\EnumLabels (landed; enum-key + org-type labels, one load per source per request; shared() keeps the maps in a transient per language)
      → Render\TemplateLoader (landed) templates/directory.php → parts/*, cards/card-{type} (theme overrides at {theme}/wicket-directory/{name}.php)
          parts/search-bar (landed): search-form + location field
          parts/filters (landed): Refine Results checkbox groups (component-filter-form__* classes), Apply Filters, Clear All → clear_url
          parts/results-header (landed): "Displaying X–Y of N" count + Order by select (submits on change; noscript Sort button)
          parts/pagination (landed): wicket_pagination() if the theme has it, else paginate_links() in nav.wicket-pagination; base on wd{ID}_pg
          parts/no-results (landed): no-results message, or the alert component for an `unavailable` page
          cards/card-individual (landed): component-card-listing classes; details / address / contact columns; tag, button, link + icon components
          cards/card-organization (landed): same structure; org type under the name, logo, description with Show more / Show less (Alpine) past 40 words
          assets/css/directory.css (landed) block viewStyle + editorStyle; one --wd-* token layer on .wicket-directory (theme tokens + fallbacks);
            sidebar layout via @container; --standalone modifier (no Wicket theme) styles the panel, cards and pagination itself
```

There is **one render path**. The frontend and the block editor preview both go through `DirectoryRenderer`. Don't add a second one.

## Key Rules

- **WordPress-native only.** Use a CPT, `register_post_meta`, classic meta boxes, and a native block (`block.json` + `render_callback`, editor via `@wordpress/scripts`). No HyperFields, ACF or Carbon Fields.
- **The block is the only embed point.** Don't add shortcodes. All per-placement options are block attributes:
  - `directoryId`, `defaultSort`, `resultsPerPage`
  - `hideKeyword`, `hideLocation`, `hideFilters`, `hideOrderBy`
  - `collapseRefineOnMobile`
- **Reuse `wicket-wp-base-plugin`** helpers and components before writing new ones:
  - API: `wicket_api_client()`
  - Schemas: `wicket_get_schema()` and `wicket_get_schemas_options()`
  - Resource types: `wicket_get_resource_types()`
  - Language: `wicket_get_current_language()` (not `ICL_LANGUAGE_CODE`)
  - Logging: `Wicket()->log()`
  - Components: `get_component('search-form' | 'button' | 'tag' | 'link' | 'icon' | 'alert')`
  - Pagination: the theme's `wicket_pagination()`
- **Don't** use `filter-form`, because it only handles WP taxonomies. `parts/filters.php` mirrors its `component-filter-form__*` classes instead.
- **Don't call these per result row:**
  - `wicket_search_organizations()`: no paging, one request per row
  - `wicket_get_resource_type_name_by_slug()`: refetches everything on every call
  - `wicket_get_*_connections_by_id()`: its static cache ignores the ID
- **Styling:**
  - Use only Wicket theme CSS variables (`--color-*`, `--spacing-*`, `--border-radius-*`, `--box-shadow-*`, `--font-size-*`, `--layout-*`), each with a fallback value. Map them onto the `--wd-*` layer (the first `.wicket-directory {}` rule); every other rule uses only `--wd-*`.
  - No hard-coded colours or sizes outside that layer, and no Tailwind utilities in our own markup. `qa`'s `DirectoryCssTest` enforces both.
  - Use container queries for card layout.
  - Styles only non-Wicket themes need go under `.wicket-directory--standalone`, so they never fight the Wicket theme's own.
- **Escape everything.** Base `button`, `link` and `icon` don't escape their args, so pass `esc_url()` / `esc_html()` values in.
- **Filtering happens in the API query** (Ransack filters on `people/query` / `organizations/query`). Never fetch everything and filter in PHP. Facet options come from schema/resource-type enums, not from the result set.
- **Contact info** (address, email, phone, website) always goes through `ContactResolver` (type → show-in-directory / primary truth table). Don't pick records ad hoc in templates.
- **Draft directories never render** on the frontend, even if a block still points at them.

## Key Extension Points (planned)

| Hook | Purpose |
|---|---|
| `wicket_directory/query_args`, `wicket_directory/query_args_{slug}` (filter) | (landed) Modify the API query before it runs. Gets `$args` (`filter`, `page`, `sort`, `include`), `DirectoryConfig`, `RequestParams`. `{slug}` is the directory post's slug |
| `wicket_directory/entry`, `wicket_directory/entry_{slug}` (filter) | (landed) Modify one entry's data before its card renders. Gets the entry's `to_array()`, the raw API resource and `DirectoryConfig`; returns the array. Unknown keys are dropped and wrong-typed values keep the original; custom data goes under `extra` |
| `wicket_directory/before_render`, `wicket_directory/before_render_{slug}` (action) | (landed) Runs before the API query and the listing render. Gets `DirectoryConfig`, `RequestParams`, the directory `WP_Post`. Output it prints comes before the directory. Not fired for a missing or unpublished directory |
| `wicket_directory/card_template` (filter) | (landed) Swap the card template file, once per render. Gets the located absolute path (`''` when none), `DirectoryConfig`, the directory `WP_Post`. A return that isn't an existing file keeps the located one |
| `wicket_directory/cache_ttl` (filter) | (landed) API response cache lifetime in seconds (default 600). Gets `$ttl`, `DirectoryConfig`, the directory `WP_Post`. `0` disables the cache |
| `wicket_directory/labels_cache_ttl` (filter) | (landed) Lifetime of the per-language enum and org-type label transient that facets and cards share (default 3600). Gets `$ttl`, `$lang`. `0` disables it |

## Code Style

PHP CS Fixer rules are `@PSR12`, `@PER-CS` and `@PHP82Migration`, configured in `.php-cs-fixer.dist.php` (same as the sibling plugins). Every PHP file starts with `declare(strict_types=1);`. Run `composer cs:lint` before handing off work.

## Documentation

| Doc | Audience |
|---|---|
| `docs/engineering/mvp-plan.md` | Developers & agents: approved MVP plan and decisions |
| `docs/engineering/mvp-tickets.md` | Developers & agents: numbered MVP build tickets (`{step}.{n}`, e.g. 2.3) with dependencies, acceptance criteria and status. When asked to "do ticket X.Y", read that ticket and its dependencies first, then set its Status when starting and finishing |
| `docs/engineering/api-queries.md` | Developers & agents: verified MDP filters, sort keys, paging limits, tier lookup and contact-record fields, with example payloads. The MDP silently ignores unknown predicates, so use only the keys listed here |

Docs follow the stack's docs conventions (see `wicket-wp-financial-fields/docs/AGENTS.md`): kebab-case names, required frontmatter (`title`, `audience`), and `docs/{product,engineering,guides}/`.
