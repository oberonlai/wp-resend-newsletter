# Subscribers

**Risk Tier**: 🔴 Red — PII, email tokens, public confirm/unsubscribe endpoints, `$wpdb`

## User Stories

**As a** visitor  
**I want to** subscribe with my email and confirm via link  
**So that** I only receive mail after opt-in

**Scenario**: New subscribe creates pending row and sends confirm mail
  **Given** email `alice@example.com` is not in the subscribers table
  **When** subscribe is requested with a valid email
  **Then** a row is inserted with status `pending` and a confirm token
  **And** a confirmation email is queued/sent via `ResendClient`
  **And** the response does not reveal whether the email was already known beyond a generic success message (enumeration-safe)

**Scenario**: Invalid email is rejected
  **Given** input `not-an-email`
  **When** subscribe is requested
  **Then** validation fails with a clear error and no row is written

**Scenario**: Confirm link activates subscriber
  **Given** a pending subscriber with a valid, unexpired confirm token
  **When** they open the confirm URL
  **Then** status becomes `confirmed` and `confirmed_at` is set
  **And** the token cannot be reused

**Scenario**: Expired or forged confirm token fails
  **Given** an expired or tampered token
  **When** the confirm URL is opened
  **Then** status is unchanged and an error page/message is shown

**As a** subscriber  
**I want to** unsubscribe via a signed link  
**So that** I can stop mail without logging in

**Scenario**: Valid unsubscribe
  **Given** a confirmed subscriber with a valid unsubscribe token
  **When** they open the unsubscribe URL
  **Then** status becomes `unsubscribed` and they are excluded from future campaign queues

**Scenario**: Already unsubscribed is idempotent
  **Given** status is already `unsubscribed`
  **When** unsubscribe URL is opened again
  **Then** the page shows success and no error is thrown

## Development Tasks

### Data Layer
- [x] Create `{prefix}wprn_subscribers` via `dbDelta` on activate → `/custom-table`
  - Columns (min): `id`, `email`, `status`, `confirm_token_hash`, `unsub_token_hash`, `confirmed_at`, `created_at`, `updated_at`
- [x] `SubscriberRepository` (find by email/token, insert, update status)

### API Layer
- [x] `SubscribeService`, `ConfirmService`, `UnsubscribeService`
- [x] Public REST or pretty permalinks for confirm/unsub with signed tokens → `/rest-api`
- [x] Token generation: random + HMAC; store only hashes

### Interface Layer
- [ ] Optional shortcode / block later — MVP may use REST + admin-only add

### Integration Layer
- [x] Call table creation from `Activator::create_tables`
- [x] Integration tests for subscribe → confirm → unsubscribe happy path and token failures → (TDD)

## Manual Test Script

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Subscribe `alice@example.com` | Pending row; confirm mail path invoked |
| 2 | Open confirm link | Status `confirmed` |
| 3 | Open unsub link | Status `unsubscribed` |
| 4 | Reuse confirm link | Error; status unchanged |
