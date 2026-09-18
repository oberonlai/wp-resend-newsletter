# Email accent border: header only (v1.2)

Yellow brand accent (`accent_color` / `#FFDC73`) must appear **only beside the header (title) block**, not the full card through body + footer.

**Plugin version:** `0.3.8`

## Why

`EmailHtmlRenderer::wrap_shell()` currently sets `border-left:3px solid {accent}` on `.wprn-email-card`, so the yellow line runs past the body into the footer. Desired look: accent line spans just 「WordPress 開發週報」 / By Oberon Lai.

## Scope

| In | Out |
|----|-----|
| Move `border-left` from card table → header `<td>` | Changing title/subtitle copy |
| Unit tests asserting header-only accent | Editor canvas CSS redesign |
| Version `0.3.8` + zip + GitHub release | Kinsta deploy (parent) |
| | Blockquote left border (content style, unrelated) |

## Area index

| # | Spec | Risk |
|---|------|------|
| 01 | [01-header-accent-border.md](./01-header-accent-border.md) | 🟢 Green — presentational HTML only; no send/auth/DB |

## Build order

1. Spec + failing unit test (accent on header; absent on card)
2. Remove card `border-left`; add header `border-left`
3. Version `0.3.8` + verify + zip + GitHub release `v0.3.8`

## References

- Auto-loaded: `@everything-wp/skills/wp-backend`
- Existing: `EmailHtmlRenderer::wrap_shell`, `render_header_row`, `tokens()['accent_color']`
- Prior brand work: `spec/v1.1/06-email-brand-template.md`, commit `244d45e` (v0.3.3 text header + yellow left accent)
