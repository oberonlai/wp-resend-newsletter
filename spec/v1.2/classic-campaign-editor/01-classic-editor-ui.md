# 01 — Classic campaign editor UI

**Risk Tier**: 🟡 Yellow — admin form markup + asset unload; persists via existing `AdminActions::handle_save_campaign`; no send-path change

## Goal

Campaign edit (`admin.php?page=wprn-campaign-edit` / `CampaignsPage::render_edit`) is a normal WP admin wrap form with classic `wp_editor` for `body_html`. No React block mount.

## Decisions (locked)

| Topic | Decision |
|-------|----------|
| Body field | `wp_editor( $body_html, 'wprn_campaign_body_html', … )` with `textarea_name` = `body_html`; Visual + Text tabs (`quicktags` on); media buttons OK |
| Load | `EmailHtmlRenderer::unwrap()` on stored `body_html` before editor (same as block era) |
| Save | Unchanged `AdminActions::SAVE_CAMPAIGN_ACTION` fields: `subject`, `body_html`, `body_text`, `mark_ready`, `filter_tag_ids[]` |
| Brand on send | `EmailHtmlRenderer::render()` on save / send stays unchanged |
| Block editor | Do not register `CampaignBlockEditor`; delete unused JS/CSS/PHP if nothing else needs them |
| Tags | Visible checkboxes `filter_tag_ids[]` (AND filter semantics unchanged) |
| Mark ready | Checkbox when `$can_mark_ready`; Queue send form when `status=ready` |

## User Stories

**As a** newsletter admin  
**I want to** edit campaign HTML with the classic editor  
**So that** I can paste HTML from my notes app via the Text tab

**Scenario**: Classic editor fields render on campaign edit
  **Given** an admin on `admin.php?page=wprn-campaign-edit`
  **When** the edit form renders
  **Then** the page contains `name="subject"` and a textarea/editor with `name="body_html"` (via `wp_editor` / `wprn_campaign_body_html`)
  **And** there is no `#wprn-campaign-block-editor` mount
  **And** there is no “Loading editor…” block chrome

**Scenario**: Block editor assets are not enqueued
  **Given** an admin viewing the campaign edit screen
  **When** scripts/styles are enqueued (or Bootstrap boots admin)
  **Then** `campaign-block-editor.js` / handle `wprn-campaign-block-editor` is not enqueued
  **And** body class `wprn-campaign-block-editor-page` is not applied by the plugin

**Scenario**: Existing HTML (including brand-wrapped) loads unwrapped
  **Given** a draft whose stored `body_html` is brand-wrapped via `EmailHtmlRenderer::render`
  **When** the edit screen loads
  **Then** the editor content exposes the inner HTML (unwrapped), not the outer `wprn-email-shell` wrapper as the editable seed

**Scenario**: Save still persists body_html via existing handler
  **Given** an admin with `manage_options` and a valid save nonce
  **When** they POST `subject` + `body_html` to `AdminActions::handle_save_campaign`
  **Then** the campaign row stores sanitized/wrapped `body_html` as today
  **And** send path remains unchanged

**Scenario**: Author cannot save / missing nonce rejected
  **Given** an author-role user, or an admin POST without a valid nonce
  **When** the save handler runs
  **Then** the request is rejected and nothing is saved

## Development Tasks

### Interface Layer
- [x] Rewrite `CampaignsPage::render_edit` to classic wrap form (`subject`, `wp_editor`, optional `body_text`, tag checkboxes, mark ready, Save)
- [x] Keep Queue send form when status is `ready`
- [x] Remove `CampaignBlockEditor::register()` from `Bootstrap`
- [x] Delete or no-op `CampaignBlockEditor` + unused `js`/`css` assets

### Tests
- [x] Rewrite `CampaignBlockEditor_Test` for classic editor assertions
- [x] Keep save / capability / nonce scenarios green

### Release metadata
- [x] Bump plugin version to `0.1.3` (`wp-resend-newsletter.php` + `Bootstrap::VERSION`)

## Manual Test Script

1. Open Campaigns → Add New — see subject + TinyMCE/textarea, not blank block chrome.
2. Switch to Text tab, paste HTML, Save — row persists; reopen shows content.
3. Open a brand-wrapped campaign — editor shows inner content only.
4. Ready campaign — Queue send button still present.
