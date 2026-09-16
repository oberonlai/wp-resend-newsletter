# Webhooks

**Risk Tier**: 🔴 Red — unauthenticated public endpoint, signature verification, PII status changes

## User Stories

**As a** the system  
**I want to** receive Resend bounce and complaint events  
**So that** subscriber status stays accurate and we stop mailing bad addresses

**Scenario**: Valid bounce webhook marks subscriber bounced
  **Given** a confirmed subscriber whose address appears in a signed Resend bounce payload
  **When** `POST /wp-json/wprn/v1/webhooks/resend` receives the event with valid signature
  **Then** subscriber status becomes `bounced`
  **And** an optional `delivery_events` row is stored
  **And** response is 200

**Scenario**: Valid complaint webhook marks complained
  **Given** a confirmed subscriber in a signed complaint event
  **When** the webhook endpoint processes it
  **Then** status becomes `complained` and they are excluded from future queues

**Scenario**: Invalid signature rejected
  **Given** a payload with missing/wrong signature
  **When** the endpoint is hit
  **Then** response is 401/403 and no subscriber row changes

**Scenario**: Unknown event type is acknowledged safely
  **Given** a signed payload with an event type we do not handle
  **When** processed
  **Then** response is 200 (avoid Resend retry storms) and no incorrect status change occurs

**Scenario**: Webhook secret missing fails closed
  **Given** no webhook secret configured
  **When** any POST arrives
  **Then** endpoint returns 503/403 and does not process

## Development Tasks

### Data Layer
- [x] Optional `{prefix}wprn_delivery_events` → `/custom-table`
  - Columns (min): `id`, `subscriber_id`, `campaign_id`, `event_type`, `provider_event_id`, `payload_hash`, `created_at`
- [x] Idempotency on `provider_event_id`

### API Layer
- [x] REST route registration → `/rest-api`
- [x] `WebhookProcessor` + signature verify using webhook secret from settings/constant `WPRN_RESEND_WEBHOOK_SECRET`
- [x] Map Resend event types → subscriber statuses

### Interface Layer
- [x] Settings field for webhook secret (masked); show webhook URL for Resend dashboard

### Integration Layer
- [x] Tests with fixture payloads + signature vectors; never log raw secrets → (TDD)

## Manual Test Script

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Configure webhook secret; copy REST URL into Resend | Dashboard accepts URL |
| 2 | Send test bounce fixture with valid sig | Subscriber → `bounced` |
| 3 | Replay with bad sig | 401/403; no change |
