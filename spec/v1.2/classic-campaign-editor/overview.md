# Classic Campaign Editor (v1.2)

Replace the Gutenberg campaign compose UI with WordPress classic `wp_editor` so admins can paste HTML from notes apps via the Text tab.

**Plugin version target:** `0.1.3`

## Why

The block editor mount (`#wprn-campaign-block-editor`) was unreliable for paste-HTML workflows. Classic TinyMCE + Quicktags (Visual/Text) is the familiar WP admin pattern and posts the same `body_html` field to `AdminActions::SAVE_CAMPAIGN_ACTION`.

## Scope

| In | Out |
|----|-----|
| Classic edit form on `wprn-campaign-edit` | Analytics / tags / send pipeline changes |
| Stop enqueueing CampaignBlockEditor JS/CSS | CloudAgent / Kinsta deploy |
| Keep `EmailHtmlRenderer::unwrap()` on load | Brand template on send (unchanged) |
| Keep Queue send when `status=ready` | New Composer / block packages |

## Area index

| # | Spec | Risk |
|---|------|------|
| 01 | [01-classic-editor-ui.md](./01-classic-editor-ui.md) | 🟡 Yellow — admin UI swap; same save path |

## Build order

1. Spec + failing tests (classic UI assertions)
2. Rewrite `CampaignsPage::render_edit`
3. Unregister / remove `CampaignBlockEditor` assets
4. Bump version to `0.1.3`
5. PHPUnit (affected admin tests) + review/verify
