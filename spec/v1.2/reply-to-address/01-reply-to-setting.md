# 01 — Reply-To setting applied to every send

**Risk Tier**: 🟡 Yellow — additive optional field on the Broadcast / batch payloads

## Decisions (locked)

| Topic | Decision |
|-------|----------|
| Storage | `wprn_settings['reply_to']` (same option as from_email) |
| Validation | `sanitize_email` + `is_email`; empty → `''` (no header); invalid non-empty → keep previous value + settings error |
| Activator defaults | Unchanged — `SettingsPage::get_options()` merges `get_defaults()` so existing installs get `reply_to = ''` |
| Resolution helper | `ResendClient::reply_to_from_settings( array $settings ): string` — valid email or `''` |
| Broadcast | `ResendClient::send_broadcast()` forwards `reply_to` when non-empty; omitted otherwise |
| Transactional | `CampaignTestSender` + `SubscribeService::send_confirm_email()` add `reply_to` to the message when non-empty |
| Version carriers | plugin header + `WP_RESEND_NEWSLETTER_VERSION` + `Bootstrap::VERSION` = `0.3.13` |

## User Stories

**As a** newsletter reader
**I want** Reply to go to a mailbox that exists
**So that** my reply reaches the author instead of bouncing

**Scenario**: Broadcast carries Reply-To
  **Given** settings `reply_to = hi@oberonlai.blog`
  **When** the send_broadcast job runs
  **Then** the Broadcast create payload contains `reply_to = hi@oberonlai.blog`

**Scenario**: No Reply-To configured
  **Given** settings `reply_to` is empty
  **When** a Broadcast or test mail is sent
  **Then** the payload has no `reply_to` key

**Scenario**: Test mail carries Reply-To
  **Given** settings `reply_to = hi@oberonlai.blog`
  **When** an admin sends a campaign test email
  **Then** the batch message contains `reply_to = hi@oberonlai.blog`

**Scenario**: Confirm mail carries Reply-To
  **Given** settings `reply_to = hi@oberonlai.blog`
  **When** a visitor subscribes and the confirm mail is sent
  **Then** the batch message contains `reply_to = hi@oberonlai.blog`

**Scenario**: Invalid Reply-To rejected
  **Given** an admin saves `reply_to = not-an-email`
  **When** settings are sanitized
  **Then** the previous value is kept and a settings error is registered

## Development Tasks

### Settings
- [x] `SettingsPage`: default `reply_to`, field "Reply-To address", sanitize/validate

### Send paths
- [x] `ResendClient::reply_to_from_settings()`; `send_broadcast` forwards `reply_to`
- [x] `BroadcastSender` passes `reply_to`
- [x] `CampaignTestSender` passes `reply_to`
- [x] `SubscribeService` confirm mail passes `reply_to`

### Tests
- [x] Unit: `send_broadcast` forwards / omits `reply_to`; helper validation
- [x] Integration: settings sanitize valid / empty / invalid
- [x] Integration: broadcast happy path payload has `reply_to`
- [x] Integration: test sender payload has / omits `reply_to`
- [x] Integration: confirm mail payload has `reply_to`

### Release
- [x] Bump to `0.3.13`
- [x] PHPUnit (unit + integration) + PHPStan + PHPCS green
- [x] Commit + push + tag `v0.3.13` + release zip (CI green on PHP 8.1/8.2/8.4)
- [x] Deploy to oberonlai.blog; set `reply_to = hi@oberonlai.blog`; verify test mail header

### Deploy notes (2026-09-26 Taipei)
- Live plugin dir contained uncommitted changes from the Mac checkout (i18n refactor, submenu reorder, updated zh_TW translations) that are not in git, so a full `rsync --delete` of the release zip would have reverted them.
- Deployed only the 7 files changed in v0.3.13 (live copies were verified byte-identical to 6130138 first). Backup of the previous live dir: `/www/oberonlaiblog_807/private/wprn-backup-live-0.3.12-20260926`.
- `wp option patch insert wprn_settings reply_to hi@oberonlai.blog`; plugin test-send of campaign #135 to the system inbox arrived with `Reply-To: hi@oberonlai.blog`.
