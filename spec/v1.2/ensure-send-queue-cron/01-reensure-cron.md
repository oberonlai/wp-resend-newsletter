# 01 — Re-ensure `wprn_process_send_queue` cron

**Risk Tier**: 🟡 Yellow — WP-Cron schedule only; does not change job claim/send

## Goal

Always keep `wprn_process_send_queue` scheduled after boot and after a successful campaign enqueue, even if activation never re-ran.

## Decisions (locked)

| Topic | Decision |
|-------|----------|
| Idempotent API | Keep `BroadcastSender::schedule_cron()` (`wp_next_scheduled` guard) |
| Boot path | `Bootstrap::init_hooks()` → `add_action( 'init', [ BroadcastSender::class, 'schedule_cron' ] )` |
| Enqueue path | `QueueService::enqueue_campaign()` calls `BroadcastSender::schedule_cron()` after successful enqueue, before return |
| Handler | Unchanged — already `add_action( CRON_HOOK, handle_cron )` on boot |
| Version carriers | `wp-resend-newsletter.php` header + `WP_RESEND_NEWSLETTER_VERSION` + `Bootstrap::VERSION` = `0.3.12` |

## User Stories

**As a** site admin  
**I want** the send-queue cron to survive deploys without reactivation  
**So that** pending sync/broadcast jobs are not stuck forever

**Scenario**: Boot re-schedules missing cron
  **Given** the plugin boots and no `wprn_process_send_queue` event is scheduled
  **When** `init` runs
  **Then** `BroadcastSender::schedule_cron()` runs and schedules the event (or no-ops if already present)

**Scenario**: Successful enqueue re-schedules cron
  **Given** a ready campaign is enqueued successfully
  **When** `QueueService::enqueue_campaign()` returns ok
  **Then** `BroadcastSender::schedule_cron()` was invoked so the worker runs even if cron was wiped

## Development Tasks

### Wiring
- [x] `Bootstrap.php`: register `BroadcastSender::schedule_cron` on `init`
- [x] `QueueService.php`: call `BroadcastSender::schedule_cron()` on enqueue success (before return)

### Tests
- [x] Unit: `BroadcastSender::schedule_cron` schedules when missing / skips when present
- [x] Unit: enqueue success path invokes schedule (tracked via WP cron stubs)
- [x] Unit: Bootstrap source wires `init` → `schedule_cron`

### Release
- [x] Bump to `0.3.12`
- [x] Run unit PHPUnit + phpstan
- [x] Commit + push; do **not** deploy to Kinsta
