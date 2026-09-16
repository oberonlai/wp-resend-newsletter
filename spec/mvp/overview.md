# WP Resend Newsletter — MVP Implementation Plan

## Codebase Analysis
- Namespace: `WpResendNewsletter` | Layers (planned): Domain / Infrastructure / Persistence / Application / Admin+REST+Cron
- Convention: PSR-4 under `src/`, OOP Bootstrap + Activator + Deactivator; function prefix `wprn_`, consts `WP_RESEND_NEWSLETTER_*` / `WPRN_*`
- Reusable: scaffold only (`Bootstrap`, `Activator`, `Deactivator`); no feature code yet (area 01 client exists)
- Conflicts: none (greenfield). Test DB name reserved: `wordpress_test_wprn`

## References
- Source: `@everything-wp/skills/wp-backend` (custom tables, OOP, security, REST)
- Key patterns: `dbDelta` custom tables + Repository; options page with nonces/capabilities; REST with permission callbacks; never store secrets in VCS
- Gaps: webhook signing docs to confirm when wiring 06; Broadcast/Segment sync implemented in areas 03–04
- Auto-loaded: `wp-backend` (plugin main + `src/`)

## Hard send-path rule (Resend)
| Mail type | Resend API | Forbidden |
|-----------|------------|-----------|
| **Newsletter campaigns (marketing/bulk)** | **Broadcasts** — `$resend->broadcasts->create([..., 'segment_id' => …, 'send' => true])` | Transactional `emails` / `emails/batch` / `batch->send` |
| **Operational only** (double opt-in confirm; optional admin test) | Transactional `emails` or `batch->send` (≤50) | Using Broadcasts for single confirm mail is unnecessary; never invert the table |

Contacts for campaigns live in **Resend Segments** (Audiences renamed). Local WP tables remain the **source of truth for WP admin** (list, status, tokens); the **campaign send path** syncs confirmed subscribers → Resend contacts on the configured segment, then sends one Broadcast.

**Sending domain:** `news.oberonlai.blog` — from address must use this verified domain (e.g. `something@news.oberonlai.blog`).

## Operation Flow
1. Admin saves Resend API key + from address (verified domain) + `segment_id` in Settings (key never committed)
2. Visitor / admin adds subscriber → **transactional** confirmation email → confirmed status (local table)
3. Admin creates campaign (subject + HTML/text body; include `{{{RESEND_UNSUBSCRIBE_URL}}}` in bodies)
4. Admin starts send → ensure Segment exists → sync confirmed subscribers to Resend contacts on that segment → create+send **Broadcast** (`send => true`)
5. Local `send_jobs` (or equivalent) tracks **broadcast id / status**, not per-recipient transactional batch chunks
6. Recipients unsubscribe via Resend’s `{{{RESEND_UNSUBSCRIBE_URL}}}` and/or local signed links as designed in 02/06
7. Resend webhooks update bounce / complaint status on subscribers and delivery events

## DNS / deliverability (news.oberonlai.blog)
Required records (**zone-relative on `oberonlai.blog`**) from Resend — values must match Resend Records **exactly** (especially SPF):

1. **TXT** `resend._domainkey.news` — DKIM (`p=…`)
2. **MX** `send.news` priority **10** → `feedback-smtp.us-east-1.amazonses.com`
3. **TXT** `send.news` → `v=spf1 include:amazonses.com ~all` ← SPF must be exact
4. **CNAME** `rsend.news` → `send.forge.rmta.net`

Public dig currently **EMPTY** — Cloudflare add / Resend verification pending. Do not send production campaigns until domain shows verified in Resend.

## Out of scope (MVP)
- Drag-drop / visual email designer
- Global `wp_mail` replacement
- Multi-provider adapters
- Public GitHub / WordPress.org submission (later: private repo under oberonlai)

## Architecture (target)
| Layer | Responsibility |
|-------|----------------|
| Domain | Subscriber / Campaign / SendJob (broadcast-oriented) entities & status enums |
| Infrastructure | `ResendClient`: `send_broadcast` for campaigns; `send_batch` / single send **only** for transactional/ops |
| Persistence | Custom tables + Repositories (`subscribers`, `campaigns`, `send_jobs`, optional `delivery_events`); settings store `segment_id` |
| Application | Subscribe/Confirm/Unsubscribe; Segment sync; Broadcast enqueue/worker; WebhookProcessor |
| WP surface | Admin UI, REST (public confirm/unsub + webhook), Cron |

**Secrets:** API key via Settings option and/or `WPRN_RESEND_API_KEY` constant in `wp-config.php`. Never in git / chat. Owned by 幕僚長 secrets.

---

## (1) Settings and Resend client
- Options schema (from_*, api_key, **segment_id**, webhook_secret), Settings API, `manage_options`
- `ResendClient`: Broadcast methods for campaigns; transactional batch helper capped at ≤50
- Composer require `resend/resend-php`

→ Details: [01-settings-and-resend-client.md](./01-settings-and-resend-client.md)

---

## (2) Subscribers
- Custom table + repository
- Subscribe / double opt-in confirm / unsubscribe with signed tokens
- Confirm mail via **transactional** Resend API only
- Statuses: pending, confirmed, unsubscribed, bounced, complained

→ Details: [02-subscribers.md](./02-subscribers.md)

---

## (3) Campaigns
- Custom table + repository
- Draft / scheduled / sending / sent / cancelled
- Subject + HTML + text; bodies must support `{{{RESEND_UNSUBSCRIBE_URL}}}`
- Send path owned by 04 (Broadcasts — **not** transactional batch)

→ Details: [03-campaigns.md](./03-campaigns.md)

---

## (4) Queue / Broadcast sender
- Ensure Resend Segment (`segment_id` in settings); sync confirmed → contacts
- Create+send Broadcast; local job tracks broadcast id/status
- Cron/worker for sync + broadcast create; retries with backoff
- **Never** call `emails` / `batch->send` for campaign blasts

→ Details: [04-queue-sender.md](./04-queue-sender.md)

---

## (5) Admin UI
- Menu pages: Settings, Subscribers list, Campaigns list/edit, Queue/broadcast status
- WP List Tables; nonces + capabilities

→ Details: [05-admin-ui.md](./05-admin-ui.md)

---

## (6) Webhooks
- REST route for Resend bounce/complaint (and delivery if useful)
- Signature verification; update subscriber + optional `delivery_events`
- No secrets in responses/logs

→ Details: [06-webhooks.md](./06-webhooks.md)

---

**Plan saved to**: `spec/mvp/`

**Next step**: continue area 02 (subscribers + transactional confirm) after 幕僚長 confirms Broadcasts send-path plan.  
TDD recommended (`--tdd=int`): settings persistence, client boundary, and later DB/REST/cron paths all need WordPress integration tests.

---

## Later: v1.1 Tags + Analytics

Subscriber tags (AND send filter) and engagement analytics (opens/clicks via Resend webhooks) are planned in **[`spec/v1.1/`](../v1.1/overview.md)** — not part of MVP implementation. Build order: tags first, then analytics.
