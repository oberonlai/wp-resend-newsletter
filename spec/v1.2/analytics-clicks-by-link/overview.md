# Analytics: clicks grouped by link URL (v1.2)

Campaign Analytics shows **who clicked which specific link**, so admins can bulk-tag only those clickers (e.g. “GitHub clickers only”).

**Plugin version:** `0.3.6`

## Why

Today “Clicked by” is one row per subscriber with **all** their clicked URLs aggregated. Admins cannot select or tag only people who clicked a single URL.

## Scope

| In | Out |
|----|-----|
| Per-link clicker sections on Campaign Analytics | Changing open tracking / webhook ingest |
| `clicks_by_link` on `CampaignAnalyticsService::summarize()` | Passing `link_url` to the bulk-tag action |
| Optional `link_url` filter on `find_unique_subscribers_for_campaign_event` | Kinsta deploy |
| Reuse `wprn_analytics_bulk_add_tag` with selected `subscriber_ids` | Theme changes |
| zh_TW for new user-visible strings | Kit historical per-subscriber clicks (still aggregate-only) |

## Area index

| # | Spec | Risk |
|---|------|------|
| 01 | [01-repo-and-summarize.md](./01-repo-and-summarize.md) | 🟡 Yellow — SQL filter + grouped query; capability-gated admin UI |
| 02 | [02-analytics-ui-per-link.md](./02-analytics-ui-per-link.md) | 🟡 Yellow — admin markup + bulk tag reuse; nonce/cap already on action |

## Build order

1. Spec + failing tests (A clicks link1+link2; B clicks only link1)
2. Repository: optional `link_url` filter + `find_clickers_grouped_by_link`
3. `CampaignAnalyticsService::summarize()` → `clicks_by_link`
4. Replace aggregate “Clicked by” UI with per-link sections; top links → anchors
5. zh_TW + version `0.3.6` + verify + zip

## References

- Auto-loaded: `@everything-wp/skills/wp-backend`
- Existing: `DeliveryEventRepository`, `CampaignAnalyticsService`, `CampaignAnalyticsPage`, `AdminActions::handle_analytics_bulk_add_tag`
