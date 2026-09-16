# Public Campaign Archive (v1.2)

Expose sent newsletters on a public WordPress page: paginated list + single-campaign HTML view.

**Plugin version:** stays at `0.1.3` (classic-editor bump already landed).

## Why

Production page `/wordpress-newsletter/` (ID 4011) currently uses ConvertKit form + `[convertkit_broadcasts]`. Replace with this plugin’s subscribe block + `[wprn_archive]` so visitors browse past campaigns stored in `{prefix}wprn_campaigns`.

## Scope

| In | Out |
|----|-----|
| Shortcode `[wprn_archive]` | Archive Gutenberg block (optional later) |
| Public view `/?wprn_campaign={id}` | Draft/ready/sending campaigns in archive |
| `CampaignRepository::find_sent` / `count_sent` | Kinsta deploy / CloudAgent |
| Page markup file under `.import/` | Touching CampaignsPage / classic editor |

## Area index

| # | Spec | Risk |
|---|------|------|
| 01 | [01-archive-shortcode-and-view.md](./01-archive-shortcode-and-view.md) | 🟡 Yellow — public HTML; only `sent`; sanitize body |

## Build order

1. Spec + failing tests
2. Repository `find_sent` / `count_sent`
3. Shortcode + CampaignViewPage
4. Bootstrap register
5. Page markup for ID 4011
6. PHPUnit
