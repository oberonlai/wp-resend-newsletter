# Settings and Resend Client

**Risk Tier**: 🔴 Red — secrets handling, options write, outbound API credentials

## User Stories

**As a** site admin  
**I want to** store a Resend API key, default from-address, and Segment id securely  
**So that** the plugin can send transactional mail and campaign Broadcasts without committing secrets to VCS

**Scenario**: Valid settings save
  **Given** an admin with `manage_options` on the plugin Settings page
  **When** they submit a non-empty API key `re_test_xxx`, from email `news@news.oberonlai.blog` (or other verified domain address), optional `segment_id`, and a valid nonce
  **Then** option `wprn_settings` stores the from email and `segment_id`
  **And** the API key is stored (encrypted-at-rest if available, otherwise in options) and never echoed back in full in the HTML (masked display)
  **And** a success notice is shown

**Scenario**: Missing nonce is rejected
  **Given** a POST to the settings handler without a valid nonce
  **When** the handler runs
  **Then** the request is rejected (`wp_die` / 403) and settings are unchanged

**Scenario**: Insufficient capability is rejected
  **Given** a logged-in subscriber (no `manage_options`)
  **When** they POST settings
  **Then** the request is rejected and settings are unchanged

**Scenario**: Constant overrides option for API key
  **Given** `WPRN_RESEND_API_KEY` is defined in `wp-config.php`
  **When** `ResendClient` resolves credentials
  **Then** the constant value is used and the Settings field shows as “defined in wp-config” (read-only hint)

**As a** developer  
**I want to** call Resend through a single `ResendClient` with separate paths for Broadcasts vs transactional mail  
**So that** campaign blasts never use the transactional API by mistake

**Scenario**: Broadcast client method is the campaign send entry point
  **Given** a `ResendClient` with a valid API key
  **When** application code sends a newsletter campaign
  **Then** it calls `send_broadcast(…)` which maps to `$resend->broadcasts->create([ 'segment_id' => …, 'from' => …, 'subject' => …, 'html' => …, 'text' => …, 'send' => true ])`
  **And** it does **not** call `emails`, `emails/batch`, or `batch->send`

**Scenario**: Segment / contact helpers support sync (planned)
  **Given** settings contain a `segment_id` (or a segment is created and stored)
  **When** confirmed subscribers are synced
  **Then** `ResendClient` exposes helpers (e.g. ensure segment, upsert contact onto segment) used by area 04 — not by blasting via transactional batch

**Scenario**: Transactional batch size is capped at 50
  **Given** a `ResendClient` instance
  **When** code requests **transactional** `send_batch` to 51 recipients in one call
  **Then** the client refuses or splits so no single Resend batch request exceeds 50 recipients
  **And** this cap applies **only** to transactional batch (confirm / admin test helpers) — campaigns do not use batch at all

**Scenario**: Missing API key fails closed
  **Given** no API key in options and no `WPRN_RESEND_API_KEY` constant
  **When** `ResendClient` attempts `send_batch` or `send_broadcast`
  **Then** it returns a typed error and no HTTP call is made

## API split (do not blur)

| Method (planned / existing) | Use for | Resend SDK |
|-----------------------------|---------|------------|
| `send_broadcast( array $params )` | Campaign / marketing bulk | `$resend->broadcasts->create` with `segment_id` + `send => true` |
| Segment / contact sync helpers | Keep Resend Segment in sync with local confirmed list | `$resend->segments`, `$resend->contacts` |
| `send_batch( array $messages )` | Double opt-in confirm, optional admin test only | `$resend->batch->send` — **max `WPRN_RESEND_BATCH_MAX` (50)** |
| (optional later) single `send_email` | Same transactional cases | `$resend->emails->send` |

**Forbidden:** using `send_batch` / transactional `emails` to deliver a campaign to the subscriber list.

## Development Tasks

### Data Layer
- [x] Define `wprn_settings` option schema (from_email, from_name, api_key_option_key, webhook_secret placeholder) → `/option-page`
- [x] Add `segment_id` (and optional segment name) to settings schema when wiring Broadcasts
- [x] Document that `.env` / keys stay out of git (README already notes)

### API Layer
- [x] `composer require resend/resend-php` (runtime dep; pin compatible with PHP ≥8.1)
- [x] Create `src/Infrastructure/ResendClient.php` wrapping SDK; expose `send_batch(array $messages): Result` (**transactional only**)
- [x] Constant `WPRN_RESEND_BATCH_MAX = 50` (transactional batch ceiling)
- [x] Implement `send_broadcast(array $params): Result` → `$resend->broadcasts->create` with `send => true` (area 04)
- [ ] (Area 04) Segment ensure + contact upsert helpers

### Interface Layer
- [x] Settings page under plugin menu → `/option-page`
- [x] Masked API key field; clear-key action with nonce
- [x] Settings field for Resend `segment_id` (and guidance that from-domain must be verified)

### Integration Layer
- [x] Wire Settings into `Bootstrap::admin_init`
- [x] Unit/integration tests for credential resolution and transactional batch cap → (TDD)
- [x] Unit test: `send_broadcast` fails closed when unimplemented or missing key

## Manual Test Script

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Activate plugin; open Settings | Form renders; no full key in HTML source |
| 2 | Save valid from + key with nonce | Success notice; option updated |
| 3 | Define `WPRN_RESEND_API_KEY` in wp-config; reload | UI indicates constant override |
| 4 | Confirm `.gitignore` has `.env` | Key files not staged |
| 5 | Prefer from `@news.oberonlai.blog` once DNS verified | Matches Resend verified domain |
