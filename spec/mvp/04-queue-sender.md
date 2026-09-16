# Queue / Broadcast Sender

**Risk Tier**: 🔴 Red — bulk marketing email via Resend Broadcasts, `$wpdb` jobs, cron, retries, Segment sync

## Hard rule
Newsletter **campaign** delivery uses Resend **Broadcasts** (`broadcasts->create` with `segment_id` and `send => true`).  
**Forbidden** for campaigns: transactional `emails`, `emails/batch`, `batch->send`, or looping `send_batch` over subscribers.

Transactional `send_batch` remains only for operational mail (area 02 confirm; optional admin test).

## User Stories

**As a** site admin  
**I want to** send a ready campaign to all confirmed subscribers via Resend Broadcasts  
**So that** marketing mail uses Resend’s bulk/Broadcast path and Segment audience, without blocking the request on per-recipient transactional API calls

**Scenario**: Start send ensures Segment and syncs contacts
  **Given** campaign `1` is `ready`, settings have (or can create) a Resend `segment_id`, and there are 120 confirmed + 5 pending + 3 unsubscribed subscribers
  **When** admin starts send
  **Then** the worker ensures a Resend Segment exists and stores `segment_id` in settings if newly created
  **And** the 120 confirmed subscribers are upserted as Resend **contacts** on that segment (pending/unsubscribed excluded)
  **And** campaign status becomes `sending`

**Scenario**: Create and send one Broadcast
  **Given** segment sync for the campaign has completed successfully
  **When** the send step runs
  **Then** `ResendClient::send_broadcast` is called with subject, html, text, `from` (verified domain, e.g. `*@news.oberonlai.blog`), `segment_id`, and `send => true`
  **And** HTML/text include `{{{RESEND_UNSUBSCRIBE_URL}}}` (or the template layer injects it)
  **And** a local `send_jobs` (or campaign) row records `resend_broadcast_id` / provider status — **not** hundreds of transactional batch chunk jobs
  **And** no call is made to `batch->send` / transactional emails for this blast

**Scenario**: Local job tracks Broadcast, not per-recipient chunks
  **Given** a campaign send has been started
  **When** jobs are inspected
  **Then** MVP may use one job (or a small fixed set: e.g. `sync_contacts` → `create_broadcast`) per campaign
  **And** job status reflects sync/broadcast progress (`pending`, `processing`, `sent`/`failed`) with `attempts`, `next_attempt_at`, `last_error`, `provider_broadcast_id`
  **And** design does **not** require one `send_jobs` row per subscriber for Resend batching

**Scenario**: Transient Resend failure retries
  **Given** segment sync or broadcast create fails with a retryable error (e.g. HTTP 429/503)
  **When** the worker handles the failure
  **Then** the job remains retryable with `attempts` incremented and `next_attempt_at` in the future
  **And** after max attempts it becomes `failed` with error stored; campaign is not left silently `sending`

**Scenario**: Concurrent workers do not double-send
  **Given** two worker runs overlapping for the same campaign
  **When** both claim the broadcast job
  **Then** the job is claimed once (row lock / status `processing`) and Broadcast create+send is not invoked twice

**Scenario**: Empty audience
  **Given** a ready campaign and zero confirmed subscribers
  **When** admin starts send
  **Then** no Broadcast is created and admin sees a clear message; campaign does not stay stuck in `sending` without explanation

**Scenario**: Campaign path rejects transactional blast helpers
  **Given** application send code for campaigns
  **When** reviewed or tested
  **Then** it depends on `send_broadcast` (+ segment/contact sync), never on `send_batch` for the recipient list

## Development Tasks

### Data Layer
- [x] Create `{prefix}wprn_send_jobs` → `/custom-table` (Broadcast-oriented)
  - Columns (min): `id`, `campaign_id`, `job_type` (`sync_segment` | `send_broadcast`), `status`, `attempts`, `next_attempt_at`, `provider_broadcast_id`, `last_error`, `created_at`, `updated_at`
  - Optional: drop per-`subscriber_id` requirement for MVP campaign sends (subscriber identity lives in Resend Segment after sync)
- [ ] Optional `{prefix}wprn_delivery_events` for provider events (can wait for 06)
- [x] `SendJobRepository` with claim / by-campaign query methods
- [x] Settings: persist `segment_id`

### API Layer
- [x] `SegmentSyncService` — ensure segment; upsert confirmed subscribers as contacts on segment
- [x] `QueueService::enqueue_campaign( int $campaign_id )` — create sync + broadcast jobs (or single pipeline job)
- [x] `BroadcastSender::process_next()` — claim job → sync and/or `ResendClient::send_broadcast(…)`
- [x] WP-Cron schedule on activate; hook e.g. `wprn_process_send_queue`
- [x] **Do not** implement campaign sending via `ResendClient::send_batch`

### Interface Layer
- [ ] “Send” action on campaign (admin) triggers enqueue only — progress UI in 05 (show broadcast id / job status)

### Integration Layer
- [x] Integration tests with mocked `ResendClient` for segment sync, broadcast create, retry, claim → (TDD)
- [x] Assertion helpers / tests that campaign send does not call `send_batch`

## Manual Test Script

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Seed 55 confirmed; start send on ready campaign | Sync runs; Broadcast created; campaign `sending`→`sent` (or job shows broadcast id) |
| 2 | Inspect Resend dashboard | One Broadcast to segment; not 55 transactional API sends |
| 3 | Simulate 429 on broadcast create then re-run cron | Retries then eventual send/fail per policy |
| 4 | Zero confirmed; start send | Clear error; no Broadcast |
