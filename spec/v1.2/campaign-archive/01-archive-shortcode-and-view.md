# Campaign Archive Shortcode + Public View

**Risk Tier**: 🟡 Yellow — public listing of sent campaigns; body via `wp_kses_post` after unwrap

## Goal

Visitors on a marketing page see a paginated list of **sent** campaigns (subject + date). Clicking a title opens a friendly HTML page with the newsletter body for browsing.

## Decisions (locked)

| Topic | Decision |
|-------|----------|
| List surface | Shortcode `[wprn_archive]` (block optional later) |
| Filter | `status = sent` only |
| Order | `created_at DESC`, then `id DESC` |
| Pagination | Default `limit=10`; page via `wprn_apage` query arg |
| Single view | `/?wprn_campaign={id}` via `template_redirect` (mirror ConfirmPage) |
| Body | `EmailHtmlRenderer::unwrap()` then `wp_kses_post` |
| Back link | `wp_get_referer()` if same-host, else `/wordpress-newsletter/` |
| Admin UI | Do **not** edit CampaignsPage / CampaignBlockEditor |

## User Stories

**As a** site visitor  
**I want to** see past newsletter subjects and dates on the subscribe page  
**So that** I can browse what Oberon has already sent

**Scenario**: Archive lists only sent campaigns newest-first  
  **Given** campaigns with statuses `sent`, `draft`, and `ready`  
  **When** `[wprn_archive]` renders  
  **Then** only `sent` rows appear  
  **And** subjects link to `/?wprn_campaign={id}`  
  **And** a human-readable date is shown

**Scenario**: Pagination defaults to 10  
  **Given** 12 sent campaigns  
  **When** `[wprn_archive]` renders without attrs on page 1  
  **Then** 10 items are listed  
  **And** a next-page control uses `wprn_apage=2`

**Scenario**: Custom limit attribute  
  **Given** 5 sent campaigns  
  **When** `[wprn_archive limit="3"]` renders  
  **Then** 3 items are listed

---

**As a** site visitor  
**I want to** open a campaign title  
**So that** I can read the full newsletter HTML in the browser

**Scenario**: Sent campaign shows HTML document  
  **Given** a campaign with `status=sent`, subject, and body_html  
  **When** they GET `/?wprn_campaign={id}`  
  **Then** Content-Type is text/html  
  **And** the page includes subject, date, and unwrapped body  
  **And** a back link is present

**Scenario**: Non-sent or missing id is 404  
  **Given** a draft campaign or unknown id  
  **When** they GET `/?wprn_campaign={id}`  
  **Then** HTTP 404 friendly HTML is returned  
  **And** body_html of drafts is not exposed

## File layout

```
src/Persistence/CampaignRepository.php  — find_sent / count_sent
src/Frontend/CampaignArchiveShortcode.php
src/Frontend/CampaignViewPage.php
src/Bootstrap.php                       — register shortcode + view
tests/Integration/Frontend/CampaignArchive_Test.php
.import/page-4011-newsletter.html       — Gutenberg markup for page 4011
```

## Tasks

- [x] Spec accepted (this file)
- [x] `find_sent` / `count_sent` on CampaignRepository
- [x] Shortcode `[wprn_archive]` with limit + pagination
- [x] CampaignViewPage `wprn_campaign` query handler
- [x] Bootstrap registration
- [x] Integration tests (list + 404)
- [x] Page markup for production page 4011

## Tests (TDD)

| Scenario | Test location |
|----------|---------------|
| Shortcode lists sent only + links | `tests/Integration/Frontend/CampaignArchive_Test.php` |
| Pagination / limit | same |
| Sent view HTML | same |
| Draft / missing → 404 | same |

## Do not

- Deploy plugin to Kinsta
- Break classic campaign editor work
- Expose non-`sent` campaigns publicly
