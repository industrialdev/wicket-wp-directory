# What This Plugin Does

Wicket Directory adds configurable public directories of **Individuals** and **Organizations** from the Wicket member data platform (MDP). Visitors can search, filter, sort and page through them. Admins set up a directory once in WP Admin and place it on any page with the **Wicket Directory** Gutenberg block. It replaces the per-client member-directory page templates that used to live in child themes.

> **Status: in development (scaffold landed).** The approved plan is `docs/engineering/mvp-plan.md`. Read it before writing code; it is the source of truth for scope, data model and decisions. Classes and paths below are the planned layout unless marked as landed. Update this file as they land.

MVP scope: Individual and Organization directories only. These are deferred to phase 2, so don't build them without being asked:
- Groups directories and group rosters
- Organization Contact (Membership Owner / Relationship Types)
- the Developer panel UI
- the `[col]/{{field}}` merge-tag card editor

## Commands

The composer scripts exist (ticket 1.1). `package.json` and the npm scripts arrive with the block editor build (ticket 4.1). Composer isn't installed on the host: run it in the stack's PHP container (`docker exec -w /var/www/html/web/app/plugins/wicket-wp-directory wicket-wp-stack-php-1 composer …`).

```bash
composer install          # Install dependencies (including dev)
composer cs:lint          # Check code style (dry run)
composer cs:fix           # Fix code style
composer production       # Fix style → remove dev deps → optimise autoloader (run before tagging)
npm run build             # Build the block editor script (@wordpress/scripts) into build/
npm run start             # Watch mode for the block editor script
php -l <file>             # Quick PHP syntax check
```

`build/` is kept in the repo because releases don't run a JS build. Rebuild after any change under `blocks/`.

Tests live in the shared QA suite at `../../../../../../qa/`, never in this repo. Read `qa/README.md` and `qa/AGENTS.md` before adding any.

## Architecture (planned)

### Bootstrap

```
wicket-wp-directory.php         (landed) header: Requires Plugins: wicket-wp-base-plugin, PHP 8.3; WICKET_DIRECTORY_* constants
  dependency guard               (landed) function_exists('Wicket') → else admin notice + early return; activation hook checks PHP + base
  Plugin::get_instance()         (landed) singleton; plugin_setup() on plugins_loaded:100 (after the base plugin's :99 setup); text domain
    DirectoryPostType            (landed) CPT `wicket_directory` (caps → manage_options, classic editor) + `_wicket_directory_config` post meta (REST `edit` context only)
      → Config\DirectoryConfig   (landed) typed config: defaults(type), from_post(post), sanitize(input, ?previous), to_array(); Config\DirectoryType enum
    DirectoryMetaBoxes           classic edit screen (block editor disabled for this CPT)
    DirectoryBlock               dynamic block `wicket/directory` → DirectoryRenderer
```

Namespace `Wicket\Directory\` → `src/`. Text domain `wicket-directory`.

### Request flow

```
Block render_callback / ServerSideRender
  → BlockAttributes (sanitize, clamp)
  → DirectoryRenderer
      → load directory post (not `publish` → render nothing; notice in the editor)
      → Query\RequestParams      (landed) flat GET params prefixed `wd{ID}_…`; sort tokens from DirectoryType::sort_options()
      → Query\OrganizationQueryBuilder | PersonQueryBuilder (landed) → Query\DirectoryQuery (landed; path() + body(), or matches_nothing)
      → Api\DirectoryRepository  (landed) builder by type → API + transient cache → Api\ResultPage (landed; `unavailable` on API failure)
      → EntryMapper + ContactResolver → DTOs
      → TemplateLoader → templates/ (theme can override at wicket-directory/…)
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
  - Use only Wicket theme CSS variables (`--color-*`, `--spacing-*`, `--border-radius-*`, `--box-shadow-*`, `--font-size-*`), each with a fallback value. Map them onto the `--wd-*` layer on `.wicket-directory`.
  - No hard-coded colours or sizes, and no Tailwind utilities in our own markup.
  - Use container queries for card layout.
- **Escape everything.** Base `button`, `link` and `icon` don't escape their args, so pass `esc_url()` / `esc_html()` values in.
- **Filtering happens in the API query** (Ransack filters on `people/query` / `organizations/query`). Never fetch everything and filter in PHP. Facet options come from schema/resource-type enums, not from the result set.
- **Contact info** (address, email, phone, website) always goes through `ContactResolver` (type → show-in-directory / primary truth table). Don't pick records ad hoc in templates.
- **Draft directories never render** on the frontend, even if a block still points at them.

## Key Extension Points (planned)

| Hook | Purpose |
|---|---|
| `wicket_directory/query_args`, `wicket_directory/query_args_{slug}` (filter) | (landed) Modify the API query before it runs. Gets `$args` (`filter`, `page`, `sort`, `include`), `DirectoryConfig`, `RequestParams`. `{slug}` is the directory post's slug |
| `wicket_directory/entry`, `wicket_directory/entry_{slug}` (filter) | Modify one entry's data before its card renders |
| `wicket_directory/before_render`, `wicket_directory/before_render_{slug}` (action) | Runs before the listing renders |
| `wicket_directory/card_template` (filter) | Swap the card template file |
| `wicket_directory/cache_ttl` (filter) | (landed) API response cache lifetime in seconds (default 600). Gets `$ttl`, `DirectoryConfig`, the directory `WP_Post`. `0` disables the cache |

## Code Style

PHP CS Fixer rules are `@PSR12`, `@PER-CS` and `@PHP82Migration`, configured in `.php-cs-fixer.dist.php` (same as the sibling plugins). Every PHP file starts with `declare(strict_types=1);`. Run `composer cs:lint` before handing off work.

## Documentation

| Doc | Audience |
|---|---|
| `docs/engineering/mvp-plan.md` | Developers & agents: approved MVP plan and decisions |
| `docs/engineering/mvp-tickets.md` | Developers & agents: numbered MVP build tickets (`{step}.{n}`, e.g. 2.3) with dependencies, acceptance criteria and status. When asked to "do ticket X.Y", read that ticket and its dependencies first, then set its Status when starting and finishing |
| `docs/engineering/api-queries.md` | Developers & agents: verified MDP filters, sort keys, paging limits, tier lookup and contact-record fields, with example payloads. The MDP silently ignores unknown predicates, so use only the keys listed here |

Docs follow the stack's docs conventions (see `wicket-wp-financial-fields/docs/AGENTS.md`): kebab-case names, required frontmatter (`title`, `audience`), and `docs/{product,engineering,guides}/`.
