# Full-Page Post-Style Block Editor + Email HTML (v1.1 area 05)

**Risk Tier**: 🟡 Yellow — admin UI chrome (native Gutenberg patterns) + email HTML transform on save; send path still Broadcasts/`body_html`; no new deps

## Goal
Make campaign edit (`wprn-campaign-edit`) look and behave like **WordPress’s built-in post/page block editor** (`post.php` chrome): top bar, block inserter, list view, settings sidebar, writing canvas — not a classic form and **not** a custom “email letter / 600px card” designer.

User (corrected): 改成（或是改善）像 WordPress 內建文章的區塊編輯器的呈現.

Email fidelity still applies **on send storage**: saved `body_html` is email-ready (shell + inline CSS) so Gmail/Apple Mail approximate the editor typography. Admin UX target is post-editor, not letter-preview.

## Relationship to 04
Builds on [`04-campaign-block-editor.md`](./04-campaign-block-editor.md). This area replaces nested form / letter-canvas experiments with **post-editor-like InterfaceSkeleton chrome** + `EmailHtmlRenderer` on save.

## Decisions (locked)
| Topic | Decision |
|-------|----------|
| Admin UX | Match native post block editor: `InterfaceSkeleton` + `FullscreenMode` from `wp.editor`; inserter + list view secondary sidebar; document settings sidebar (tags / mark-ready / optional text); subject as **title field** (post-title styling). |
| Not doing | Bespoke 600px “letter on gray desk” as primary chrome. (Email shell may still be ~600px wide for inboxes.) |
| Storage | Keep single `body_html`. Persist email-ready HTML (table shell + inline styles). No CPT dual-storage unless a later iteration needs it. |
| body_text | Optional / collapsed in document sidebar; empty on save → autofill from stripped HTML. |
| Email fidelity | Shared typography tokens for `.editor-styles-wrapper` + PHP DOMDocument inliner. Zero new Composer deps. |
| Shell | Resend-safe table fragment + `wprn-email-shell` marker (survives `wp_kses_post`). |
| Allowed blocks | Same as 04 (+ freeform for Kit imports). |
| Save path | `AdminActions::handle_save_campaign` + nonce/`manage_options`. `EmailHtmlRenderer::render()` before persist. |
| Load | Unwrap shell for editor initial HTML (`rawHandler` / freeform). |
| Body class | `block-editor-page is-fullscreen-mode` on campaign edit for familiar fullscreen chrome. |
| Out of scope | Custom email blocks; pixel-perfect all clients; CloudAgent/Kinsta; Broadcast path changes; mandatory CPT host (optional later). |

## User Stories

**As a** newsletter admin  
**I want** campaign editing to feel like editing a WordPress post  
**So that** I get the familiar block inserter, list view, title, and settings sidebar

**Scenario**: Post-editor app chrome
  **Given** an admin on `admin.php?page=wprn-campaign-edit`
  **When** the edit screen renders
  **Then** the root contains class `wprn-campaign-editor-app`
  **And** the mount `#wprn-campaign-block-editor` is present
  **And** there is no visible `<textarea name="body_html">`
  **And** subject is available as a title-style field (name=`subject` and/or synced hidden/input)
  **And** the page is prepared for fullscreen block-editor chrome (`block-editor-page` body class and/or interface skeleton once JS mounts)

**Scenario**: Document sidebar holds campaign meta
  **Given** an editable campaign with tags in the system
  **When** the editor document sidebar is used
  **Then** send-filter tags and mark-ready (when allowed) are campaign settings — not a classic form-table stacked above a tiny editor
  **And** plain text body is optional/collapsed with autofill on save

---

**As a** newsletter admin  
**I want** saved HTML to carry inline email styles  
**So that** recipients see formatting consistent with editor typography intent

**Scenario**: EmailHtmlRenderer wraps and inlines
  **Given** inner HTML `<p>Hello</p>` and a button-like `<a class="wp-block-button__link">Go</a>`
  **When** `EmailHtmlRenderer::render()` runs
  **Then** output contains the email shell marker / outer table structure
  **And** a `<p>` has a non-empty `style` attribute
  **And** the button link has inline background/color/padding

**Scenario**: Idempotent wrap (no double shell)
  **Given** HTML that already includes `wprn-email-shell`
  **When** `render()` runs again
  **Then** only one shell wrapper is present

**Scenario**: Unwrap for editor load
  **Given** stored `body_html` with email shell around `<p>Kit</p>`
  **When** the edit screen prepares initial editor HTML
  **Then** the block editor receives unwrapped inner content

**Scenario**: Save persists rendered email HTML
  **Given** admin + valid save nonce
  **When** they POST subject + inner `body_html`
  **Then** row stores shell + inlined HTML; empty `body_text` autofills; Broadcast path unchanged

**Scenario**: Assets only on edit screen
  **Given** campaign edit vs another admin page
  **When** scripts/styles enqueue
  **Then** campaign editor script + editor chrome styles enqueue only on `wprn-campaign-edit`

---

**As a** non-admin  
**I want** unauthorized saves rejected

**Scenario**: Author cannot save campaign → rejected  
**Scenario**: Missing nonce → rejected; nothing saved

## Honest limits
Pixel-perfect across all email clients is impossible. Target Gmail + Apple Mail via table shell + inline critical CSS. Admin UI matches post editor chrome; inbox rendering is best-effort from the same typography tokens.

## File layout
```
spec/v1.1/05-full-page-email-editor.md
src/Application/EmailHtmlRenderer.php
src/Admin/css/campaign-block-editor.css   — post-editor/fullscreen chrome (not letter card)
src/Admin/CampaignBlockEditor.php         — enqueue wp-editor + edit-post styles; body class
src/Admin/js/campaign-block-editor.js     — InterfaceSkeleton + inserter/list/sidebar/title
src/Admin/CampaignsPage.php               — thin mount + hidden fields / localized bootstrap
src/Admin/AdminActions.php                — render() before persist
tests/Unit/Application/EmailHtmlRenderer_Test.php
tests/Integration/Admin/CampaignBlockEditor_Test.php
```

## Tasks
- [x] Spec accepted (this file + overview pointer) — revised for post-editor chrome
- [x] Failing/updated tests (post-editor root; no textarea body_html; EmailHtmlRenderer; save inlined; assets; body class; cap/nonce)
- [x] `EmailHtmlRenderer` (keep; adjust editor CSS away from letter-card primacy)
- [x] Rewrite `CampaignsPage::render_edit` to thin post-editor host
- [x] Expand JS: InterfaceSkeleton / FullscreenMode / inserter / list view / document sidebar / title
- [x] CSS + enqueue `wp-editor` / `wp-edit-post` + `admin_body_class`
- [x] Wire save path through `EmailHtmlRenderer::render`
- [x] Review 🔴/🟡 fixes
- [x] Verify (phpstan, phpunit, phpcs); smoke :8080 if possible

## Tests (TDD)
| Scenario | Test location |
|----------|---------------|
| Wrap + inline / idempotent / unwrap | `tests/Unit/Application/EmailHtmlRenderer_Test.php` |
| App root; no textarea body_html; title/subject field | `tests/Integration/Admin/CampaignBlockEditor_Test.php` |
| Body class block-editor-page on edit | same |
| Save persists inlined shell; body_text autofill | same |
| Assets only on edit; cap/nonce | same |

## Risk notes
- Do not change BroadcastSender / queue send path.
- Prefer table fragment over full HTML document for `wp_kses_post`.
- Do not enqueue block-editor assets globally.
- Keep Kit-imported HTML openable via unwrap + rawHandler / freeform.
- Avoid private `unlock()` APIs when public `wp.editor` / `wp.blockEditor` exports suffice.
