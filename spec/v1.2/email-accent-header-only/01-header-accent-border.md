# 01 — Accent border on header only

**Risk Tier**: 🟢 Green — presentational email HTML; no auth, DB, or send-path changes

## Goal

Yellow `border-left: 3px solid {accent_color}` appears only on the brand header cell (title + subtitle), not on the outer card / body / footer.

## Decisions (locked)

| Topic | Decision |
|-------|----------|
| Method | `wrap_shell()` (user alias: wrap_brand_shell) |
| Remove from | `.wprn-email-card` table `style` — drop `border-left:3px solid …` |
| Add to | `render_header_row()` header `<td class="wprn-email-header">` style |
| Accent token | Keep `tokens()['accent_color']` = `#FFDC73` |
| Body / footer | No accent `border-left` |
| Blockquote | Unrelated `border-left:4px` on content quotes — leave alone |
| Version carriers | `wp-resend-newsletter.php` header + `WP_RESEND_NEWSLETTER_VERSION` + `Bootstrap::VERSION` |

## User Stories

**As a** newsletter recipient  
**I want** the yellow accent line only next to the brand title  
**So that** the body and footer are not visually framed by a continuous yellow stripe

**Scenario**: Accent sits on header cell
  **Given** inner HTML `<p>品牌測試</p>`
  **When** `EmailHtmlRenderer::render()` runs
  **Then** the `wprn-email-header` cell style includes `border-left: 3px solid #FFDC73`
  **And** `tokens()['accent_color']` remains `#FFDC73`
  **And** title `WordPress 開發週報` and subtitle `By Oberon Lai` remain present

**Scenario**: Card table has no accent border
  **Given** the same rendered HTML
  **When** inspecting the `.wprn-email-card` opening tag
  **Then** that tag’s `style` does **not** contain `border-left`
  **And** the body cell (`wprn-email-body`) and footer (`wprn-email-footer`) do not carry the accent `border-left: 3px solid #FFDC73`

## Development Tasks

### HTML shell
- [x] Remove `border-left:3px solid {accent}` from `.wprn-email-card` in `wrap_shell()`; drop unused accent sprintf arg on the card
- [x] Add `border-left:3px solid {accent}` to the header `<td>` in `render_header_row()`

### Tests
- [x] Update `EmailHtmlRenderer_Test::test_render_includes_logo_contact_and_unsubscribe` to expect accent on `wprn-email-header`, not `wprn-email-card`
- [x] Assert card / body / footer lack accent `border-left: 3px solid #FFDC73`

### Release
- [x] Bump to `0.3.8` (main file header + constant + `Bootstrap::VERSION`)
- [x] Run everything-wp verify (PHPStan + PHPUnit + coverage + PHPCS)
- [x] Build zip `build/wp-resend-newsletter-v0.3.8.zip`
- [x] Commit + push `main`; create GitHub release `v0.3.8` with zip
- [x] Do **not** deploy to Kinsta
