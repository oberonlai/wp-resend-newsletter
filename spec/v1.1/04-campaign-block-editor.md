# Campaign Block Editor (v1.1)

**Risk Tier**: 🟡 Yellow — admin UI + asset enqueue; persists HTML via existing save path; no send-path change

## Goal
Replace the campaign edit page HTML body `<textarea>` (`admin.php?page=wprn-campaign-edit`) with a **WordPress block editor** (Gutenberg) for composing newsletter HTML. User request: 這個編輯器請幫我改成區塊編輯器.

## Decisions (locked)
| Topic | Decision |
|-------|----------|
| Storage | Keep existing `body_html` column. Store **rendered email-ready HTML** (no `<!-- wp:` comments in what Resend receives). Send path unchanged — Broadcasts still use `body_html` as HTML. |
| Load | Convert stored HTML → blocks with `wp.blocks.rawHandler({ HTML })` (or `parse()`); freeform / `core/html` fallback OK for Kit-imported / legacy HTML. |
| Mount | Campaign edit screen only (`wprn-campaign-edit`). |
| Assets | Vanilla WP script deps (`wp-block-editor`, `wp-blocks`, `wp-components`, `wp-element`, `wp-data`, `wp-i18n`, `wp-block-library` + edit-blocks styles). Prefer plain JS under `src/Admin/js/` with `wp.*` globals (like Subscribe block). No new `@wordpress/scripts` pipeline unless already present. |
| Form | Still POST to `AdminActions::handle_save_campaign`. Hidden `name="body_html"` synced from editor before submit. Subject, tag filter, `body_text` kept. |
| body_text | Optional: if empty on save, auto-fill from stripped HTML. |
| Capability / nonces | `manage_options`; existing save nonce unchanged. |
| Allowed blocks (MVP) | Core subset suitable for email: `core/paragraph`, `core/heading`, `core/list`, `core/image`, `core/quote`, `core/separator`, `core/buttons`, `core/button`, `core/html`. No custom email blocks. No drag-from-media beyond core image block. |
| Out of scope | Full email design system, custom blocks, changing Broadcast send path, CloudAgent / Kinsta deploy, secrets in output. |

## User Stories

**As a** newsletter admin  
**I want to** compose campaign HTML with the WordPress block editor  
**So that** I can use familiar blocks instead of raw HTML textarea

**Scenario**: Editor root mounts on campaign edit page
  **Given** an admin on `admin.php?page=wprn-campaign-edit`
  **When** the edit form renders
  **Then** the page contains an editor mount root (e.g. `#wprn-campaign-block-editor`)
  **And** there is a hidden input `name="body_html"` (not a visible HTML `<textarea name="body_html">`)
  **And** an accessible label for the HTML body field remains

**Scenario**: Block editor assets enqueue only on campaign edit
  **Given** an admin viewing the campaign edit screen
  **When** scripts/styles are enqueued
  **Then** the campaign block-editor script handle is enqueued
  **And** on a different admin page (e.g. campaigns list / settings) that handle is not enqueued

**Scenario**: Existing HTML loads into blocks
  **Given** a draft campaign whose `body_html` is `<p>Hello</p>` (or Kit-imported markup)
  **When** the edit screen loads
  **Then** the editor is initialized with that HTML available to the client (localized / data attribute / hidden field value)
  **And** client JS can turn it into blocks via `rawHandler` / `parse` (freeform fallback OK)

**Scenario**: Save still persists body_html via existing handler
  **Given** an admin with `manage_options` and a valid save nonce
  **When** they POST `subject` + `body_html` to `AdminActions::handle_save_campaign`
  **Then** the campaign row stores the posted `body_html` (sanitized as today)
  **And** send path remains Resend Broadcasts using stored `body_html`

**Scenario**: Optional body_text auto-fill when empty
  **Given** a save with non-empty `body_html` and empty `body_text`
  **When** the save handler runs
  **Then** `body_text` may be filled from stripped HTML (or left empty if already provided)

---

**As a** non-admin  
**I want** unauthorized saves rejected  
**So that** campaigns cannot be edited without capability

**Scenario**: Author cannot save campaign
  **Given** an author-role user
  **When** they POST to the save campaign action (even with a nonce)
  **Then** the request is rejected (`wp_die` / insufficient permissions)
  **And** no campaign row is created

**Scenario**: Missing nonce is rejected
  **Given** an admin POST without a valid save nonce
  **When** the handler runs
  **Then** the request is rejected and nothing is saved

## File layout
```
src/Admin/CampaignBlockEditor.php   — enqueue + localize on edit screen only
src/Admin/js/campaign-block-editor.js — mount BlockEditorProvider; sync hidden body_html
src/Admin/js/campaign-block-editor.asset.php
src/Admin/CampaignsPage.php          — replace textarea with mount + hidden input
src/Bootstrap.php                    — register CampaignBlockEditor hooks
tests/Integration/Admin/CampaignBlockEditor_Test.php
```

## Allowed blocks (document)
`core/paragraph`, `core/heading`, `core/list`, `core/image`, `core/quote`, `core/separator`, `core/buttons`, `core/button`, `core/html`

## Tasks
- [x] Spec accepted (this file + overview pointer)
- [x] Failing tests first (enqueue scope; edit render has editor root + hidden body_html; save persists; cap/nonce regression)
- [x] `CampaignBlockEditor` enqueue on `wprn-campaign-edit` only
- [x] Replace textarea with mount div + hidden `body_html`; keep label / unsubscribe hint
- [x] JS: register allowlisted core blocks, mount editor, sync HTML on change/submit
- [x] Optional empty `body_text` auto-fill from stripped HTML on save
- [x] Review 🔴/🟡 fixes
- [x] Verify (phpstan, phpunit, phpcs)

## Tests (TDD)
| Scenario | Test location |
|----------|---------------|
| Assets enqueue only on campaign edit | `tests/Integration/Admin/CampaignBlockEditor_Test.php` |
| Edit render contains editor root + hidden body_html, no textarea name=body_html | same |
| Save persists body_html | same / existing CampaignsFlow |
| Cap / nonce rejection | same + existing AdminUi_Test |

## Risk notes
- Do not enqueue heavy block-library assets on every admin page.
- Do not put block-comment markup into Resend HTML without `do_blocks` — store rendered HTML.
- Do not alter BroadcastSender / queue send path.
