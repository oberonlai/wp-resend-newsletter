# WP Resend Newsletter

WordPress plugin for in-site newsletter subscriber lists, campaigns, and email sending via the [Resend](https://resend.com) API (`resend/resend-php`).

**Author:** Oberon Lai (Codotx) · **Version:** 0.3.1 · **Requires:** WordPress 6.5+, PHP 8.1+

## MVP scope

- Subscriber list with double opt-in (confirm) and unsubscribe
- Campaigns (subject + HTML/text body — **no** drag-drop designer)
- Campaign delivery via Resend **Broadcasts** + Segments (not transactional batch blasts)
- Bounce / complaint handling via Resend webhooks
- Settings for API key, from address, and Resend `segment_id`

## Resend: Broadcasts vs transactional

| Use case | API |
|----------|-----|
| **Newsletter campaigns** (marketing / bulk) | Resend **Broadcasts** (`broadcasts->create` with `segment_id` and `send => true`). Confirmed subscribers are synced to Resend **Contacts** on a **Segment**. |
| **Operational mail only** (double opt-in confirm; optional admin test) | Transactional `emails` / `batch->send` (≤50 per request). **Never** use this path for campaign blasts. |

Local WP tables stay the source of truth for admin UI; the campaign **send path** is Broadcasts.

## Sending domain & DNS

Production from-address must use the verified domain **`news.oberonlai.blog`** (e.g. `news@news.oberonlai.blog`).

Required DNS on zone `oberonlai.blog` (must match Resend Records exactly):

1. TXT `resend._domainkey.news` (DKIM)
2. MX `send.news` pri 10 → `feedback-smtp.us-east-1.amazonses.com`
3. TXT `send.news` → `v=spf1 include:amazonses.com ~all`
4. CNAME `rsend.news` → `send.forge.rmta.net`

Domain DNS is configured in Cloudflare and verified in Resend for production sends.

## Explicitly out of scope (MVP)

- Replacing global `wp_mail`
- Visual email designer
- Committing API keys or secrets to VCS

## Secrets

Store the Resend API key in WordPress options / `wp-config.php` constants only. Never commit `.env`, keys, or webhook secrets. See `.gitignore`.

## Local development

```bash
composer install
composer test:install   # uses DB wordpress_test_wprn — drops/recreates that DB only
composer test
composer phpstan
composer phpcs
composer build
```

Local WordPress (if present): `/workspace/wordpress` → http://127.0.0.1:8080 (admin/admin).

## Architecture (planned)

Domain / Infrastructure (`ResendClient`: `send_broadcast` for campaigns, `send_batch` for transactional only) / Persistence (custom tables + Repository) / Application services / WP Admin + REST + Cron.

See `spec/mvp/overview.md` for the full plan.
