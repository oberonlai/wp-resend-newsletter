# 01 — Tags column on engagement tables

**Risk Tier**: 🟢 Green — read-only admin markup; batch query already used on subscribers list; output escaped like `column_tags`

## Goal

Show each engagement row’s current tags between Email and Events on Campaign Analytics tables.

## Decisions (locked)

| Topic | Decision |
|-------|----------|
| Data source | `SubscriberTagRepository::find_tags_for_subscribers( $ids )` once per page render |
| ID collection | All `subscriber_id` from `$stats['openers']` + every `$group['subscribers']` in `$stats['clicks_by_link']` |
| Pass-through | Extra `$tags_by_subscriber` argument on `render_engagement_table` / `render_clicks_by_link_sections` |
| Empty display | `—` (same as subscribers list) |
| Non-empty | Comma-separated escaped tag names (same as `SubscribersListTable::column_tags`) |
| Bulk forms | Unchanged |
| Column order | Checkbox, Email, **Tags**, Events, Last activity |

## User Stories

**As an** admin viewing Campaign Analytics  
**I want to** see each opener/clicker’s tags next to their email  
**So that** I can decide bulk-tagging without opening subscriber detail

**Scenario**: Opener with tags renders in HTML
  **Given** a sent campaign with subscriber A who opened
  **And** A is attached to tags `VIP` and `Product`
  **When** an administrator renders Campaign Analytics for that campaign
  **Then** the Opened by table has a Tags header after Email
  **And** A’s row HTML contains `VIP` and `Product` (comma-separated)
  **And** the bulk-tag form / Events / Last activity columns remain

**Scenario**: Subscriber with no tags shows em dash
  **Given** opener B with no tags
  **When** analytics renders
  **Then** B’s Tags cell is `—`

**Scenario**: Per-link clicker section also shows tags
  **Given** clicker C tagged `VIP` who clicked a URL
  **When** analytics renders
  **Then** that link’s clickers table includes the Tags column and `VIP` for C

**Scenario**: Author cannot view analytics
  **Given** a user without `manage_options` (existing capability gate)
  **When** they request the analytics page
  **Then** render still fails closed (existing A10 behavior; no regression)

## Development Tasks

### Admin UI
- [x] In `CampaignAnalyticsPage::render()`, collect subscriber IDs from openers + clicks_by_link; batch-load tags; pass map into engagement renderers
- [x] Extend `render_clicks_by_link_sections` / `render_engagement_table` to accept `$tags_by_subscriber`
- [x] Add `<th>Tags</th>` after Email; per-row Tags cell matching `column_tags`

### Tests
- [x] Integration: seed opener/clicker with tags; assert Tags header + tag names (and `—` for untagged) in rendered HTML

### Release
- [x] Bump version to `0.3.7` (main file + Bootstrap::VERSION)
- [x] Build zip `build/wp-resend-newsletter-v0.3.7.zip`
