# Confirm page theme shell + branded confirm email

## Goal
1. Public confirm page (`/?wprn_confirm={token}`) uses the active theme `get_header()` / `get_footer()` (same pattern as campaign view), with scoped `confirm.css`.
2. Double opt-in confirm **email** uses `EmailHtmlRenderer::render( $inner, false )` so recipients see the brand shell (logo, card, contact) without the Resend Broadcast `{{{RESEND_UNSUBSCRIBE_URL}}}` placeholder (that token only works on Broadcasts, not transactional `send_batch`).

## Areas
01 — `ConfirmPage::build_content_html` + `render_html` theme chrome; styles in `src/Frontend/css/confirm.css`
02 — `EmailHtmlRenderer::render` / `wrap_shell` optional `$include_unsubscribe` (default `true` for campaigns)
03 — `SubscribeService::send_confirm_email` wraps inner HTML with brand template, `$include_unsubscribe = false`

## Out of scope
- REST `/subscribers/confirm` remains JSON.
- Confirm mail stays on `send_batch` (never Broadcasts).
