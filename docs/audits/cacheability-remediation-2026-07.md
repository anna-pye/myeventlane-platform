# Cacheability remediation — 12 July 2026

## Summary

Homepage (`/`) and `/events` returned `X-Drupal-Dynamic-Cache: UNCACHEABLE (poor cacheability)` for anonymous requests.

Root cause was **not** missing `Cache-Control` headers. Drupal Dynamic Page Cache refuses to store responses whose cacheability metadata matches renderer **auto-placeholdering** conditions — notably bare `user` / `session` contexts or `max-age: 0` on the **page root**.

`SurfaceNegotiator::attachPageMetadata()` merged governance payload cache metadata and then **forced** the `user` context onto every page. That bubbled to the response, so Dynamic Page Cache marked public pages uncacheable even when personalised chrome was already lazy-built (Commerce cart, Flag links).

Secondary contributors (acceptable after the negotiator fix, or still placeholder-dependent):

- Views exposed filter forms (`CACHE_MISS_IF_UNCACHEABLE_HTTP_METHOD:form`) — should auto-placeholder
- Commerce `cart` cache context from site-header cart lazy builder — not in auto-placeholder conditions; OK for DPC
- `system.performance.cache.page.max_age: 0` in committed sync (development value) — fixed to `900` for production defaults; local override keeps `0`

## Route findings

| Route | Anonymous (before) | Anonymous (after) | Authenticated | Root cause | Fix | Files | Remaining limits |
|-------|--------------------|-------------------|---------------|------------|-----|-------|------------------|
| `/` | DPC poor cacheability (`user` on page root) | DPC eligible (MISS); no longer forced `user` | Varies by `user.roles:authenticated`; bell/cart placeholdered | Forced `user` on SurfaceNegotiator + site_header + event cards | Remap to `user.roles:authenticated`; flag lazy builder only | `SurfaceNegotiator.php`, `myeventlane_theme.theme`, `EventCardViewModel.php` | Discover exposed form tokens; local page max_age 0 |
| `/events` | Same | Improved contexts (no bare `user`); may still show poor cacheability from exposed form max-age | Same | Same + Views exposed filters | Same shell fixes; form lazy-builder follow-up | Same | Exposed filters |
| `/node/{event}` | Poor due to `user` | Improved | Placeholders for flags | site_header + cards | Same | Same | — |
| Vendor/customer | User-varied | Unchanged | DPC may skip | Legitimate personalisation | None | — | Acceptable |

## Preferred Drupal-native approach used

- Correct cache **contexts** on the public page shell (`user.roles:authenticated`, not bare `user`)
- Leave personalised fragments on existing **lazy builders** / placeholders (cart, flags, notification bell when authenticated)
- Do **not** force synthetic cache headers
- Do **not** strip invalidation tags from event nodes / stores

## Remaining acceptable limitations

- Views exposed filter forms on `/events` (and homepage discover) still contribute form cache tags / token metadata. Fully placeholdering those forms needs a proper `#lazy_builder` (not bare `#create_placeholder`). Follow-up: lazy-build discovery filters or enable BigPipe after a dedicated spike.
- Commerce cart remains a placeholder with `cart` context — correct for basket isolation.
- Vendor/Customer/Staff surfaces intentionally retain bare `user` context.
- Local DDEV overrides page `max_age` to `0` for DX; production sync uses `900`.
