# Campaign public web sanitize (hotfix)

## Problem
Kit-imported campaign HTML includes `<style>` (email mobile CSS). `wp_kses_post()` strips the tags but leaves CSS as visible text. Rules like `width: 414px !important` also force horizontal overflow on phones.

## Goal
Public campaign view (`/newsletter/{id}/`) shows article HTML only: no leaked CSS/JS, no forced email widths breaking mobile.

## Areas
01 — `EmailHtmlRenderer::for_web()` strips style/script (+ orphan CSS text), used by `CampaignViewPage` before `wp_kses_post`
02 — `campaign-view.css` constrains tables/images/`overflow-x` for mobile

## Risk
Low (display-only). Email send path unchanged.

## Related (v1.2 confirm)
Confirm page theme chrome + branded transactional confirm email: see `../confirm-theme-shell/overview.md`.
