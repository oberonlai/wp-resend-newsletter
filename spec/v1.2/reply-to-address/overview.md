# Reply-To address for outgoing mail (v1.2)

Readers who hit **Reply** on a newsletter currently reply to the From address
(`hello@news.oberonlai.blog`). `news.oberonlai.blog` has no MX record, so replies
bounce. DNS is intentionally left untouched — the fix is a `Reply-To` header only.

**Plugin version:** `0.3.13`

## Scope

| In | Out |
|----|-----|
| New setting `reply_to` (validated email; empty = no header) | DNS / Cloudflare / MX changes |
| Pass `reply_to` on Broadcast create (campaigns) | Per-campaign Reply-To override |
| Pass `reply_to` on transactional sends (admin test mail, double opt-in confirm) | Changing the From address |
| Unit + integration tests, version `0.3.13` | |

## Area index

| # | Spec | Risk |
|---|------|------|
| 01 | [01-reply-to-setting.md](./01-reply-to-setting.md) | 🟡 Yellow — touches the campaign send payload (additive optional field only) |

## Build order

1. Spec + failing tests (unit: `ResendClient`; integration: settings, broadcast queue, test sender, confirm mail)
2. `SettingsPage` field + sanitize; `ResendClient::reply_to_from_settings()` + `send_broadcast` pass-through
3. Wire `BroadcastSender`, `CampaignTestSender`, `SubscribeService`
4. Version `0.3.13`; PHPUnit (unit + integration) + PHPStan + PHPCS
5. Commit, push, tag `v0.3.13`, release, deploy to oberonlai.blog, set `reply_to = hi@oberonlai.blog`

## References

- Resend create broadcast: `reply_to` (string | string[])
- Resend send email / batch: `reply_to` (string | string[])
