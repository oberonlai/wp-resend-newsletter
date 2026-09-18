# Analytics: Tags column on engagement tables (v1.2)

Campaign Analytics **Opened by** and each per-link **Clicked** table show a **Tags** column between **Email** and **Events**, matching the subscribers list tag display.

**Plugin version:** `0.3.7`

## Why

Admins reviewing openers/clickers cannot see existing tags without leaving the page. The screenshot marks the missing column between Email and Events.

## Scope

| In | Out |
|----|-----|
| Tags column on openers + per-link clicker tables | Changing bulk-tag forms / actions |
| Batch-load via `SubscriberTagRepository::find_tags_for_subscribers` | New repository methods |
| Display like `SubscribersListTable::column_tags` | Kinsta deploy |
| Integration test asserting tag names in analytics HTML | Theme / frontend changes |

## Area index

| # | Spec | Risk |
|---|------|------|
| 01 | [01-analytics-tags-column.md](./01-analytics-tags-column.md) | 🟢 Green — read-only display; reuses existing batch query + escaping pattern |

## Build order

1. Spec + failing integration test (subscriber with tags appears in analytics HTML)
2. Collect IDs → batch-load tags → pass map into `render_engagement_table`
3. Header + cell rendering; empty → `—`
4. Version `0.3.7` + verify + zip

## References

- Auto-loaded: `@everything-wp/skills/wp-backend`
- Existing: `CampaignAnalyticsPage`, `SubscribersListTable::column_tags`, `SubscriberTagRepository::find_tags_for_subscribers`
