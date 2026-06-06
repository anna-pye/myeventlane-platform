# Boost listing placement migration (update 10008)

## Context

The site front page (`/home`, route `view.frontpage.page_1`) was incorrectly
attributed as bare placement `listing` before `BoostPlacementResolver` mapped
`view.frontpage.*` to `homepage_discover`.

Client-side impression tracking shipped **2026-06-05 09:03:07 UTC**
(commit `6dd122b9e`).

## Update hook

`myeventlane_boost_update_10008()` in `myeventlane_boost.install`:

- Targets **bare** `placement = 'listing'` only (not `listing_*`).
- Scopes aggregate rows to `created >= 1780650187` and daily rows to
  `date >= 2026-06-05`.
- Merges into an existing `homepage_discover` row on the same
  `boost_order_item_id` when present (sums impressions/clicks; preserves earliest
  `created`, latest `changed`).
- Renames to `homepage_discover` when no matching aggregate row exists.
- Updates `myeventlane_boost_stats_log` placement in the same window.
- Idempotent: a second `drush updb` run finds no rows to migrate.

## Validation

```bash
ddev drush updb -y
ddev drush cr
```

```sql
SELECT
  event_id,
  placement,
  impressions,
  clicks
FROM myeventlane_boost_stats
WHERE event_id = 1591;
```

Expected after migration (local test case):

| event_id | placement         | impressions | clicks |
|----------|-------------------|-------------|--------|
| 1591     | homepage_discover | 3           | 0      |

No `listing` row for event 1591.

Confirm no bare listing rows remain in scope:

```sql
SELECT COUNT(*) FROM myeventlane_boost_stats WHERE placement = 'listing';
SELECT COUNT(*) FROM myeventlane_boost_stats_log WHERE placement = 'listing';
```

## Residual risk

Bare `listing` on routes other than the homepage (e.g. event canonical pages)
is a valid fallback placement. This migration only rewrites bare `listing` rows
inside the known bug window. Environments with legitimate bare `listing` traffic
in that window could be mis-credited to homepage — run the validation queries on
staging before production deploy.
