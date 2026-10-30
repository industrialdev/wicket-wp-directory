---
title: "Phase 2 Tickets"
audience: [developer, agent]
updated: 2026-10-30
---

# `wicket-wp-directory` Phase 2 tickets

Phase 2 build tickets, taken from the backlog in [mvp-plan.md](mvp-plan.md) (step 7) and the designer's "Member Directory Block" prototype. IDs are `P2.{n}`. Refer to a ticket by its ID, e.g. "let's do ticket P2.3". The same IDs prefix the task names in Asana's **BUILD: Phase 2** section (`[Directory P2.3] …`), where the dependencies are linked too.

There is no approved Phase 2 plan yet. These tickets are the scope; settle open design questions in the ticket that raises them and record the outcome here.

Every ticket must stay backwards compatible: existing Individual and Organization directories, their saved config, their block attributes and their GET params keep working unchanged. Unit tests go in `qa/tests/Unit/Directory/` with each ticket, as in the MVP.

Keep the **Status** column current: `To do`, `In progress`, `Done`.

## Index

| ID | Ticket | Depends on | Status |
|---|---|---|---|
| P2.0 | [API spike: groups, group members, connections and membership owner](#p20) | — | To do |
| P2.1 | [Groups directory type: config, query builder and eligibility](#p21) | P2.0 | To do |
| P2.2 | [Group card template](#p22) | P2.1 | To do |
| P2.3 | [Group roster page: Default View, Back to Directory, own search, filters, sort and paging](#p23) | P2.2 | To do |
| P2.4 | [Group Member card, with its own card config and facets](#p24) | P2.3 | To do |
| P2.5 | [Organization Contact on the org card (Membership Owner / Relationship Types)](#p25) | P2.0 | To do |
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

## 1. Groups

<a id="p21"></a>

### P2.1 — Groups directory type: config, query builder and eligibility

**Depends on:** P2.0

Add a third directory type, Group, alongside Individual and Organization.

- `DirectoryType::Group`, with sort options Name A–Z / Z–A.
- Group eligibility config: Group Status (All / Active / Inactive, default Active), group types (chip picker), and "Groups which shouldn't be displayed" (an exclude list, stored by UUID, picked by name).
- `GroupQueryBuilder` (extends `QueryBuilder`) → `DirectoryQuery`; keyword on the group name; facets.
- `DirectoryRepository` support, including the per-page active member count batch from P2.0.
- `EntryMapper` → `GroupEntry` (name, status, type label, description, active member count). The `wicket_directory/entry` filter applies.
- Admin: Group in the Type select, the group eligibility panels, and Card View / Filters tabs showing group fields only.
- Block editor: group directories in the picker, sort options per type.
- The status and exclude filters apply everywhere a group from this directory can be reached (listing, roster link, Default View).

**Acceptance criteria:**

- Backwards compatible: existing Individual and Organization directories, their saved config and their GET params behave exactly as before.
- An Inactive or excluded group never appears, including through a direct link.
- Unit tests for the builder (eligibility, keyword and sort combinations), config sanitize and the type-change reset.

<a id="p22"></a>

### P2.2 — Group card template

**Depends on:** P2.1

`templates/cards/card-group.php`, theme-overridable like the other cards.

- Layout per the prototype: name and status pill inline, group type below, active member count and a "View Members" button right-aligned on the same row. No image.
- Card toggles: status pill, group type, member count, description, View Members button (when off, the card has no way to reach the roster).
- Same rules as 3.6/3.7: base components, `--wd-*` tokens only, container query, everything escaped.

**Acceptance criteria:**

- Each toggle hides its row; empty values hide their row.
- Renders on the Wicket theme and on a non-Wicket theme (`--standalone` modifier).
- Unit tests (`CardGroupTest`).

<a id="p23"></a>

### P2.3 — Group roster page: Default View, Back to Directory, own search, filters, sort and paging

**Depends on:** P2.2

View Members shows one group's members on the same page, server-rendered from GET params: shareable, bookmarkable, works without JS, back/forward work.

- Directory setting **Default View**: Group List (browse, then click in), Group Members (always one chosen group's roster), Group List + Member List (both).
- A roster GET param (e.g. `wd{ID}_group`) selects the group. The roster has its own prefixed keyword, location, facet, sort and page params, so using it never resets the group list's state, and vice versa.
- Header: Back to Directory link (only in the "+ Member List" mode), group type eyebrow, title, group description from the MDP.
- **Group Member Status** setting (Active / Inactive / All) decides which members appear. Separate from the card's active member count, which is always active only.
- Roster keyword matches name and role; roster sort and page size (falls back to the directory's).
- Block: roster results per page and Collapse Refine by default for the roster; settings that don't apply to the chosen Default View are hidden.
- An inactive or excluded group can't be reached through a direct roster link.

**Acceptance criteria:**

- Two directory blocks on one page don't interfere.
- Hidden controls' GET params are ignored, as in the MVP.
- Backwards compatible: existing directories and their GET params behave as before.
- Unit tests for the roster params, query and renderer branches.

<a id="p24"></a>

### P2.4 — Group Member card, with its own card config and facets

**Depends on:** P2.3

`templates/cards/card-group-member.php`: the individual card plus four group-membership fields that exist only on a roster row.

- Role (plain text), in-group status pill (from the group membership's active flag, not the person's account status), start date, end date ("Present" when absent).
- Card config mirrors the individual card: profile image, role, membership status, start/end date, member ID, tier, title, employer, address (format, type, flags), email, phone, website.
- Its own facet set for the roster's Refine Results, separate from the group list's.
- Admin: in "Group List + Member List" mode, a switch above the Card View and Filters tabs picks Group Card or Group Member Card. In the other modes only the card that can be reached is shown.

**Acceptance criteria:**

- Editing one card's config or facets never changes the other's.
- The group-membership fields never appear outside the roster.
- Unit tests (`CardGroupMemberTest`, config sanitize).

## 2. Card fields

<a id="p25"></a>

### P2.5 — Organization Contact on the org card (Membership Owner / Relationship Types)

**Depends on:** P2.0

Show who to contact for an organization on its card. Config under the org card:

- **Membership Owner** mode: the org membership's owner. Optional "Display relationship type": when relationship types are picked, show the owner only if they have an active relationship of one of those types to this org, and show the type label next to the name. With no types picked, always show the owner.
- **Relationship Type(s)** mode: every person with an active relationship of the selected type(s) to the org (any type when none are selected). Can be one person or a team.
- Per-contact toggles: name, title, email, phone. Contact records go through `ContactResolver`.
- One batch request per page of orgs (from P2.0), cached with the page. Never per row.
- The section disappears when nobody qualifies (no placeholder).

**Acceptance criteria:**

- Backwards compatible: org directories saved before this keep rendering without the section.
- Unit tests for both modes and the relationship-type filter rules.

<a id="p26"></a>

### P2.6 — Individual card: Employer, Designation and relationship types

**Depends on:** P2.0

Add the individual-card fields the prototype has but the MVP left out (see 5.5 in [mvp-tickets.md](mvp-tickets.md#55)).

- Employer: the person's organization from connections (primary organization, or an active connection of the selected relationship types), legal name in the current language.
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
