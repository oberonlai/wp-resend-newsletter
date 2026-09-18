# 02 — Analytics UI: one section per link

**Risk Tier**: 🟡 Yellow — admin-only; reuses existing bulk-tag nonce/action

## Goal

Replace the single aggregate “Clicked by” table with **one section per link URL**. Keep Opens/Clicks summary, Top clicked links, and “Opened by” unchanged.

## Decisions (locked)

| Topic | Decision |
|-------|----------|
| Section heading | Link URL + click count |
| Table columns | checkbox \| email \| events_count (for that link) \| last_at |
| Bulk tag | Same `wprn_analytics_bulk_add_tag`; posts only selected `subscriber_ids` from that section (no `link_url` in POST) |
| Form identity | Unique form id per link section (e.g. hash of URL) so select-all / tag dropdowns do not collide |
| Anchor | `id="wprn-link-{hash}"` where hash is stable (e.g. `substr( md5( $url ), 0, 12 )`); Top clicked links URL cells link to `#wprn-link-…` |
| Empty | If no `clicks_by_link`, show the existing empty clickers message (or omit sections) |
| Aggregate “Clicked by” | Removed from UI (do not render `clickers` table) |
| i18n | New labels via `__()` / `esc_html_e()` + zh_TW.po entries |

## User Stories

**As a** newsletter admin  
**I want to** see clickers under each link URL  
**So that** I can tag only people who clicked that link

**Scenario**: Per-link sections render
  **Given** an admin viewing Campaign Analytics for a campaign with link1 (A+B) and link2 (A only)
  **When** the page renders
  **Then** there is a section for each URL with heading containing the URL and click count
  **And** link1’s table lists A and B; link2’s table lists only A
  **And** there is no aggregate “Clicked by” table that merges all links into one row set with a Links column

**Scenario**: Bulk tag scopes to section selection
  **Given** an admin on the link2 section
  **When** they check only A, choose a tag, and submit Add tag
  **Then** `wprn_analytics_bulk_add_tag` receives A’s `subscriber_ids` (and campaign_id)
  **And** redirect returns to that campaign’s analytics

**Scenario**: Top links jump to section
  **Given** top_links includes a URL that has a per-link section
  **When** the admin clicks the top-links URL
  **Then** the browser navigates to `#wprn-link-{hash}` for that URL

**Scenario**: Opened by unchanged
  **Given** open events exist
  **When** analytics renders
  **Then** “Opened by” table still lists openers with bulk tag (not link-scoped)

**Scenario**: Capability / nonce
  **Given** a user without `manage_options`, or missing nonce on bulk tag
  **When** they hit analytics render or the bulk action
  **Then** they are rejected (existing handlers)

## Development Tasks

### Interface
- [x] Refactor `CampaignAnalyticsPage` to render per-link sections from `clicks_by_link`
- [x] Wire top_links rows to section anchors
- [x] Keep openers table; drop aggregate clickers table from render

### i18n / release
- [x] zh_TW for any new user-visible strings
- [x] Bump to `0.3.6` and build zip

### Tests
- [x] Update/add analytics page HTML assertions for per-link sections + anchors (not aggregate clickers Links column)
