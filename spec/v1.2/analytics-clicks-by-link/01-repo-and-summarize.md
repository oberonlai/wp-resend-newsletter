# 01 — Repository + summarize grouping

**Risk Tier**: 🟡 Yellow — prepared SQL on `link_url`; PII (email) already exposed on analytics for `manage_options`

## Goal

Expose clickers **grouped by exact stored `link_url`**, and allow filtering unique subscribers by one link URL.

## Decisions (locked)

| Topic | Decision |
|-------|----------|
| Match | Exact string match on stored `link_url` (same value as delivery events / top_links) |
| Grouping source | Distinct non-empty `link_url` with ≥1 row where `subscriber_id > 0` and `event_type = email.clicked` |
| Order | Links ordered by total clicks DESC (same spirit as `top_links_for_campaign`); subscribers within a link by `last_at` DESC |
| `events_count` | Count of click rows for **that** subscriber **and** that `link_url` |
| `clicks` (group) | Total click event rows for that URL on the campaign (including `subscriber_id = 0`? Prefer same as top_links: all rows for URL). Subscriber list still skips `subscriber_id = 0`. |
| API | Extend `find_unique_subscribers_for_campaign_event( $campaign_id, $event_type, ?string $link_url = null )`; add `find_clickers_grouped_by_link( int $campaign_id ): array` |
| Summarize | Add `clicks_by_link` list; keep `clickers` aggregate for BC (UI stops using it for the main table) |
| Limit | No artificial top-N cap on grouped links (list every distinct clicked URL with ≥1 matched subscriber). `top_links` display may still be capped. |

## User Stories

**As a** the system  
**I want to** list unique subscribers who clicked a specific URL  
**So that** analytics and tagging can scope to that link

**Scenario**: Two subscribers, overlapping links
  **Given** campaign C with subscribers A and B
  **And** A has `email.clicked` for `https://example.com/link1` and `https://example.com/link2`
  **And** B has `email.clicked` only for `https://example.com/link1`
  **When** `find_clickers_grouped_by_link( C )` runs
  **Then** the group for `link1` includes A and B
  **And** the group for `link2` includes only A
  **And** each subscriber row has `events_count` / `last_at` for that link only

**Scenario**: Optional link_url filter on unique listing
  **Given** the same fixtures
  **When** `find_unique_subscribers_for_campaign_event( C, 'email.clicked', 'https://example.com/link2' )` runs
  **Then** only A is returned
  **And** when `$link_url` is null, behavior matches today’s aggregate clickers (all links)

**Scenario**: summarize exposes grouping
  **Given** the same fixtures
  **When** `CampaignAnalyticsService::summarize( C )` runs
  **Then** `clicks_by_link` contains the same grouping as the repository
  **And** `top_links` / open metrics remain present

**Scenario**: Empty / no matched subscribers
  **Given** a campaign with no click events (or only `subscriber_id = 0` clicks)
  **When** grouped listing runs
  **Then** result is an empty list

## Development Tasks

### Persistence
- [x] Extend `find_unique_subscribers_for_campaign_event` with optional `?string $link_url = null` (exact match when set and event is click)
- [x] Add `find_clickers_grouped_by_link( int $campaign_id ): list<array{link_url: string, clicks: int, subscribers: list<…>}>`

### Application
- [x] `CampaignAnalyticsService::summarize()` includes `clicks_by_link` from the grouped repo method

### Tests
- [x] Fixture A+B / link1+link2 assertions on filter + group + summarize
