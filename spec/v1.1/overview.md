# WP Resend Newsletter — v1.1 Implementation Plan (Tags + Analytics)

## Relationship to MVP
Builds on completed MVP areas in [`spec/mvp/`](../mvp/overview.md): subscribers, campaigns, Broadcast + Segment sync, admin UI, and Svix-signed webhooks (`POST /wp-json/wprn/v1/webhooks/resend`) with `wprn_delivery_events`.

**Do not implement until these plans are accepted.** Local-first everything-wp style: plan → TDD → code.

## Product scope
| Area | User value | ConvertKit-like analogy |
|------|------------|-------------------------|
| **Subscriber tags** | Tag people; when sending a campaign, filter recipients by selected tag(s) | Tags + send filter |
| **Engagement analytics** | Per-campaign and per-subscriber open / link-click stats from Resend webhooks | Opens, clicks, unique counts |

## Architecture (unchanged layers)
| Layer | v1.1 additions |
|-------|----------------|
| Domain | `Tag` entity; tag assignment; engagement event types (`email.opened`, `email.clicked`) |
| Infrastructure | No new provider for tags. Analytics: extend webhook handling only (same Svix path). Optional Resend domain tracking docs in Settings. |
| Persistence | `wprn_tags`, `wprn_subscriber_tag`; extend `wprn_delivery_events` (+ lookup by `resend_broadcast_id`); optional campaign analytics aggregates |
| Application | `TagService`; filter confirmed+tagged before Segment sync; `WebhookProcessor` maps opens/clicks + **resolves `campaign_id` from `broadcast_id`** |
| WP surface | Tags admin + subscriber tag UI; campaign send tag picker; campaign Analytics view; subscriber recent events |

## Hard send-path rule (unchanged)
Campaigns still send via Resend **Broadcasts** + Segment sync — **not** transactional `emails` / `batch->send`.

Tags are **local**. Do **not** sync tags to Resend Topics unless a later version needs them. v1.1: **filter locally → sync only matching confirmed subscribers onto a send audience segment → Broadcast**.

## Key product decisions (locked for v1.1)
| Decision | Choice | Rationale |
|----------|--------|-----------|
| Multi-tag send filter | **AND** | Recipient must have *all* selected tags. OR deferred. |
| Opens / clicks display | **Unique and total** both shown | Unique = distinct subscriber_id (or email) per campaign+event type; total = raw event rows (idempotent on `provider_event_id`). |
| Tags ↔ Resend | **Local only** | Prefer filter + segment contact set; no Topics sync. |
| Audience for tagged send | **Campaign-scoped segment** (preferred) | Avoid leaving non-matching contacts on the global settings `segment_id` who would still receive the Broadcast. Unfiltered send keeps using settings `segment_id`. |
| Map events → campaign | Resolve `data.broadcast_id` → `campaigns.resend_broadcast_id` → local `campaign_id` | Closes MVP gap where `WebhookProcessor` stores `campaign_id = 0`. |
| Storage for opens/clicks | Extend **`delivery_events`** (+ nullable metadata columns) | One audit/idempotency table; avoid parallel engagement table for MVP. |

## Out of scope (v1.1)
- OR tag filter, tag hierarchies, auto-tagging rules, Resend Topics sync
- Heatmaps, device/geo charts, A/B subject tests
- Storing raw webhook bodies, click IP, or User-Agent in logs or admin exports
- Replacing transactional confirm mail with tracked Broadcasts
- Custom email blocks, multi-provider analytics, pixel-perfect all email clients (full-page email editor is area 05; honest Gmail/Apple Mail target)

## Prerequisites / ops notes
- Resend domain **open_tracking** + **click_tracking** must be enabled and tracking subdomain verified (e.g. `links.news.oberonlai.blog`) or open/click webhooks will not fire. Document on Settings / Analytics empty state.
- Webhook endpoint already exists; admin must also subscribe to `email.opened` and `email.clicked` in the Resend dashboard (in addition to bounce/complaint).

## Build order
1. **`01-subscriber-tags`** — tables, repository, admin tag CRUD + assign, campaign send filter (AND), SegmentSync filtered audience + campaign-scoped segment, tests.
2. **`02-engagement-analytics`** — extend webhooks + `delivery_events`, resolve `campaign_id` from broadcast id, campaign Analytics UI (unique + total), per-subscriber recent events, privacy, tests.

3. **`03-subscribe-block`** — Gutenberg subscribe form (dynamic block) + browser confirm HTML page; no shortcode.
4. **`04-campaign-block-editor`** — Replace campaign edit HTML textarea with WordPress block editor; store rendered HTML in `body_html`; send path unchanged.
5. **`05-full-page-email-editor`** — Full-page editor chrome + email-faithful canvas CSS + `EmailHtmlRenderer` (wrap + inline styles) on save.
6. **`06-email-brand-template`** — Default brand shell (logo header + contact/unsub footer + oberonlai.blog typography tokens) on every campaign via `EmailHtmlRenderer`.

Tags first so campaign analytics can later attribute to the filtered audience that was actually synced.

## Schema sketch (additive)
```
wprn_tags
  id, name (unique), slug (unique), created_at, updated_at

wprn_subscriber_tag
  subscriber_id, tag_id, created_at
  PRIMARY (subscriber_id, tag_id)
  KEY tag_id

wprn_campaigns (extend)
  + filter_tag_ids TEXT/JSON NULL   -- snapshot of tag ids used at send (AND)
  + audience_segment_id varchar NULL -- Resend segment used for this send (scoped)

wprn_delivery_events (extend)
  + provider_broadcast_id varchar(64) NULL  -- from data.broadcast_id
  + provider_email_id varchar(64) NULL
  + link_url varchar(500) NULL               -- click target only; no IP/UA
  KEY provider_broadcast_id
  KEY (campaign_id, event_type)
```

## Area index
| # | File | Risk |
|---|------|------|
| 01 | [01-subscriber-tags.md](./01-subscriber-tags.md) | 🟡 Yellow (PII join tables, send audience) |
| 02 | [02-engagement-analytics.md](./02-engagement-analytics.md) | 🔴 Red (public webhook, engagement PII adjacency) |
| 03 | [03-subscribe-block.md](./03-subscribe-block.md) | 🟡 Yellow (public form + confirm HTML) |
| 04 | [04-campaign-block-editor.md](./04-campaign-block-editor.md) | 🟡 Yellow (admin block editor; body_html storage) |
| 05 | [05-full-page-email-editor.md](./05-full-page-email-editor.md) | 🟡 Yellow (full-page chrome + email shell/inline CSS) |
| 06 | [06-email-brand-template.md](./06-email-brand-template.md) | 🟢 Green (brand shell/footer tokens) |

**Plan saved to**: `spec/v1.1/`

**Next step**: implement 06 with TDD; 01–05 already landed.
