# Engagement Analytics — Opens & Clicks (v1.1)

**Risk Tier**: 🔴 Red — public webhook endpoint, engagement events adjacent to PII (email → subscriber), status/analytics integrity, idempotency

## Goal
ConvertKit-like per-**campaign** and per-**subscriber** open and link-click tracking, powered by existing Resend webhooks (`POST /wp-json/wprn/v1/webhooks/resend`, Svix signing). Show **unique and total** counts. Close the MVP gap where `WebhookProcessor` always persists `campaign_id = 0`.

## Plan notes — Resend event names (confirmed)
| Event | Purpose | Docs |
|-------|---------|------|
| `email.opened` | Recipient opened the email (pixel); requires domain open tracking | [resend.com/docs/webhooks/emails/opened](https://resend.com/docs/webhooks/emails/opened) |
| `email.clicked` | Recipient clicked a tracked link; requires domain click tracking | [resend.com/docs/webhooks/emails/clicked](https://resend.com/docs/webhooks/emails/clicked) |

Payload fields used (typical `data` object):
- `broadcast_id` — maps to local `campaigns.resend_broadcast_id` → **`campaign_id`**
- `email_id`, `to[]` (recipient emails), `subject`
- For clicks: `click.link` (URL); **do not persist** `click.ipAddress` / `click.userAgent` into DB or logs for v1.1 privacy

Also keep handling: `email.bounced`, `email.complained` (MVP). Unknown types remain 200 ack.

**Ops:** Admin must enable open/click tracking on the sending domain in Resend and subscribe the webhook to `email.opened` + `email.clicked` (Settings copy should list all four event types).

## Decisions (locked)
| Topic | Decision |
|-------|----------|
| Counts | Show **unique** and **total** for opens and clicks (per campaign) |
| Unique definition | Distinct `subscriber_id` with ≥1 event of that type for the campaign (fallback: distinct normalized email if subscriber_id still 0) |
| Total definition | Count of `delivery_events` rows for that `campaign_id` + `event_type` (already deduped by `provider_event_id` / Svix id) |
| Storage | **Extend `wprn_delivery_events`** — no separate engagement table for v1.1 |
| campaign_id resolution | Prefer `data.broadcast_id` → `CampaignRepository::find_by_resend_broadcast_id` → id; else 0 |
| Raw PII in logs | **Forbidden** — keep `payload_hash` only; never `error_log` raw body, email, IP, or UA |
| Click metadata | Store `link_url` (truncated) only; omit IP/UA columns |

## MVP gap to fix
`WebhookProcessor` currently sets `$campaign_id = 0` always and only maps bounce/complaint to subscriber status. Opens/clicks are acknowledged as `ignored` without useful analytics rows tied to campaigns.

## User Stories

**As a** the system  
**I want to** record signed `email.opened` / `email.clicked` events  
**So that** campaign and subscriber analytics stay accurate without double-counting retries

**Scenario**: Open event stores delivery_events with campaign_id
  **Given** a sent campaign whose `resend_broadcast_id` is `br_abc`
  **And** a confirmed subscriber `alice@example.com`
  **When** a Svix-signed `email.opened` webhook arrives with `data.broadcast_id = br_abc` and `data.to = ["alice@example.com"]`
  **Then** a `delivery_events` row is inserted with `event_type = email.opened`, matching `subscriber_id`, and `campaign_id` = that campaign’s id
  **And** `provider_broadcast_id` / `provider_email_id` are stored when present
  **And** response is 200
  **And** subscriber status is **unchanged** (opens do not mutate status)

**Scenario**: Click event stores link_url
  **Given** the same campaign and subscriber
  **When** a signed `email.clicked` event arrives with `click.link = "https://example.com/post"`
  **Then** a row is stored with `event_type = email.clicked` and `link_url` set
  **And** IP / User-Agent from the payload are **not** written to the database or PHP logs

**Scenario**: Duplicate Svix id is idempotent
  **Given** an event already stored under `provider_event_id`
  **When** the same webhook is replayed
  **Then** response is 200 `duplicate` and no second row; totals unchanged

**Scenario**: Unknown broadcast_id leaves campaign_id 0 but still records event
  **Given** a signed open with `broadcast_id` that matches no local campaign
  **When** processed
  **Then** event row may have `campaign_id = 0` but still records subscriber (if email known) and type for audit
  **And** response is 200

**Scenario**: Invalid signature still rejected
  **Given** bad/missing Svix headers
  **When** POST hits the endpoint
  **Then** 401/403; no new `delivery_events` row; no status change

**Scenario**: Bounce/complaint behavior preserved
  **Given** signed `email.bounced` / `email.complained`
  **When** processed
  **Then** subscriber status updates as in MVP **and** `campaign_id` is resolved from `broadcast_id` when possible (improvement over MVP)

---

**As a** site admin  
**I want to** see open/click stats on a campaign  
**So that** I can judge engagement ConvertKit-style

**Scenario**: Campaign analytics view
  **Given** campaign 7 with stored events: 10 open rows (7 unique subscribers), 4 click rows (3 unique)
  **When** admin opens Campaign → Analytics (or detail panel)
  **Then** they see at least: Total opens **10**, Unique opens **7**, Total clicks **4**, Unique clicks **3**
  **And** optionally top clicked links (aggregate by `link_url`)
  **And** no API keys or raw webhook bodies are shown

**Scenario**: Empty / tracking-disabled empty state
  **Given** a sent campaign with zero open/click events
  **When** admin opens Analytics
  **Then** zeros are shown plus a short note that Resend domain open/click tracking and webhook subscriptions must be enabled

---

**As a** site admin  
**I want to** see a subscriber’s recent engagement events  
**So that** I can debug deliverability and interest

**Scenario**: Per-subscriber recent events
  **Given** subscriber Alice with several delivery_events (opens, clicks, bounce)
  **When** admin opens subscriber detail (or expandable row)
  **Then** a recent list shows time, event_type, campaign subject/id (if known), and link_url for clicks
  **And** emails in the UI remain capability-gated; nothing sensitive is written to `error_log`

## Data Layer

### Extend `{prefix}wprn_delivery_events`
Existing: `id`, `subscriber_id`, `campaign_id`, `event_type`, `provider_event_id` (UNIQUE), `payload_hash`, `created_at`.

Add (version bump e.g. `1.1.0`):
| Column | Type | Notes |
|--------|------|-------|
| `provider_broadcast_id` | varchar(64) NULL | From `data.broadcast_id` |
| `provider_email_id` | varchar(64) NULL | From `data.email_id` |
| `link_url` | varchar(500) NULL | From `data.click.link` for clicks only |

Indexes: `KEY provider_broadcast_id (provider_broadcast_id)`, `KEY campaign_event (campaign_id, event_type)`.

### Campaign lookup
- Ensure `campaigns.resend_broadcast_id` is indexed (add KEY if missing).
- `CampaignRepository::find_by_resend_broadcast_id( string $id ): ?object`

### Optional (not required for v1.1)
Materialized counters on `campaigns` (`opens_total`, etc.) — prefer live `COUNT` / `COUNT(DISTINCT subscriber_id)` queries first; add caches only if list performance requires it.

## Application Layer

### `WebhookProcessor` changes
1. After decode, extract `broadcast_id`, `email_id`, emails from `to`, and for clicks `click.link`.
2. Resolve `$campaign_id` via broadcast id lookup (shared for bounce/complaint/open/click).
3. For `email.opened` / `email.clicked`: resolve `subscriber_id` from email(s); **do not** change subscriber status; persist event with metadata columns.
4. For bounce/complaint: keep status map; also set resolved `campaign_id`.
5. Idempotency unchanged on `provider_event_id` (Svix id).
6. Hash raw body for `payload_hash`; never log raw body.

### Analytics query service
- `CampaignAnalyticsService::summarize( int $campaign_id ): array`  
  Returns totals/uniques for `email.opened` and `email.clicked`, plus optional top links.
- `SubscriberEngagementService::recent( int $subscriber_id, int $limit = 20 ): array`

Unique SQL sketch:
```sql
SELECT COUNT(*) AS total,
       COUNT(DISTINCT NULLIF(subscriber_id, 0)) AS unique_subscribers
FROM {prefix}wprn_delivery_events
WHERE campaign_id = %d AND event_type = %s
```

## Admin UI
- Campaign list or edit: “Analytics” link for `sent` / `sending` campaigns
- Analytics panel: unique + total opens/clicks; top links table; link to Queue
- Subscriber screen: “Recent events” table (last N)
- Settings help text: webhook URL + subscribe to `email.bounced`, `email.complained`, `email.opened`, `email.clicked`; domain tracking prerequisite
- Capabilities: `manage_options`; nonces on any POST (views are GET)

## Privacy notes
- Store **hash** of payload (`payload_hash`), not raw JSON.
- Do **not** store click IP or User-Agent.
- Do **not** `error_log` / Logger raw webhook bodies, full emails in debug dumps, or secrets.
- Admin UI may show subscriber email (already PII in subscribers list) only to users with `manage_options`.
- Exports (if added later) need explicit consent/docs — out of scope for v1.1.

## Development Tasks

### Data Layer
- [x] Bump `DeliveryEventsTable::VERSION`; add columns + indexes via `dbDelta`
- [x] Index `campaigns.resend_broadcast_id`; repository finder
- [x] Extend `DeliveryEventRepository::insert` / query helpers (by campaign+type, by subscriber recent, aggregates)

### API Layer
- [x] Update `WebhookProcessor` event handling + campaign_id resolution
- [x] `CampaignAnalyticsService`, `SubscriberEngagementService`
- [x] Settings copy for event subscription + tracking domain

### Interface Layer
- [x] Campaign Analytics admin view
- [x] Subscriber recent events UI
- [x] Empty-state guidance when tracking disabled / zero events

### Integration Layer
- [x] Fixture payloads for `email.opened` / `email.clicked` with valid Svix vectors (reuse MVP signing helpers)
- [x] Tests: campaign_id resolved; unique vs total; idempotent replay; bad sig; bounce still works with campaign_id; no IP stored; opens do not change status

## Tests checklist
| # | Case | Expected |
|---|------|----------|
| A1 | Signed open + known broadcast_id + email | Row with correct campaign_id + subscriber_id |
| A2 | Signed click with link | `link_url` set; no IP/UA columns populated |
| A3 | Replay same svix-id | Duplicate 200; single row |
| A4 | Open for unknown broadcast | campaign_id 0; 200 |
| A5 | Bad signature | 401/403; no row |
| A6 | Summarize unique vs total | Matches seeded distinct subscribers vs row counts |
| A7 | Bounce still sets bounced + resolves campaign when broadcast known | Status + campaign_id |
| A8 | Open does not change confirmed → other status | Status unchanged |
| A9 | Secret missing | 503 fail closed (MVP preserved) |
| A10 | Analytics page capability | Non-admin denied |

## Manual Test Script

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Enable domain open/click tracking in Resend; subscribe webhook to opened+clicked | Dashboard OK |
| 2 | Send a small Broadcast campaign | `resend_broadcast_id` stored on campaign |
| 3 | Open mail / click link (or inject signed fixtures) | Events in `delivery_events` with non-zero `campaign_id` |
| 4 | View Campaign Analytics | Unique + total opens/clicks |
| 5 | View subscriber recent events | Open/click rows listed |
| 6 | Replay fixture | No double count |

## Out of scope
- Heatmaps, bot filtering beyond Resend’s data, geo/device charts
- Storing full payloads, IP, UA
- Real-time websockets; Resend Metrics API polling as primary source (webhooks first)
- Changing transactional confirm emails to use open tracking (avoid training spam filters — Resend recommends tracking mainly for Broadcasts)
