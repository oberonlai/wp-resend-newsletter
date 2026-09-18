# Ensure send-queue WP-Cron after deploy (v1.2)

`wprn_process_send_queue` must stay scheduled even when the plugin is updated via rsync without reactivation.

**Plugin version:** `0.3.12`

## Why

`BroadcastSender::schedule_cron()` previously ran only from `Activator::activate()`. After deploy/rsync without reactivation, the cron event can be missing while the hook handler remains registered — pending jobs stall (e.g. campaign #134).

## Scope

| In | Out |
|----|-----|
| Re-ensure cron on every `init` (idempotent) | Resend send / claim / audience filter changes |
| Re-ensure cron after successful enqueue | Kinsta deploy |
| Unit tests + version `0.3.12` | Force-push |

## Area index

| # | Spec | Risk |
|---|------|------|
| 01 | [01-reensure-cron.md](./01-reensure-cron.md) | 🟡 Yellow — cron scheduling only; no send-path logic change |

## Build order

1. Spec + failing unit tests
2. `Bootstrap` init → `schedule_cron`; `QueueService` success path → `schedule_cron`
3. Version `0.3.12` + `vendor/bin/phpunit -c phpunit.unit.xml.dist` + phpstan
4. Commit + push `main` (no deploy)

## References

- `BroadcastSender::schedule_cron()` / `CRON_HOOK`
- `Activator::schedule_events()` (activation-only path)
- Similar deploy-without-reactivate pattern: `CampaignViewPage` rewrite version flush
