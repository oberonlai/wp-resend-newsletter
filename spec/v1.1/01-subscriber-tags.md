# Subscriber Tags (v1.1)

**Risk Tier**: 🟡 Yellow — capability-gated admin + join tables over subscriber PII; changes who receives Broadcasts (audience correctness is Red-adjacent — treat send-path tests as Red)

## Goal
ConvertKit-like tags on local subscribers. When starting a campaign send, optionally filter recipients by one or more tags. **Multiple selected tags use AND** (recipient must have every selected tag). Tags stay local — **do not** sync to Resend Topics.

## Decisions (locked)
| Topic | Decision |
|-------|----------|
| Multi-tag filter | **AND** for v1.1; OR is out of scope |
| Resend Topics | **Not used** |
| Audience sync | Filter confirmed + tags locally, then sync **only** those contacts to a **campaign-scoped Resend segment**, then Broadcast to that segment |
| Unfiltered send | No tags selected → current MVP behavior (all confirmed → settings `segment_id`) |
| Empty intersection | Zero matching confirmed subscribers → clear admin error; no Broadcast; campaign not stuck in `sending` |

## User Stories

**As a** site admin  
**I want to** create tags and assign them to subscribers  
**So that** I can segment my list without leaving WordPress

**Scenario**: Create tag
  **Given** an admin with `manage_options`
  **When** they create a tag named `VIP`
  **Then** a row is stored in `wprn_tags` with unique `name`/`slug`
  **And** duplicate name is rejected with a validation error

**Scenario**: Assign and remove tags on a subscriber
  **Given** subscriber `alice@example.com` (confirmed) and tags `VIP`, `Product`
  **When** admin assigns both tags
  **Then** two rows exist in `wprn_subscriber_tag`
  **When** admin removes `Product`
  **Then** only `VIP` remains for that subscriber

**Scenario**: Bulk assign from Subscribers list
  **Given** multiple selected subscribers and a chosen tag
  **When** admin runs bulk “Add tag” with a valid nonce
  **Then** each selected subscriber gains that tag (idempotent if already tagged)

---

**As a** site admin  
**I want to** send a campaign only to subscribers who have selected tag(s)  
**So that** I can run ConvertKit-like filtered Broadcasts

**Scenario**: AND filter with one tag
  **Given** confirmed subscribers A (tag `VIP`), B (tag `VIP`+`Product`), C (no tags), D (pending + `VIP`)
  **And** a ready campaign with send filter tag ids = [`VIP`]
  **When** admin starts send
  **Then** SegmentSync audience is A and B only (confirmed ∩ has `VIP`)
  **And** C and D are excluded
  **And** Broadcast uses the campaign-scoped segment containing only A,B contacts — not the full global segment leftover membership

**Scenario**: AND filter with multiple tags
  **Given** confirmed A (`VIP` only), B (`VIP`+`Product`), C (`Product` only)
  **And** send filter = [`VIP`, `Product`]
  **When** send starts
  **Then** only B is synced to the campaign segment and receives the Broadcast

**Scenario**: No tags selected means all confirmed
  **Given** a ready campaign with empty filter
  **When** send starts
  **Then** behavior matches MVP area 04 (sync all confirmed to settings `segment_id`)

**Scenario**: Empty filtered audience
  **Given** filter tags that match zero confirmed subscribers
  **When** admin starts send
  **Then** no Broadcast is created; admin sees a clear message; campaign does not remain silently `sending`

**Scenario**: Campaign path still forbids transactional blast
  **Given** a tagged or untagged campaign send
  **When** the pipeline runs
  **Then** it uses `send_broadcast` + segment/contact sync only
  **And** never `send_batch` / transactional `emails` for the recipient list

**Scenario**: Non-admin cannot mutate tags
  **Given** a user without `manage_options`
  **When** they POST tag create/assign
  **Then** request is rejected; no rows change

## How Broadcast / SegmentSync filters by tags

### Current MVP (baseline)
`SegmentSyncService::sync_confirmed_contacts( $segment_id )` upserts **all** `confirmed` emails onto settings `segment_id`, then `BroadcastSender` creates one Broadcast with that `segment_id`.

### v1.1 tagged send (preferred)
1. Admin selects zero or more tags on campaign edit / “Queue send” UI.
2. Persist snapshot on campaign: `filter_tag_ids` (JSON array of ints; empty = no filter). Decision: AND semantics when length ≥ 1.
3. `QueueService::enqueue_campaign` unchanged job types (`sync_segment` → `send_broadcast`).
4. On `sync_segment` job:
   - Resolve audience SQL: `status = confirmed` **AND** (if tags) subscriber has **all** selected `tag_id`s (e.g. `GROUP BY subscriber_id HAVING COUNT(DISTINCT tag_id) = N`).
   - If filter empty: reuse settings `segment_id` (MVP path).
   - If filter non-empty: **ensure campaign-scoped segment** (create via Resend if `campaigns.audience_segment_id` empty; name e.g. `WPRN campaign #{id}`; store id on campaign). Sync **only** filtered emails as contacts on **that** segment.
   - **Do not** rely on “upsert filtered only onto the global segment” without removing others — leftover contacts would still get the Broadcast.
5. On `send_broadcast` job: call `send_broadcast` with the segment id chosen above (`audience_segment_id` if filtered, else settings `segment_id`).
6. Optional later (out of scope): prune/delete ephemeral segments after send.

### Why not Resend Topics
Topics are a Resend-side preference model. Local tags + filtered contact set keep WP as source of truth, avoid dual-write, and match “filter then sync” product ask.

## Data Layer

### `{prefix}wprn_tags`
| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint unsigned AI PK | |
| `name` | varchar(191) NOT NULL | unique |
| `slug` | varchar(191) NOT NULL | unique, sanitized |
| `created_at` | datetime | |
| `updated_at` | datetime | |

### `{prefix}wprn_subscriber_tag`
| Column | Type | Notes |
|--------|------|-------|
| `subscriber_id` | bigint unsigned NOT NULL | FK logical → subscribers |
| `tag_id` | bigint unsigned NOT NULL | FK logical → tags |
| `created_at` | datetime | |
| PK | `(subscriber_id, tag_id)` | |
| KEY | `tag_id` | |

### Campaigns table (extend via dbDelta / version bump)
| Column | Type | Notes |
|--------|------|-------|
| `filter_tag_ids` | text NULL | JSON array of tag ids at send; empty/`[]`/NULL = all confirmed |
| `audience_segment_id` | varchar(64) NULL | Resend segment used for this campaign’s filtered send |

### Repositories
- `TagRepository` — CRUD, find by slug, list
- `SubscriberTagRepository` — attach/detach, list tags for subscriber, list subscriber ids for tag set (AND helper)
- Extend `SubscriberRepository` or query helper: `find_confirmed_ids_with_all_tags( int[] $tag_ids ): int[]`
- Extend `CampaignRepository` for new columns
- Extend `SegmentSyncService` with `sync_contacts( string $segment_id, array $emails )` (or `sync_confirmed_contacts_filtered( … )`)

## Application Layer
- `TagService` — create/rename/delete (delete removes join rows), assign/remove
- `QueueService` / `BroadcastSender` / `SegmentSyncService` — wire filter + campaign-scoped segment as above
- Snapshot `filter_tag_ids` when enqueue starts (immutable for that send) so mid-send tag edits do not change audience mid-flight

## Admin UI
- **Tags** submenu (or section under Subscribers): list / add / rename / delete (confirm if in use)
- **Subscribers** list: column of tags; row actions or meta box to edit tags; bulk “Add tag” / “Remove tag”
- **Campaign edit / Queue send**: multi-select tags with help text: “Recipients must have **all** selected tags (AND). Leave empty to send to all confirmed.”
- Show estimated recipient count (confirmed ∩ AND tags) before enqueue
- Nonces + `manage_options` on all mutations

## Development Tasks

### Data Layer
- [x] `TagsTable` + `SubscriberTagTable` via `dbDelta` on activate / `maybe_upgrade`
- [x] Bump `CampaignsTable` schema for `filter_tag_ids`, `audience_segment_id`
- [x] Repositories + AND query helper

### API Layer
- [x] `TagService`
- [x] Extend `SegmentSyncService` for filtered email lists + campaign segment ensure
- [x] Wire filter into sync job in `BroadcastSender`
- [x] Preserve hard rule: no transactional campaign blast

### Interface Layer
- [x] Tags admin page; subscriber tag UI; campaign tag multi-select + count preview
- [x] Admin-post handlers with nonce + capability

### Integration Layer
- [x] Unit: AND intersection helper
- [x] Integration: assign tags; enqueue filtered campaign → mock Resend sees only filtered emails on campaign segment; empty audience; unfiltered still uses settings segment
- [x] Assertion: campaign send never calls `send_batch`

## Tests checklist
| # | Case | Expected |
|---|------|----------|
| T1 | Create duplicate tag name | Validation fail |
| T2 | Assign same tag twice | Idempotent join |
| T3 | Delete tag | Join rows removed; subscribers untouched |
| T4 | Filter one tag | Only confirmed with that tag synced |
| T5 | Filter two tags AND | Only subscribers with both |
| T6 | Pending tagged excluded | Not in audience |
| T7 | Empty filter | All confirmed → settings segment |
| T8 | Empty audience | No broadcast; clear error |
| T9 | Capability / nonce fail | No writes |
| T10 | Concurrent claim | Still single Broadcast (existing 04 guarantee) |

## Manual Test Script

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Create tags `VIP`, `Product`; assign to subset of confirmed | Tags visible on Subscribers |
| 2 | Campaign send with `VIP` only | Resend segment/contacts = VIP confirmed only; one Broadcast |
| 3 | Send with `VIP`+`Product` | Only intersection |
| 4 | Send with no tags | All confirmed (MVP) |
| 5 | Send with tag matching nobody | Error; no Broadcast |

## Out of scope
- OR filter, smart/auto tags, import CSV tag columns (may follow), Resend Topics, public subscribe form tag picker
