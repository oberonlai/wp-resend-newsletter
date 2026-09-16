# Subscribe Gutenberg Block (v1.1)

**Risk Tier**: 🟡 Yellow — public form + confirm link; enumeration-safe messaging; no new PII columns

## Goal
Let visitors subscribe via a **Gutenberg dynamic block** that posts to existing `POST /wp-json/wprn/v1/subscribers`. Double opt-in stays in `SubscribeService` / `ConfirmService`. Confirm links from email must open a **browser-friendly HTML** page (REST `/confirm` remains JSON for API).

## Decisions (locked)
| Topic | Decision |
|-------|----------|
| Implementation | **Gutenberg Block only** (`/frontend-page` → Gutenberg). **No shortcode.** |
| Block type | **Dynamic** — `block.json` + `render.php` / `render_callback`; editor via plain `edit.js` (no webpack required) |
| Form fields | Email (required). Optional name deferred (no DB column). |
| Submit | Front-end `fetch` → existing REST subscribe (rate-limit / nonce already on REST) |
| Success copy | Enumeration-safe; tell user to check email for confirm |
| Confirm UX | Public HTML handler `/?wprn_confirm={token}`; email link uses this URL |
| Confirm status | Only `ConfirmService` sets status `confirmed` |
| Assets | Minimal CSS + view JS; text domain `wp-resend-newsletter` |
| Out of scope | Shortcode, npm/webpack build, CloudAgent, analytics/tags changes, real API keys |

## User Stories

**As a** site editor  
**I want to** insert a Newsletter Subscribe block in the editor  
**So that** any page/post can collect double opt-in subscribers

**Scenario**: Block is registered
  **Given** the plugin is loaded
  **When** block types are queried
  **Then** `wp-resend-newsletter/subscribe` is registered
  **And** no `wprn_subscribe` shortcode is registered

**Scenario**: Block renders accessible email form
  **Given** block attributes `title` and `buttonLabel`
  **When** the block is rendered on the front end
  **Then** HTML includes a labeled email input (`type="email"`, required) and a submit button
  **And** title / button labels from attributes appear
  **And** a status region with `aria-live` is present for messages

**Scenario**: Editor can edit title and button labels
  **Given** the block in the block editor
  **When** the editor changes title / button label attributes
  **Then** those attributes are stored on the block (Inspector / edit UI)
  **And** `save` returns `null` (dynamic)

---

**As a** visitor  
**I want to** submit my email on the subscribe form  
**So that** I receive a confirmation email without the site revealing whether I was already subscribed

**Scenario**: Form posts to existing REST subscribe
  **Given** the subscribe block on a public page
  **When** the visitor submits a valid email
  **Then** the front-end script `POST`s to `/wp-json/wprn/v1/subscribers`
  **And** on success shows a generic message instructing them to check email
  **And** response body does not expose `confirm_token` or subscriber status

**Scenario**: Invalid email is rejected client-side or by REST
  **Given** the subscribe form
  **When** an invalid email is submitted
  **Then** the visitor sees an error; no confirmed subscriber is created

---

**As a** pending subscriber  
**I want to** open the confirm link from email in a browser  
**So that** I see a friendly success or error page (not raw JSON)

**Scenario**: Valid confirm token shows HTML success
  **Given** a pending subscriber with a valid confirm token
  **When** they GET `/?wprn_confirm={token}` in a browser
  **Then** an HTML page is returned (Content-Type text/html)
  **And** subscriber status becomes `confirmed`
  **And** the page shows a success message

**Scenario**: Invalid or expired token shows HTML error
  **Given** a missing/forged/expired token
  **When** they GET `/?wprn_confirm={token}`
  **Then** an HTML error page is returned
  **And** no pending subscriber is wrongly confirmed

**Scenario**: Confirm email uses HTML confirm URL
  **Given** a new subscribe via SubscribeService
  **When** the transactional confirm mail is built
  **Then** the link uses `ConfirmPage::url()` (`wprn_confirm` query arg), not only the REST JSON endpoint

**Scenario**: REST confirm remains JSON for API
  **Given** a valid confirm token
  **When** `GET /wp-json/wprn/v1/subscribers/confirm?token=` is called
  **Then** a JSON success body is returned (API clients unchanged)

## File layout (everything-wp `/frontend-page` Gutenberg)
```
src/Blocks/Subscribe/
  index.php          — register_block_type + asset registration
  block.json
  edit.js            — editor UI (wp.* globals, no webpack)
  editor.asset.php
  render.php         — front-end markup
  style.css
  view.js            — form fetch submit
src/Frontend/ConfirmPage.php — public HTML confirm handler
```

## Tasks
- [x] Spec accepted (this file)
- [x] Register dynamic block `wp-resend-newsletter/subscribe` from Bootstrap `init`
- [x] `render.php` accessible form + attribute title/buttonLabel
- [x] `edit.js` InspectorControls for title / button label; `save: null`
- [x] `style.css` + `view.js` enqueued when block renders; localize REST URL
- [x] `ConfirmPage` HTML handler + SubscribeService email link
- [x] Remove shortcode (`[wprn_subscribe]`) if present
- [x] Integration tests: block registered, render markup, confirm HTML success/error, no shortcode
- [x] Update `spec/v1.1/overview.md` area index

## Tests (TDD)
| Scenario | Test location |
|----------|---------------|
| Block registered / no shortcode | `tests/Integration/Frontend/SubscribeBlock_Test.php` |
| Render markup (email, labels, aria-live) | same |
| Confirm HTML success → confirmed | same |
| Confirm HTML invalid token | same |
| Email confirm URL contains `wprn_confirm` | extend subscribers flow or same |

## Do not
- Break analytics / tags / queue / webhooks
- Add CloudAgent, push, or real API keys
- Require npm/webpack for this block
