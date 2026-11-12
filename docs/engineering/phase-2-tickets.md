---
title: "Phase 2 Tickets"
audience: [developer, agent]
updated: 2026-11-12
---

# `wicket-wp-directory` Phase 2 tickets

Phase 2 build tickets, taken from the backlog in [mvp-plan.md](mvp-plan.md) (step 7) and the designer's "Member Directory Block" prototype. IDs are `P2.{n}`. Refer to a ticket by its ID, e.g. "let's do ticket P2.3". The same IDs prefix the task names in Asana's **BUILD: Phase 2** section (`[Directory P2.3] …`), where the dependencies are linked too.

There is no approved Phase 2 plan yet. These tickets are the scope; settle open design questions in the ticket that raises them and record the outcome here.

Every ticket must stay backwards compatible: existing Individual and Organization directories, their saved config, their block attributes and their GET params keep working unchanged. Unit tests go in `qa/tests/Unit/Directory/` with each ticket, as in the MVP.

Keep the **Status** column current: `To do`, `In progress`, `Done`.

## Index

| ID | Ticket | Depends on | Status |
|---|---|---|---|
| P2.0 | [API spike: groups, group members, connections and membership owner](#p20) | — | Done |
| P2.1 | [Groups directory type: config, query builder and eligibility](#p21) | P2.0 | Done |
| P2.2 | [Group card template](#p22) | P2.1 | Done |
| P2.3 | [Group roster page: Default View, Back to Directory, own search, filters, sort and paging](#p23) | P2.2 | Done |
| P2.4 | [Group Member card, with its own card config and facets](#p24) | P2.3 | Done |
| P2.5 | [Organization Contact on the org card (Membership Owner / Relationship Types)](#p25) | P2.0 | Done |
| P2.6 | [Individual card: Employer, Designation and relationship types](#p26) | P2.0 | To do |
| P2.7 | [Merge-tag card template engine (`[col]` / `{{field}}`)](#p27) | P2.4, P2.5, P2.6 | To do |
| P2.8 | [Admin Advance Configurations editor](#p28) | P2.7 | To do |
| P2.9 | [Developer tab: custom code switch, notes, hook reference and custom fields](#p29) | — | To do |
| P2.10 | [Admin live card preview](#p210) | P2.7 | To do |
| P2.11 | [Phase 2 acceptance: theme check, docs and Atlas](#p211) | P2.1–P2.10 | To do |

## 0. API spike

<a id="p20"></a>

### P2.0 — API spike: groups, group members, connections and membership owner

Verify against the staging MDP (as 0.1 did for people and organizations) and record the findings and example payloads in [api-queries.md](api-queries.md). The MDP silently ignores unknown predicates, so later tickets may use only verified keys.

- Groups: the list/query endpoint, paging, sort by name (`{lang}`), and filters for status (active/inactive), group type, name keyword and excluding groups by UUID.
- Active member count per group without one request per row (an attribute, a count, or one batch request per page).
- Group members: the endpoint for one group's members with role, active flag, start and end dates; include the person with `emails,phones,addresses,web_addresses`; keyword, sort, paging and data-field facet filters on the person.
- Connections: person ⟷ organization relationship types, the active flag and dates; one batch request per page of orgs or people.
- Membership owner: where an organization membership's owner lives and how to fetch it for a page of orgs in one request.
- Group types and relationship types: where their labels come from (resource types), EN/FR.

**Acceptance criteria:**

- Every filter, sort key and include used by P2.1–P2.6 is verified with a no-match check (→ 0) and listed in api-queries.md.
- The per-page batch requests for member counts, owners and connections are documented. No per-row calls.

**Outcome (2026-11-10):** see [api-queries.md](api-queries.md#groups-directory-groupsquery), from "Groups directory" to "Labels", and its [Phase 2 corrections](api-queries.md#phase-2-corrections). Everything was verified with no-match checks except three positive cases staging has no data for: an inactive group, an inactive-only group member, and an owner with an active connection to their own org. `primary_organization` is null for every person, so it is unverified. P2.1, P2.3, P2.5 and P2.6 below have been updated with the verified names and the decisions they now need to make.

## 1. Groups

<a id="p21"></a>

### P2.1 — Groups directory type: config, query builder and eligibility

**Depends on:** P2.0

Add a third directory type, Group, alongside Individual and Organization.

- `DirectoryType::Group`, with sort options Name A–Z / Z–A.
- Group eligibility config: Group Status (All / Active / Inactive, default Active), group types (chip picker), and "Groups which shouldn't be displayed" (an exclude list, stored by UUID, picked by name).
- `GroupQueryBuilder` (extends `QueryBuilder`) → `DirectoryQuery` on `groups/query`: status `active_eq`, types `resource_type_slug_in` (**not** `type_in`, which groups ignore), exclude list `uuid_not_in`, keyword `name_{lang}_cont`, sort `name_{lang}`; facets (group type only: no schema is bound to groups).
- `DirectoryRepository` support, including the active member count. Decide between `attributes.active_member_count` (free, but counts active rows, so a member with overlapping terms counts twice) and one `group_members/query` batch per page that counts distinct people. P2.0 recommends the batch, so the card agrees with the roster (api-queries.md, Phase 2 corrections item 2).
- `EntryMapper` → `GroupEntry` (name, status, type label, description, active member count). The `wicket_directory/entry` filter applies.
- Admin: Group in the Type select, the group eligibility panels, and Card View / Filters tabs showing group fields only.
- Block editor: group directories in the picker, sort options per type.
- The status and exclude filters apply everywhere a group from this directory can be reached (listing, roster link, Default View). The roster's group lookup is `groups/query` with `uuid_in` plus every eligibility key (verified in P2.0).

**Acceptance criteria:**

- Backwards compatible: existing Individual and Organization directories, their saved config and their GET params behave exactly as before.
- An Inactive or excluded group never appears, including through a direct link.
- Unit tests for the builder (eligibility, keyword and sort combinations), config sanitize and the type-change reset.

**Outcome (2026-11-10):**

- **Config.** `DirectoryType::Group` (`group`), sorts `name_asc` / `name_desc`. Eligibility gained `group_status` (`active` / `inactive` / `all`, default `active`), `group_types` (slugs) and `excluded_groups` (lower-case UUIDs). Every type stores every eligibility key; keys that don't apply keep their defaults, so a group directory never has tiers or an opt-in, and other types never have group conditions. Existing configs read back with the three new keys at their defaults (the cache key changes once). Group card keys: `status`, `group_type`, `description`, `member_count` (all on). The View Members toggle comes with the roster link (P2.2/P2.3). Facets: a new `group_type` source, the only one a group directory accepts; data-field facets are dropped for groups (no schema is bound to them).
- **Query.** `GroupQueryBuilder` sends exactly the P2.0 keys and no `include` (`DirectoryQuery::path()` drops an empty one). Groups have no location: `DirectoryType::has_location()` is false, `RequestParams` ignores the param, the search bar has no location field, and the block editor hides its Hide Location toggle. `GroupQueryBuilder::lookup()` + `DirectoryRepository::group()` are the direct-link guard: `uuid_in` plus every eligibility key, uncached, and the returned ID must match. P2.3 must reach groups only through it.
- **Member count: the batch.** One `group_members/query` per page (`group_uuid_in` + `active_eq: true`, size 2000) counts distinct people, so the card agrees with the roster. Past 2000 rows, or if the batch fails (logged, page not cached), the card falls back to `active_member_count`. The batch runs only when the card shows the count.
- **Data.** `GroupEntry` (`status`, `group_type`, `group_type_label`, `description`, `member_count`; `null` = hidden); the inherited contact and tier fields stay empty. `EnumLabels::group_types()` reads `resource_types` (`wicket_get_resource_types()`) and shares the label transient.
- **Admin.** Group status, group types and "Groups which shouldn't be displayed" panels; tiers, active-members and opt-in panels hide for groups (`data-wd-type` now takes space-separated types). `Admin\Groups` loads every group (one `groups/query`, 2000) only for a saved group directory; unnamed groups show as "Unnamed group (slug)".
- **Not in this ticket:** `cards/card-group.php` (P2.2). Until it lands, a group directory renders its search, filters, count and paging, but empty cards.

<a id="p22"></a>

### P2.2 — Group card template

**Depends on:** P2.1

`templates/cards/card-group.php`, theme-overridable like the other cards.

- Layout per the prototype: name and status pill inline, group type below, active member count and a "View Members" button right-aligned on the same row. No image.
- Card toggles: status pill, group type, member count, description (config keys `status`, `group_type`, `member_count`, `description`, added in P2.1), View Members button (when off, the card has no way to reach the roster; add its card key here or in P2.3).
- Same rules as 3.6/3.7: base components, `--wd-*` tokens only, container query, everything escaped.

**Acceptance criteria:**

- Each toggle hides its row; empty values hide their row.
- Renders on the Wicket theme and on a non-Wicket theme (`--standalone` modifier).
- Unit tests (`CardGroupTest`).

**Outcome (2026-11-11):**

- **Template.** `templates/cards/card-group.php`, located as `cards/card-group` and overridable at `{theme}/wicket-directory/cards/card-group.php` like the other cards. Same `component-card-listing` / `wicket-directory-card__*` classes, no image. A `__summary` row holds the header (`__title-row`: the name with the status pill beside it; then `__group-type`) and `__aside` (`__member-count`, then the View Members button). The description follows the divider, with the organization card's Show more / Show less past 40 words.
- **Status pill.** The `tag` component, labelled Active / Inactive, with `wicket-directory-card__status--active` / `--inactive`. The Wicket theme styles it as a tag; `--standalone` gives it its own pill (`--wd-radius-pill` ← `--tag-corner-radius`, `--wd-font-size-small` ← `--body-sm-font-size`). A status a filter sets shows as given, without a modifier.
- **Empty values.** Each row hides when its value is empty (the mapper empties what a toggle turns off), so the card never reads the toggles. A count of 0 shows ("0 active members"); `null` hides it. A group with no name is titled "Unnamed group".
- **View Members.** New card key `view_members` (on by default) and a "View Members button" toggle beside the member count in Card View. New `GroupEntry::$members_url` (`''` hides the button; the `wicket_directory/entry` filter can set it). The button is `button` (primary, `a_tag`), with `aria-label` "View Members of {name}" because every card has one. **The mapper leaves `members_url` empty until P2.3**, so no card shows the button yet.
- **Layout.** A container query (`wd-card`, 36rem) puts the header and the aside side by side, the aside right-aligned; a narrow card stacks them.
- **Checked** against staging data in the local site (Wicket child theme): status, type, count and description render. Not checked visually on a non-Wicket theme.

<a id="p23"></a>

### P2.3 — Group roster page: Default View, Back to Directory, own search, filters, sort and paging

**Depends on:** P2.2

View Members shows one group's members on the same page, server-rendered from GET params: shareable, bookmarkable, works without JS, back/forward work.

- Directory setting **Default View**: Group List (browse, then click in), Group Members (always one chosen group's roster), Group List + Member List (both).
- A roster GET param (e.g. `wd{ID}_group`) selects the group. The roster has its own prefixed keyword, location, facet, sort and page params, so using it never resets the group list's state, and vice versa.
- Header: Back to Directory link (only in the "+ Member List" mode), group type eyebrow, title, group description from the MDP.
- The roster is a `people/query` joined with `group_members_group_uuid_eq`, so each person appears once and the MVP person filters, sort and facets carry over (P2.0). Not `groups/{uuid}/people`, which pages by term.
- **Group Member Status** setting (Active / Inactive / All) decides which members appear. Separate from the card's active member count, which is always active only. Active = `group_members_active_eq: true`; All = no key; Inactive = no active row in the group, which takes one request for the active people's UUIDs and then `uuid_not_in` (`group_members_active_eq: false` would also list current members with past terms).
- Roster keyword matches name and role. The role part matches role labels in PHP and ORs the slugs as `group_members_resource_type_slug_in`. Roster sort uses person keys only (a join sort is an HTTP 500); page size falls back to the directory's.
- Block: roster results per page and Collapse Refine by default for the roster; settings that don't apply to the chosen Default View are hidden.
- An inactive or excluded group can't be reached through a direct roster link.
- The group card's View Members button: `EntryMapper` sets `GroupEntry::$members_url` to the roster link when the card's `view_members` is on (P2.2 left it empty).

**Acceptance criteria:**

- Two directory blocks on one page don't interfere.
- Hidden controls' GET params are ignored, as in the MVP.
- Backwards compatible: existing directories and their GET params behave as before.
- Unit tests for the roster params, query and renderer branches.

**Outcome (2026-11-11):**

- **Default View.** New config section `roster` (`default_view`, `group`, `member_status`); every type stores it, and types other than group keep its defaults. `default_view` is `groups` (Group List: groups only, no member list, no View Members button), `members` (Group Members: the member list of `roster.group`, no group list and no back link) or `groups_members` (Group List + Member List, the default: View Members opens a group's member list on the same page, with Back to Directory). We read "Group List (browse, then click in)" as groups only, because P2.4 says that in the other modes only one card can be reached; if Group List should also open member lists, it becomes the same as Group List + Member List. Saved group directories without the section read back as `groups_members`. `DirectoryConfig::has_roster()` tells whether a view has member lists.
- **Params.** The member list's params live under their own prefix, `RequestParams::roster_prefix()` = `wd{ID}m_` (`wd12m_keyword`, `_location`, `_sort`, `_pg`, facets). No `wd{ID}_` prefix is a prefix of it, or the reverse, so the group list and the member list never read or clear each other's params. The group is `wd{ID}_group` (`RequestParams::requested_group()`; only a UUID is accepted). `RequestParams::for_roster()` validates sorts against the individual tokens, keeps the location, and applies the block's Hide toggles. New `RequestParams::$prefix` and `$group`; hook callbacks tell a member list's params by `$params->group` / `is_roster()`.
- **Links.** View Members = the current URL without `wd{ID}m_*` and with `wd{ID}_group`, so the group list's search and page survive. Back to Directory = the current URL without the group and `wd{ID}m_*`. Both end with `#wicket-directory-{ID}`. The member list form keeps the group and the group list's params as hidden fields, and Clear All and pagination work only on `wd{ID}m_*`. In the editor preview both links are `#`. `EntryMapper` sets `GroupEntry::$members_url` only in the Group List + Member List view and only when the card's `view_members` is on.
- **Guard.** Every member list (a link, and the Group Members view) first looks the group up with `DirectoryRepository::group()` (`uuid_in` + every eligibility key). Checked live: an excluded group, a group of another type, and an inactive group under Group Status Active all fall back to the group list. In the Group Members view, a group that can't be found (or none chosen) renders nothing for visitors and a notice in the editor.
- **Query.** `RosterQueryBuilder` (`people/query`): `group_members_group_uuid_eq`; Active adds `group_members_active_eq: true`, All adds nothing, and Inactive adds `uuid_not_in` with the group's active people. Those come from one `group_members/query` (`group_uuid_eq` + `active_eq: true`, 2000), cached like a page; past 2000 rows, or on failure, the list shows as unavailable rather than wrong. The keyword is the individual OR group plus `group_members_resource_type_slug_in` for roles whose label (`EnumLabels::group_member_roles()`) contains it. Sort is person keys only (`PersonQueryBuilder::person_sort_key()`). The group directory's `require_active_membership` (stored as `true` for groups) is never sent. `DirectoryRepository::fetch_roster()` shares `fetch()`'s cache, last-page fallback and error handling (the new private `load()`).
- **Render.** One render path still: `DirectoryRenderer` renders a member list through `directory.php` with `roster` set (a `GroupEntry` from `EntryMapper::roster_group()` and the back URL), plus new args `type`, `card` and `facets` for what is listed. Parts and cards read those before `config` (`card-individual` read `config->card['website_label']`, which a group card doesn't have). New part `parts/roster-header.php`: Back to Directory (`link` component), group type eyebrow, `h2` name ("Unnamed group" when empty), description. `wicket-directory--roster` modifier; `directory.css` styles the header with `--wd-*` tokens. The search placeholder is "Search by name, member ID or role".
- **Card.** Members map to `PersonEntry` with `DirectoryConfig::member_card()`: the individual defaults (name, job title) with every contact rule off, until P2.4 adds the Group Member card and its config. The roster is public and nobody has chosen which contact records it may show. The entry filters and `card_template` run with the group directory's config; the located card is `cards/card-individual`.
- **Hooks.** `query_args` and `entry` get the group config and the member list's `RequestParams`. `before_render` fires once per render with the params of what renders; for a member list it fires **after** the group lookup (an API request) and before the member query.
- **Block.** New attributes `rosterResultsPerPage` (0 = same as `resultsPerPage`; `BlockAttributes::roster_per_page()`) and `collapseRosterRefineOnMobile`. They apply in the Group List + Member List view; in the Group Members view the member list is the directory, so the main settings apply, and the Default Sort Order offers the person sorts. The sidebar reads the view from a new read-only `directory_view` REST field (view context; null for non-group directories) and shows a Member List panel only for Group List + Member List. Hide Location shows whenever there are member lists. Until P2.4 the member list has no facets, so its collapse toggle has nothing to collapse yet.
- **Admin.** Group panels: Default view, Group (Group Members only; the same group list as the exclude picker, saved for saved group directories), Group member status (views with member lists). `admin.js` toggles `[data-wd-view]` with the Default View select. `DirectoryMetaBoxes::SECTIONS` gains `roster`; the config meta's REST schema gains `roster`.
- **Checked** on the local stack against staging data (`wp eval-file`, directory 1897, config saved before this ticket): the list's View Members links; every group's member list (FIG C 5 people, as P2.0 found); All; Inactive (empty: nobody on staging is inactive); "chair" and "Past" on Test Group → its two immediate past chairs; last name Z–A at 2 per page; the Group Members view; the Group List view ignoring a link; and the guard cases above. Not checked in a browser: the editor sidebar, the admin toggles and the header's look.

<a id="p24"></a>

### P2.4 — Group Member card, with its own card config and facets

**Depends on:** P2.3

`templates/cards/card-group-member.php`: the individual card plus four group-membership fields that exist only on a roster row.

- Role (plain text, the `group_members` resource-type label), in-group status pill (from the group membership's active flag, not the person's account status), start date, end date ("Present" when absent). They come from one `group_members/query` batch per roster page; a person can have several rows, so use the first by `-start_date` (P2.0).
- Card config mirrors the individual card: profile image, role, membership status, start/end date, member ID, tier, title, employer, address (format, type, flags), email, phone, website.
- Its own facet set for the roster's Refine Results, separate from the group list's.
- Admin: in "Group List + Member List" mode, a switch above the Card View and Filters tabs picks Group Card or Group Member Card. In the other modes only the card that can be reached is shown.

**Acceptance criteria:**

- Editing one card's config or facets never changes the other's.
- The group-membership fields never appear outside the roster.
- Unit tests (`CardGroupMemberTest`, config sanitize).

**Outcome (2026-11-11):**

- **Config.** Two new sections, `member_card` and `member_facets`, beside `card` and `facets`. Every type stores them; types other than group keep the defaults, and a type change resets both (like `card` and `facets`). `member_card` has the individual card's keys plus `DirectoryConfig::MEMBERSHIP_FIELDS`: `role`, `status` (in-group), `start_date`, `end_date`. The defaults are P2.3's member card (the individual defaults with every contact rule off, since the list is public) plus the four group fields, on. `member_facets` takes data-field facets only (people have no org or group type). Each section is sanitized on its own, so saving one card never touches the other. Saved group directories read back with the defaults (the cache key changes once). `DirectoryConfig::member_card()` stays and returns the property; `shows_membership()` says whether any group field is on.
- **Employer is not in this ticket.** The individual card has no employer field yet; it comes with P2.6, and the Group Member card gets it from the individual card's keys then. Title is the existing job title.
- **Data.** New `GroupMemberEntry extends PersonEntry` (`PersonEntry` is no longer `final`): `role` (slug), `role_label` (the `group_members` resource type in the current language, else the slug), `status` (`active` / `inactive`, from the row's `active` flag), `start_date` (`Y-m-d`) and `end_date` (`Y-m-d`, or `GroupMemberEntry::END_PRESENT` for an open-ended term). Empty when the card hides them or the person has no row on the page. The entry filters get these keys too.
- **Batch.** `DirectoryRepository::fetch_roster()` adds one `group_members/query` per page when `shows_membership()` (`group_uuid_eq` + `person_uuid_in` the page's people, `sort=-start_date`, size 2000; `active_eq: true` in the Active view only), stored as `ResultPage::$group_members` and cached with the page. **Row choice:** each person's newest active row, else their newest row. That is P2.0's "first row by `-start_date`" in the Active and Inactive views, and in All it shows the term the person is in now rather than a newer ended one. The member card's tier toggle adds the person tier batch (no tier filter: group directories have none). A failed batch is logged; the page shows without it and isn't cached.
- **Facets.** `RosterQueryBuilder` sends the member list's facets as `search_query`, as `PersonQueryBuilder` does (new `QueryBuilder::facets()` hook); `FacetOptions::for_members()` gives their choices. They use the member list's `wd{ID}m_` params, so the two facet sets never read each other's selections. Hide Filters hides them too. No role facet: the keyword already matches roles.
- **Template.** `templates/cards/card-group-member.php`, located as `cards/card-group-member` (overridable at `{theme}/wicket-directory/cards/card-group-member.php`; `card_template` gets it). The individual card's structure with: the status pill (the `tag` component, `wicket-directory-card__status--active|inactive`, as on the group card) beside the name in `__title-row`; the role (`__role`) under the name; Start date / End date in the Details column, in the site's date format (`mysql2date()`), "Present" for an open-ended term. Each row hides when empty. `directory.css` reuses the group card's title row and pill; `__role` is muted like `__group-type`.
- **Admin.** For a saved group directory, Card View and Filters each hold two `data-wd-card` sections: the Group card and its facets, and the Group Member card (`member_card_fields()`, `member_card_groups()`: role and status, then the dates, after the name) and the member list facets (person enum data fields). The new `views/card-switch.php` ("Card to set up": Group Card / Group Member Card) sits above the tabs and shows only in Group List + Member List; there `admin.js` shows the chosen card. Group List shows only the group card, Group Members only the member card. Both sections are always printed and posted, so changing the view never loses settings; without the script both show. The second section's ids are prefixed (`wicket-directory-member-card-*`, `wicket-directory-member-facet-*`), and the Filters script now runs once per facet list. `DirectoryMetaBoxes::SECTIONS` and the config meta's REST schema gain both sections.
- **Checked** on the local stack against staging (`wp eval`, directory 1897, saved before this ticket): it reads back with the defaults; every group's member list renders the Group Member card with role, Active pill and dates (FIG C: 5 members, as in P2.0; Test Group: two Immediate Past Chairs); the edit screen prints both cards in both tabs with no duplicate ids, and the member facet picker offers staging's 3 person enum fields. Not checked in a browser: the switch and the section toggling in `admin.js`, and the card's look.

## 2. Card fields

<a id="p25"></a>

### P2.5 — Organization Contact on the org card (Membership Owner / Relationship Types)

**Depends on:** P2.0

Show who to contact for an organization on its card. Config under the org card:

- **Membership Owner** mode: the org membership's owner. Optional "Display relationship type": when relationship types are picked, show the owner only if they have an active relationship of one of those types to this org, and show the type label next to the name. With no types picked, always show the owner.
- **Relationship Type(s)** mode: every person with an active relationship of the selected type(s) to the org (any type when none are selected). Can be one person or a team.
- Per-contact toggles: name, title, email, phone. Contact records go through `ContactResolver`.
- Batch requests per page of orgs (from P2.0), cached with the page. Never per row. The owner is `organization_memberships.relationships.owner`, from the tier batch with `include=owner`; contacts are `connections/query` (`organization_uuid_in`, `active_eq`, `resource_type_slug_in`); emails and phones need one more `people/query` `uuid_in` request, because nested includes don't work.
- Decide which owner to show when an org has several active memberships with different owners (2 of 10 orgs on staging): the configured tiers' membership, every distinct owner, or one.
- The section disappears when nobody qualifies (no placeholder).

**Acceptance criteria:**

- Backwards compatible: org directories saved before this keep rendering without the section.
- Unit tests for both modes and the relationship-type filter rules.

**Outcome (2026-11-12):**

- **Config.** The organization card gains `org_contact`: `show` (off by default, so configs saved before this read back and render without the section), `source` (`DirectoryConfig::ORG_CONTACT_OWNER` | `ORG_CONTACT_RELATIONSHIP`), `relationship_types` (slugs), the toggles `name`, `title` and `relationship` (on), and `email` / `phone` contact rules (off: they are a person's own records on a public card). `DirectoryConfig::org_contact()` returns the settings when the section is on, else null. Only organization cards have the key; a type change resets it with the rest of the card.
- **Owner rule (the open question).** When the directory is limited to tiers, only the owners of memberships in those tiers count, as on the tier line. Otherwise every distinct owner of the org's active memberships shows, newest membership (`starts_at`) first. With relationship types chosen, an owner shows only with an active relationship of one of them to *that* organization, matched per (person, org) pair, and their type labels show next to the name. With none, the owner always shows, with no label.
- **Relationship Types mode.** Everyone with an active relationship of the chosen types (any type when none) to the org; a person with several matching types is listed once with all of them. Order: the first matching type in the admin's order, then family and given name. At most `OrganizationContacts::MAX_PER_ORG` (10) per card, so "any type" on a large org doesn't list its whole staff.
- **Batches.** New `Api\OrganizationContacts`, called from `DirectoryRepository::fetch()`. Owners come from the tier request, which now asks `include=membership,owner` (one `organization_memberships/query` serves the tier line and the owners). Then at most one `connections/query` (owners with types: `person_uuid_in` the owners, no include; Relationship Types: `include=person`) and one `people/query` `uuid_in` + `include=emails,phones` when the section shows an email or phone. Stored on `ResultPage` (`org_contacts`, `contact_people`, `contact_included`) and cached with the page. A failed batch is logged; the page shows without the section and isn't cached. Pages cached before this read back without contacts.
- **Data.** `OrganizationEntry::$contacts` (after `$extra`, so positional callers are unaffected): a list of `{id, name, title, relationship, emails, phones}`. Name is given + family (else `full_name`), title is `job_title`, `relationship` the comma-joined type labels (`EnumLabels::relationship_types()`, resource type `connection_person_to_organizations`, slug when unlabelled). Email and phone go through `ContactResolver` with the section's own rules. A person with nothing to show, or missing from the batch, is left out. The entry filters get `contacts`; `Entry::with()` keeps each one in that shape and drops any with nothing to show.
- **Template.** `cards/card-organization` gets a third column after Address and Contact, `wicket-directory-card__people`, headed "Organization Contact" / "Organization Contacts" (`_n`). Each person: name and relationship type on one line, then the title, email and phone (the `link` component, as the org's own). No column when nobody qualifies. `directory.css` adds the person spacing; tokens only.
- **Admin.** An "Organization Contact" item last in the organization card's Card View: source radios, a relationship-type chip picker (from `resource_types`; a saved type the MDP no longer lists stays, marked), a hint per source, the three toggles, and an email and a phone contact rule. The contact rule markup moved to `views/contact-rule.php`, shared with the card's own rules. `admin.js` now scopes each Card View item to its own elements, because this item nests two contact rules, and shows the hint of the chosen source (`data-wd-mode`).
- **Checked** on the local stack against staging (`wp eval-file`, directory 1883, config built in memory, cache off): Membership Owner lists an owner on all 13 orgs with the one membership request; with email and phone, one extra `people/query` gives their primary records; with types `employee` / `primary-contact` nobody shows, as P2.0 found (no owner has an active relationship to their own org); Relationship Types (any) lists people on 7 orgs, by family name, with their type labels; `employee` only, 3 orgs. Not checked in a browser: the admin item and its script, and the column's look.

<a id="p26"></a>

### P2.6 — Individual card: Employer, Designation and relationship types

**Depends on:** P2.0

Add the individual-card fields the prototype has but the MVP left out (see 5.5 in [mvp-tickets.md](mvp-tickets.md#55)).

- Employer: the person's organization from connections (an active connection of the selected relationship types), legal name in the current language. One `connections/query` batch with `person_uuid_in` and `include=organization` (P2.0). "Primary organization" is unverified: `primary_organization` is null for every person on staging, so decide whether to support it before building it.
- Designation: a data field appended to the name line (no stray comma when empty).
- Person ⟷ Org relationship types: which types count for Employer, and an option to show the type label.
- Decide whether the prototype's "Display Contact Details" parent toggle is needed on top of the per-field toggles.
- Admin Card View items for each. One batch request per page for connections.

**Acceptance criteria:**

- Backwards compatible: existing individual configs render as before.
- Unit tests for the mapper and the card.

## 3. Advanced card editor

<a id="p27"></a>

### P2.7 — Merge-tag card template engine (`[col]` / `{{field}}`)

**Depends on:** P2.4, P2.5, P2.6

Server-side engine for the prototype's `[col]`/`{{field}}` card templates, so a card can be laid out by hand (Advance Configurations). Standard Configurations generate the same kind of template, so both paths share one renderer.

- Template syntax: `[col]` columns and `{{field}}` merge tags.
- Resolver chain, first match wins:
  1. computed pseudo-fields (`group_active_member_count`, `group_status_pill`, `group_member_*`, `org_contact[...]`);
  2. typed-bracket contact tags (`addresses|emails|phones|websites[primary|type=…,consent=…,primaryOnly=…].field`, through `ContactResolver`);
  3. the field catalog for the entity type;
  4. legacy aliases;
  5. an empty string (never an error or a raw `{{tag}}`).
- Field catalog per type, grouped Profile / Addresses / Email / Phone / Membership / Connections / Data Fields; it backs the admin field picker.
- Output escaped; only computed fields may emit markup.
- Fits what exists: `wicket_directory/entry` still applies, theme card overrides keep working, still one render path.

**Acceptance criteria:**

- A Standard config's generated template renders the same card as the MVP PHP template.
- Unknown tags render empty.
- Backwards compatible: existing directories render as before.
- Unit tests for each resolver step.

<a id="p28"></a>

### P2.8 — Admin Advance Configurations editor

**Depends on:** P2.7

Card View gets Standard / Advance modes.

- Advance shows the raw `[col]`/`{{field}}` template, with a "Select a Field…" picker (from the catalog) that inserts tokens, and "Use Custom Field Path".
- A dirty flag per template (main card, Group Member Card), set when the admin edits the Advance text or inserts a field. A flag, not a string diff.
- Switching Advance → Standard while dirty warns that the custom layout will be lost. No warning otherwise.
- Saved and sanitized with the rest of the config.

**Acceptance criteria:**

- Standard-only directories save as before.
- The editor posts without the script (textarea fallback).
- Unit tests for sanitizing and the dirty-flag rules.

## 4. Admin

<a id="p29"></a>

### P2.9 — Developer tab: custom code switch, notes, hook reference and custom fields

A Developer tab on the edit screen, so anyone can tell at a glance that a directory runs custom code. It doesn't write or run code.

- "Custom code" on/off switch and a Developer Notes field, saved in the config.
- Hook reference for this directory: the `wicket_directory/*` hooks with their slug-scoped names, each with a Copy button.
- Registered Custom Fields: fields developers register through a new filter (e.g. `wicket_directory/fields_{slug}`), listed for visibility. Document the filter in AGENTS.md.
- A "Custom Code" pill on the All Directories list when the switch is on.

**Acceptance criteria:**

- No change to rendering.
- Unit tests for config sanitize and the hook list.

<a id="p210"></a>

### P2.10 — Admin live card preview

**Depends on:** P2.7

A live preview on the edit screen that renders a sample card as settings change, before saving.

- Rendered server-side by the same `DirectoryRenderer` and card templates (one render path), e.g. through an admin REST endpoint that takes the unsaved config.
- One sample card (for a roster, one Group Member card), from a real API record when available.
- Desktop / Tablet / Mobile width toggle (preview chrome only, not a setting).

**Acceptance criteria:**

- The preview matches the frontend and the block editor preview for the same config.
- Capability and nonce checks on the endpoint.
- Unit tests for the endpoint.

## 5. Acceptance

<a id="p211"></a>

### P2.11 — Phase 2 acceptance: theme check, docs and Atlas

**Depends on:** P2.1–P2.10

- Theme compatibility check for the group, group member and org contact cards: Wicket theme v2 and a default theme, light/dark, desktop and mobile.
- Manual check on the local stack against the staging MDP: a groups directory in each Default View mode, a roster, org contacts in both modes, an Advance template, the Developer tab and the live preview.
- Confirm MVP directories on a client-like setup (CHFA org directory) are unaffected.
- Update docs/engineering (this file, a Phase 2 plan if one is written, api-queries.md), AGENTS.md, and the Atlas package doc with the new hooks, block attributes and config keys.

**Acceptance criteria:**

- `wicket test unit:directory` passes.
- No breaking change to existing directories.
