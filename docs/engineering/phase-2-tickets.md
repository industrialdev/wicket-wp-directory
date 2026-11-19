---
title: "Phase 2 Tickets"
audience: [developer, agent]
updated: 2026-11-19
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
| P2.6 | [Individual card: Employer, Designation and relationship types](#p26) | P2.0 | Done |
| P2.7 | [Merge-tag card template engine (`[col]` / `{{field}}`)](#p27) | P2.4, P2.5, P2.6 | Done |
| P2.8 | [Admin Advance Configurations editor](#p28) | P2.7 | Done |
| P2.9 | [Developer tab: custom code switch, notes, hook reference and custom fields](#p29) | — | Done |
| P2.10 | [Admin live card preview](#p210) | P2.7 | Done |
| P2.11 | [Phase 2 acceptance: theme check, docs and Atlas](#p211) | P2.1–P2.10 | Done |

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

**Outcome (2026-11-12):**

- **Primary organization (the open question): not supported.** `primary_organization` is null for every person on staging and nothing in the stack reads it, so it can't be verified. Employer is built from active connections only. It can be added later as a source if a tenant turns out to use it.
- **Config.** The individual card gains `employer`: `show` (off by default, so configs saved before this read back and render as before), `relationship_types` (slugs; empty = any active person ⟷ organization relationship counts) and `relationship` (show the type label next to the organization; off). The Group Member card gets the same key through the individual card's keys, as P2.4 planned, with its own settings. `DirectoryConfig::employer()` returns the individual card's settings when on, `employer(true)` the Group Member card's (group directories only); else null. Organization and group cards have no key; a type change resets it with the rest of the card.
- **Batch.** New `Api\PersonEmployers`, called from `DirectoryRepository::fetch()` (individual) and `fetch_roster()` (Group Member card): one `connections/query` per page (`connection_type_eq`, `person_uuid_in` the page's people, `active_eq: true`, `resource_type_slug_in` when types are chosen; `include=organization`, size 2000). Rows of other types or inactive rows are ignored in PHP too. An organization with several matching relationships is listed once with every type. Order: the first matching type in the admin's order (when types are chosen), then the organization's name in the current language. At most `PersonEmployers::MAX_PER_PERSON` (5) per card: one staging person has 17 connections. Stored on `ResultPage` (`employers`, `employer_organizations`, only the organizations a card names) and cached with the page. A failed batch is logged; the page shows without employers and isn't cached. Pages cached before this read back without employers.
- **Data.** `PersonEntry::$employers` (after `$extra`, so positional callers are unaffected; `GroupMemberEntry` passes it through): a list of `{id, name, relationship}`. Name is `legal_name_{lang}`, else `legal_name`, else `legal_name_en`; an organization with no name is left out. `relationship` is the comma-joined type labels (`EnumLabels::relationship_types()`, slug when unlabelled), empty when the card hides it. The entry filters get `employers`; `Entry::with()` keeps each one in that shape and drops any without a name.
- **Designation** is the existing `post_nominal` data field, which already appends ", {value}" to the name and adds nothing when empty. No new key: the Card View item is relabelled "Designation", with "post-nominal letters" in its description.
- **"Display Contact Details" parent toggle: not added.** Each contact rule (address, email, phone, website) already has its own Show toggle, and the Contact column disappears when all are empty, so a parent toggle would only duplicate them.
- **Template.** `cards/card-individual` and `cards/card-group-member` list the employers in the Details column after the job title (and before the Group Member card's dates): one `wicket-directory-card__employer` per organization, with `__employer-name` and, when set, `__employer-relationship` (muted) on the same line. The Details column now shows for employers alone. `directory.css` adds the row; tokens only.
- **Admin.** An "Employer" item after Job title in the individual card's Card View, and in the Group Member card's: a Show checkbox that reveals a relationship-type chip picker (from `resource_types`; a saved type the MDP no longer lists stays, marked), a hint, and the "Relationship type, next to the organization" toggle. `admin.js` needed no change (a generic `data-wd-item`).
- **Checked** on the local stack against staging (`wp eval-file`, directory 1818 saved before this ticket, config built in memory, cache off): it reads back with Employer off; on, it adds one `connections/query` per page; with any type, 3 of 50 people show employers (Rob Ferguson hits the cap of 5); `employee` only, 2; French names and labels (Employé, Contact général); on group 1897's member lists, one Test Group member shows an employer. Not checked in a browser: the admin item and the card's look.

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

**Outcome (2026-11-13):**

- **Render path (the open question): the engine renders Advance cards only.** Standard cards keep their PHP card templates, so existing directories render exactly as before and theme overrides of `cards/card-*.php` keep working. A card whose mode is Advance renders through `cards/card-template.php` instead: the same `DirectoryRenderer` path, the same `wicket_directory/card_template` filter (its located file is then `cards/card-template.php`), and the same `component-card-listing` / `wicket-directory-card__*` shell, plus `wicket-directory-card--template`. "Both paths share one renderer" holds through the generator: a Standard config writes the template it stands for, and that template renders the same card content through the engine (tested). P2.8 prefills Advance with it.
- **Config.** Every card, and a group directory's `member_card`, gains `mode` (`standard`, the default; `advanced`) and `template` (plain text: tags stripped, line breaks normalized to `\n`, at most `DirectoryConfig::TEMPLATE_MAX_LENGTH` = 20000 characters). Configs saved before this read back Standard. `DirectoryConfig::template()` gives the main card's Advance template (`template(true)` the Group Member card's), else null. A type change resets both with the rest of the card. Until P2.8 adds the editor, the Card View posts both back in hidden inputs, so a save keeps them; they can only be set in code for now.
- **What an Advance card shows: its template decides.** `DirectoryConfig::for_render()` (called once in `DirectoryRenderer::render()`) returns a copy whose Advance cards' toggles follow the template's tags (`FieldCatalog::toggles()`): a used field's toggle on, an unused one off. So the batches (tiers, owners, employers, member counts, group rows) run only for what the template prints, and `EntryMapper` fills exactly that. Settings that aren't toggles (contact rule types and flags, data-field references, address format, website label, Employer and Organization Contact options) keep their saved values. Options of another field (the name parts of `{{name}}`, the options of `{{org_contact}}`) are turned on by a tag of their own (`{{salutation}}`, `{{org_contact[1].email}}`) but never off. The stored config never changes, so switching back to Standard finds the toggles as they were.
- **Syntax** (`Template\CardTemplate`). One card row per line. `{{tag}}` is a merge tag (spaces inside the braces ignored); everything else is literal text, escaped, so HTML in a template shows as text. `[col]` … `[/col]` is a column; consecutive columns form one row (`__columns`); a `[col]` inside an open column starts the next, an unclosed one ends with the template, a stray `[/col]` is ignored. `# ` starts the title (h3), `## ` a heading (h4). Rules decided here: a line whose tags are all empty is left out (text-only lines stay); `, ` and ` | ` split a line into segments that drop on their own, so `{{name}}, {{post_nominal}}` and `ID: {{member_id}} | {{tiers}}` never leave a stray separator; a line holding a list field (emails, phones, addresses, websites, employers…) repeats once per item of the first one, other list fields on it comma-joined; a tag alone on its line prints its block form (website buttons in `__actions`, tag chips, the description with Show more / Show less, `<address>`es, employer rows, the Organization Contact list); a column with tags but nothing shown is left out. Lines outside columns form groups: the one holding the title is the `__header`, others `__rows`; the image, description, website buttons and tag chips stand between them; an `{{image}}` before anything else sits beside the content, as on the Standard card. Parsed once per request per template.
- **Resolver chain** (`Template\MergeTags`), first match wins: (1) computed fields, the only ones that print markup: `image`, `email`, `phone`, `address`, `website_button`, `employer`, `tag_chips`, `description`, `group_active_member_count` (the number), `group_active_members` ("12 active members"), `group_status`, `group_status_pill`, `view_members_button`, `group_member_role|status|status_pill|start_date|end_date`, `org_contact` (the Standard list) and `org_contact[n].name|title|relationship|email|phone` (n from 1); (2) typed contact tags `addresses|emails|phones|websites[options].attribute`, options `primary` / `primaryOnly=…`, `consent` / `consent=…` (show-in-directory) and `type=…`, comma-separated, mapped to a contact rule for `ContactResolver` (no option: its default, the first show-in-directory record, else the first primary one). They read the raw records from the page's `included`, whatever the card's contact toggles say, and print scalar attributes as text; (3) the catalog's plain fields, read from the entry after the `wicket_directory/entry` filters, plus `data_fields.{schema_key}.{field}` (enum keys as labels, from the raw resource) and `extra.{key}` (a scalar, or a list of scalars, a filter put under `extra`); (4) `FieldCatalog::ALIASES` (`first_name`, `last_name`, `full_name`, `legal_name`, `organization_name`, `title`, `designation`, `membership_tier`, `logo`, `member_count`, `role`…); (5) empty. A tag that belongs to another type is empty. Every value is escaped; components (`link`, `button`, `tag`) get escaped args, as in the PHP cards.
- **Catalog** (`Template\FieldCatalog`): per type (and the Group Member card), tag => label, group and toggles. Groups: Profile, Group, Addresses, Email, Phone, Website, Membership, Connections, Data Fields (Website and Group added to the ticket's list). It lists example typed contact tags and, given `Admin\SchemaFields`-style data fields, `data_fields.*` tags; `grouped()` is the P2.8 picker's shape.
- **Generator** (`Template\TemplateGenerator::generate()`): only the fields the card's toggles turn on, in the PHP card's order and columns, headings and labels in the current language. Turning that template into an Advance one gives back the Standard card's toggles exactly (tested for each type, defaults and everything on).
- **Checked** in unit tests only: the Standard-vs-generated comparison renders both card paths for each type and checks they show the same values in the same order (and the structural classes match). Not checked on the local stack against staging or in a browser: there is no editor to set a template yet (P2.8), and the Advance card's look has only the existing card CSS plus spacing for `__rows` and `__line`.

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

**Outcome (2026-11-17):**

- **Config.** Every card (and `member_card`) gains `template_dirty`. `DirectoryConfig::LAYOUT_KEYS` lists the three layout keys (`mode`, `template`, `template_dirty`), which the editor sets and the checklist doesn't. Dirty-flag rules, applied in `sanitize()`:
  - An empty template is never dirty.
  - A flag that is missing or invalid counts as dirty when the template isn't empty. That covers configs saved before P2.8 and the editor without its script, which posts no flag. A template someone wrote is never replaced.
  - **An Advance template that isn't dirty is the generated one.** `sanitize()` writes it again from the card's toggles (`TemplateGenerator`), on save and on read. So it follows the Standard checklist and the current language's labels until the admin edits it. An empty Advance template stays empty and renders the Standard card, as in P2.7.
  - A Standard card keeps its template as posted. A type change resets all three keys with the card.
- **Card View.** A Layout switch (Standard / Advance, the `mode` radios) sits above each card's checklist; a group directory has one per card. Both panels always post, so the checklist keeps its settings while the card is in Advance (the generator reads them).
- **The Advance panel:**
  - the template in a textarea (`[template]`);
  - a "Select a Field…" picker: `FieldCatalog::grouped()`, plus the card's schema data fields as `data_fields.{schema}.{field}`, labelled "Schema › Field";
  - "Use Custom Field Path": a text field that inserts any tag, braces and spaces stripped;
  - a syntax help line;
  - the generated template for the saved toggles (`data-wd-generated`).

  The picker and the path field don't post. The `[template_dirty]` hidden input is disabled until the script enables it.
- **Script** (`assets/js/admin.js`):
  - Typing in the template or inserting a field sets the flag.
  - Standard → Advance fills the template with the generated one, unless it's dirty and not empty. If the checklist changed since the page loaded, a note says the saved toggles were used and saving applies the changes.
  - Advance → Standard clears the template and the flag. When the template is dirty it first asks with `confirm()`; Cancel stays in Advance. There is no warning otherwise.
- **Without the script** both panels show and the textarea posts. No flag posts, so the template counts as edited and is kept.
- **Checked:**
  - Unit tests in `qa`: `CardEditorTest` (dirty-flag rules, editor markup, saves, the no-script post). The card-key tests and form-post helpers are updated for `template_dirty` and textareas.
  - The script ran in a browser against the real markup on a static page: switch, prefill, stale note, picker, custom path, confirm with Cancel and OK, and a group directory's two editors.
  - Not checked in WP admin on the local stack. QA has no JS unit tests, so the script's rules have no automated test.

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

**Outcome (2026-11-17):**

- **Config.** A new `developer` section, `{custom_code: bool, notes: string}`, defaults `false` / `''`, so configs saved before P2.9 read back without custom code. The notes are plain text: tags stripped, line breaks normalized to `\n`, at most `DirectoryConfig::NOTES_MAX_LENGTH` (5000) characters. The section describes the directory, not its type, so a type change keeps it. `DirectoryConfig::has_custom_code()` reads the switch. Nothing on the render path reads the section; `for_render()` carries it unchanged. The config meta's REST schema lists the `developer` key, like every other top-level key (its top level has `additionalProperties: false`).
- **Developer tab** (`views/developer.php`, after Filters; section `developer` in `DirectoryMetaBoxes::SECTIONS`). Three panels:
  - **Custom code**: a switch (a checkbox with `role="switch"` and its hidden "0"), the Developer Notes textarea, and a line saying the tab never runs, writes or turns off code.
  - **Hook reference** (`Admin\DeveloperTab::hooks($slug)`): every `wicket_directory/*` hook in render order, with its kind, arguments and a one-line description, and, where it has one, its "This directory only" name, `{name}_{slug}`. A unit test scans `src/` for `apply_filters` / `do_action` calls, so the list can't drift from the hooks the plugin fires. Before WordPress sets a slug (an unpublished directory) the names end in `{slug}` and a note says so; otherwise a note warns that changing the slug renames them.
  - **Registered custom fields** (`DeveloperTab::fields()`): the new filter, below. Each field shows its label, description and merge tag `{{extra.key}}`. With none, the panel explains how to register them.

  The Card View's Advance field picker also lists them, last, in a Custom Fields group (`DirectoryMetaBoxes::custom_field_group()`; options `extra.{key}`), on both of a group directory's cards. The filter runs once per Card View. A custom field needs no card toggle, so picking one never changes what a render fetches.

  Each name and merge tag has a Copy button. The buttons are `hidden` until `admin.js` finds the Clipboard API (secure contexts only). When the browser refuses the write, the script selects the name instead, ready for Ctrl/Cmd+C.
- **New filter: `wicket_directory/fields`, then `wicket_directory/fields_{slug}`.** Args: `$fields` (`[]`), `DirectoryConfig`, the directory `WP_Post`. A callback registers fields by the key its `wicket_directory/entry` callback uses under `extra`: `$fields['chapter'] = 'Chapter'`, or `['label' => …, 'description' => …]`, or a list item with its own `key`. A key may hold letters, digits, `_` and `-` (what a merge tag needs); other entries are dropped, and the first of a repeated key wins. A field without a label shows its key. Only the edit screen runs the filter (the Developer tab and the field picker); rendering never does. Documented in AGENTS.md.
- **All Directories.** A "Custom Code" pill (`.wicket-directory-pill`) under the type in the Type column when the switch is on. `ListColumns` loads `admin.css` on that screen for it.
- **Checked:**
  - Unit tests in `qa`: `DeveloperTabTest` (config defaults, coercion, notes sanitizing, type change, render copy; the hook list and its match with the source; the fields filter's order and normalizing; the tab's markup and saves; the picker's Custom Fields group on one and two cards; the pill and the list-screen style; the REST schema). `DirectoryMetaBoxesTest`, `GroupConfigTest` and `RosterConfigTest` expect the new `developer` key in the stored shape.
  - The box, rendered by `wp eval` on the local stack for a real directory (with a test filter and an injected config; nothing saved), viewed on a static page with WP admin's CSS and `admin.js`: switch, Copy (both the clipboard and the refused path), fields table, and no overflow at 375px.
  - The Card View of a real group directory, rendered by `wp eval` with a test filter: both pickers list the Custom Fields group.
  - Not checked in WP admin itself: the browser pane doesn't trust the local stack's certificate.

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

**Outcome (2026-11-19):**

- **Endpoint** (`Admin\CardPreview`): `POST /wicket-directory/v1/directories/{id}/preview`, registered on every request (REST requests aren't admin requests).
  - Permission: `manage_options` (the directory capability) and `edit_post` on the directory. WordPress's cookie authentication needs the `wp_rest` nonce (`X-WP-Nonce`); without it the request is logged out and refused (401). A post that isn't a directory, or is trashed, gets a 404.
  - Body: `wicket_directory[...]` (the settings box's fields), `card` (`card` or `member`), `sample` (which matching record, from 1) and `frame`.
  - The posted sections go over the stored config and through `DirectoryConfig::sanitize()` with the stored config as `$previous`, exactly as the save handler does (`CardPreview::config()`). So a type change resets the card, and an untouched Advance template follows the posted checklist. Nothing is saved, and the directory's cache isn't touched.
  - Returns `{html, number, total, sample, group}`: the card, the record shown of how many match, the sample reason (empty for a real record) and, for the Group Member card, the group's name.
- **One render path** (`DirectoryRenderer::render_preview()`): `for_render()`, the repository with the `query_args` filters, `EntryMapper` with the `entry` filters, the card template the `card_template` filter picks. The card is the one the listing renders, character for character (tested against the frontend and the block editor preview). What differs:
  - One record: a page of one, the `sample`th, so Previous / Next step through what the settings match.
  - No cache: `DirectoryRepository::uncached()`. Unsaved configs would each leave a transient behind until the next save, and a cached page could hide a change.
  - `before_render` doesn't fire: it prepares a listing, and nothing here prints one. Links go to `#`.
  - Draft directories preview too.
  - The card sits in the directory's own wrappers (`templates/parts/card-preview.php`: `.wicket-directory--preview` > `__results` > `__list` > `__item`), so its container queries apply. A theme can override the part to match an override of `directory.php`.
  - A group directory's Group Member card shows a member of the Group Members view's group (looked up with every eligibility key), else of the first group in the list.
- **Sample record** (`Render\PreviewSample`): when nothing matches the settings, the API fails, or a member card has no group, the card shows a made-up record and the status line says why. It is a raw MDP page (one resource, its included contact records, and the tier, employer, Organization Contact and group member batches the card asks for), so it goes through the same mapper, filters and templates. Each contact record matches its card rule, so every contact field that's on shows. Data fields the card shows get their first enum label, else the field name. Types and roles are the configured ones, else the first the MDP lists, so their labels are real. Images stay empty.
- **Frame.** The card shows in an `iframe` whose document (`views/preview-frame.php`, sent once with the first response as `srcdoc`) holds the site's stylesheets. The endpoint runs `wp_enqueue_scripts` as a page does, then prints only styles (with their inline CSS, so the theme's tokens and global styles come along), `directory.css`, font faces and the Customizer's Additional CSS, with the admin bar off. No scripts: the card needs none, and analytics or consent banners stay out. So the card looks as on the site, and the theme's media queries see the frame's width.
  - Without the theme's script there is no Alpine, so a long description shows in full, not as an excerpt with Show more (the cards' no-script state).
- **Panel** (`views/preview.php`, first in the Card View; one panel for a group directory's two cards, following the card switch):
  - Desktop / Tablet / Mobile buttons: 1280, 768 and 375 px. The frame keeps that width and is scaled down to fit the panel. The choice is remembered (localStorage) and isn't a setting.
  - A status line ("Record 3 of 186, from Wicket.", "Member 1 of 4 in Board, from Wicket.", or the sample reason) and Previous / Next record.
  - Hidden without the script, and while the type select differs from the saved type (the Card View asks for a save then). Nothing in it posts.
- **Script** (`assets/js/admin.js`):
  - Any input, change or button click in the settings box schedules an update 400 ms later.
  - The update posts only the sections that shape the card (type, eligibility, card, member_card, roster). It is skipped when they haven't changed since the last one, and a newer request aborts an older one.
  - It only runs while the panel is on screen (IntersectionObserver): not on another tab, in a closed box or scrolled away.
  - A failed update says so and keeps the last card. The heartbeat's `rest_nonce` keeps the nonce fresh on a long edit.
- **Checked:**
  - Unit tests in `qa`: `CardPreviewRenderTest` (one record and its number, wrappers, same card as the listing and the block editor preview, unsaved settings, drafts, no cache, the filters and no `before_render`, Advance cards, View Members, the Group Member card in both views and without a group, each sample reason and what the sample fills, errors) and `CardPreviewTest` (route, `rest_api_init`, permissions, 404s, the posted settings, nothing saved, the frame document and a failing `wp_enqueue_scripts` callback, `config()`, the panel's markup). The Directory test bootstrap gains `WP_REST_Request` and `WP_Error` shims; `AbstractTestCase` stubs `rest_url`, `wp_create_nonce` and `wp_json_encode`.
  - On the local stack against the staging MDP, through `rest_do_request()` for the three real directories: real records for individual, organization (with Organization Contact) and group cards, and a Group Member card; 401 when logged out. The frame document holds the theme, child theme and `directory.css` stylesheets and no script.
  - The script, in a browser against the real settings box markup (rendered by `wp eval`) with the endpoint mocked by those responses, and the frame loading the stack's real stylesheets. Checked: first load, the debounce, skipping an unchanged form, the widths and scaling, Previous / Next with wrap-around, a failed request, hiding on a type change, links in the card doing nothing, the group card switch, and the Advance prefill and typing.
  - Not checked in WP admin itself: the browser pane doesn't trust the local stack's certificate. QA has no JS unit tests, so the script has no automated test.

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

**Outcome (2026-11-19):**

- **No breaking change to MVP directories.** The same directories were rendered with the plugin as it was before Phase 2 (commit `2be4474`, P2.0 docs) and with `main`, against the staging MDP, with the cache off.
  - Two directories hold the config the MVP code saves: its own `sanitize()->to_array()`, so no Phase 2 key. One is an individual directory. One is CHFA-like: active membership, org type and data-field facets, a data-field eyebrow, chips and a website label.
  - Eleven requests: default, keyword, location (individual), a facet, the last sort option and page 2. The HTML is the same apart from whitespace.
  - The block's attributes and `directory.css` changed by additions only (`rosterResultsPerPage`, `collapseRosterRefineOnMobile`; group, member list, Organization Contact and Advance card rules).
  - The CHFA directory itself can't be rebuilt here: staging has no `orgmemberdir` schema. That stays with MVP ticket 7.2.
  - New unit test `Phase2CompatibilityTest` pins this: the two MVP-saved configs read back with every MVP key unchanged and every Phase 2 feature off, survive a save with no changes, and the MVP block attributes keep their meaning.
- **Phase 2 on the local stack against staging** (`wp eval-file`, cache off, temporary directories deleted afterwards):
  - **Default View.** Group List: the groups, no View Members, and a `wd{ID}_group` link is ignored. Group Members: the chosen group's members; with no group chosen, nothing for visitors. Group List + Member List: View Members links, and each group's member count matches its member list (FIG C: 5).
  - **Member list** (FIG C). Last name Z–A; 2 per page through `rosterResultsPerPage`, page 2 shows 3–4 of 5; Order by offers the four person sorts. Over HTTP, Back to Directory drops `wd{ID}_group` and the `wd{ID}m_` params and keeps the others. An unknown UUID and an excluded group fall back to the list. Inactive member status is empty (nobody on staging is inactive).
  - **Organization Contact.** Relationship Types (40 contact rows on the first 6 cards), Membership Owner (25 rows), and off (none).
  - **Employer.** Shows on the individual card (first on page 2).
  - **Advance templates.** The template generated from the individual card's toggles. A hand-written one with columns, a typed contact tag, `{{employer}}` and `{{extra.p211}}` filled by a `wicket_directory/entry` callback. An organization template with `{{org_contact}}`. Each renders six `--template` cards.
  - **Developer tab** (`render_developer_box()` as an admin): the hook reference with the `_{slug}` names, the notes, the switch, and a `wicket_directory/fields` field with its `{{extra.key}}` tag.
  - **Live preview** (`rest_do_request()` as an admin): real records for the individual, organization (record 2 of 22), group and Group Member cards (with the group's name); an unsaved Advance template; the `none` sample when nothing matches; the frame document. 404 for a page that isn't a directory, 401 logged out, and the stored config unchanged.
  - **Org data-field facets** are now verified with matching records ([api-queries.md](api-queries.md#data-fields-opt-in-and-facets)).
- **Docs.** This file; api-queries.md (org data fields); AGENTS.md (status, no more "planned" markers); README.md (groups, Phase 2); the Atlas package doc (block attributes, status) and the MVP plan's artifact line.
- **Tests.** `composer test:unit:directory` in the stack's PHP container: 1062 passed. `wicket test unit:directory` first asks for `wicket test --setup` (test environment last set up 2026-11-04).
- **Theme check** (group, Group Member and Organization Contact cards; Wicket theme v2 and a default theme; light and dark; desktop and mobile): done by hand in a browser on the local stack, no issues found. The Wicket theme has no dark mode, so light and dark apply to the default theme's style variations.
