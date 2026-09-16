# Email Brand Template (v1.1 area 06)

**Risk Tier**: 🟢 Green — presentational HTML shell/footer only; no send-path or auth changes; filters for logo/contact override

## Goal
Every campaign email uses a **default brand template** matching **oberonlai.blog** article readability: charcoal text, Chinese-friendly typography, white card on light gray page, **header logo**, and a **footer** with logo + contact + Resend unsubscribe placeholder.

User: E2E test mail received; now match article style; footer must include site logo, contact, unsubscribe; apply as default for every campaign going forward.

## Relationship to 05
Builds on [`05-full-page-email-editor.md`](./05-full-page-email-editor.md). Same `EmailHtmlRenderer::render()` / `unwrap()` contract; enrich tokens + shell (header/footer). Editor stays **body-only** (footer appended on save/send only).

## Brand facts (locked)
| Token | Value |
|-------|--------|
| Site | WP 開發日常 — https://oberonlai.blog/ |
| Brand / text | `#293132` |
| Page background | `#f6f6f6` |
| Card | `#ffffff` |
| Content width | ~640px |
| Body type | ~16–18px, line-height ~1.7 |
| Font stack | Noto Sans TC / PingFang TC / Microsoft JhengHei / Arial / Helvetica / sans-serif |
| Link | Brand-adjacent dark teal `#3a5f66` (site yellow accent is not link-safe) |
| Logo | Bundled PNG `src/Assets/logo.png` (rasterized from site SVG; email clients break SVG) |
| Unsubscribe | `{{{RESEND_UNSUBSCRIBE_URL}}}` |
| Contact default | `get_bloginfo('name')` + `home_url('/')` + `get_option('admin_email')`; override via `wprn_email_footer_contact` |

## Decisions (locked)
| Topic | Decision |
|-------|----------|
| Where | Extend `EmailHtmlRenderer` (tokens + `wrap_shell`); no BroadcastSender change |
| Apply when | Existing save path: `AdminActions` → `EmailHtmlRenderer::render()` |
| Editor | `unwrap()` returns **body cell only** — header/footer not duplicated in editor |
| Idempotent | `render()` unwraps shell first; one shell; footer always rebuilt |
| Logo URL | Bundled plugin PNG; filter `wprn_email_logo_url` |
| Contact | Dynamic WP blog info; filter `wprn_email_footer_contact` (HTML-safe lines array or prebuilt HTML string — use structured array of lines) |
| Unsub | Footer always includes placeholder link; Chinese label 取消訂閱 |
| Out of scope | Blank editor fix; Kinsta deploy; secrets |

## User stories

**As a** newsletter admin  
**I want** saved campaign HTML wrapped in the brand shell  
**So that** recipients see logo, article-like type, contact, and unsubscribe

**Scenario**: Brand wrap includes logo, contact, unsub  
  **Given** inner HTML `<p>Hello</p>`  
  **When** `EmailHtmlRenderer::render()` runs  
  **Then** output has `wprn-email-shell`, header logo `<img>`, footer contact strings, and `{{{RESEND_UNSUBSCRIBE_URL}}}`  
  **And** brand color `#293132` appears in tokens / inline styles  

**Scenario**: No double-wrap  
  **Given** already-templated HTML  
  **When** `render()` runs again  
  **Then** exactly one `wprn-email-shell`  

**Scenario**: Unwrap for editor  
  **Given** templated HTML with header + body + footer  
  **When** `unwrap()` runs  
  **Then** result has no shell/header/footer classes; body content remains  

## File layout
```
spec/v1.1/06-email-brand-template.md
src/Assets/logo.png
src/Assets/logo-source.svg   (source; email uses PNG)
src/Application/EmailHtmlRenderer.php   — tokens + header/footer shell
tests/Unit/Application/EmailHtmlRenderer_Test.php
tests/bootstrap-unit.php                — stubs for filters / bloginfo / home_url
```

## Tasks
- [x] Spec (this file + overview pointer)
- [x] Failing unit tests (logo, contact, unsub, no double-wrap, unwrap, brand tokens)
- [x] Bundled logo PNG
- [x] Enhance EmailHtmlRenderer
- [x] Unit bootstrap stubs
- [x] Review touch + verify touched tests

## Tests (TDD)
| Scenario | Location |
|----------|----------|
| Logo + contact + unsub in render | `EmailHtmlRenderer_Test` |
| Idempotent / unwrap strips footer | same |
| Tokens brand color / width / line-height | same |
