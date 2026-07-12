# Role and permission matrix

**Date:** 12 July 2026  
**Source:** Active DDEV roles + `config/sync/user.role.*.yml` after remediation.

## Access model layers

| Layer | Meaning |
|-------|---------|
| Permission-based | Drupal role permissions (`user.role.*.yml`) |
| Ownership-based | Entity access / custom checks (own event, own order, vendor store) |
| Administrative override | `administrator` (`is_admin: true`) or staff permissions such as `administer nodes` |
| Route-level | `_permission`, `_role`, `_custom_access`, `_entity_access` in routing YAML |
| Entity-level | Access handlers, `hook_node_access`, Commerce order access |

There is **no** separate `customer` role. Customers are **authenticated** users. Organisers use **vendor** (+ optional **mel_pro**).

## Roles

| Role | Admin? | Purpose |
|------|--------|---------|
| anonymous | No | Public discovery, checkout entry, donations |
| authenticated | No | Account, own orders/tickets, flags, notifications |
| vendor | No | Event Studio, attendees, vendor console |
| mel_pro | No | Pro analytics / extras on top of vendor |
| content_editor | No | Editorial content (articles/pages) |
| administrator | Yes | Full bypass |

## Change made in this remediation

| Permission | anonymous | authenticated | vendor | Reason |
|------------|-----------|---------------|--------|--------|
| `access commerce_order overview` | **Removed** | **Removed** | Retained | Anonymous (and plain authenticated) could open `/admin/commerce/orders` and see order rows. Views access is permission-only with no uid filter. Vendors keep it for admin commerce tooling; customers use `view own commerce_order`. |

## Permission vs ownership (high-risk items)

| Permission / capability | Roles | Enforcement | Notes |
|-------------------------|-------|-------------|-------|
| `access commerce_order overview` | vendor, administrator | Route/view permission | **Must not** be on anonymous/authenticated |
| `view own commerce_order` | authenticated | Ownership | Customer order history |
| `create/edit/delete own event content` | vendor | Node ownership + Event Studio access | Do not grant edit any event |
| `manage own event attendees` / `view own event attendees` | vendor | Ownership-based services | |
| `delete/update default commerce_order` | vendor | Must remain ownership-scoped in Commerce/MEL access | Do not treat as global admin |
| `view user email addresses` | vendor | Attendee tooling — verify store/event scope in code | Residual medium risk; not changed here |
| `view any profile` / `access user profiles` | vendor | Profile module | Residual — product decision if still required |
| `administer myeventlane donations` / `messaging` | vendor | Confirm vendor-scoped controllers | Residual |
| `unlock orders` | vendor | Residual abuse risk | Documented; not removed without product decision |
| `view unpublished paragraphs` | anonymous, authenticated, vendor, mel_pro | Paragraph access | Residual medium; needed for some public paragraph embeds — leave pending product review |
| `access administration pages` | content_editor | Staff editorial | Expected |
| `bypass node access` / `administer *` | administrator only | Admin override | Expected |

## Route-level notes

Public `_access: TRUE` webhook/OAuth routes were reviewed in `docs/audits/public-route-access-review.md` (signature/token checks). No change in this task.

## Tests

- `MelCommerceOrderOverviewRoleConfigTest` — asserts committed YAML omits the overview permission from anonymous/authenticated and retains it on vendor.
- Smoke: anonymous `GET /admin/commerce/orders` must be 403 after config import.

## Intentionally unresolved (product decision)

- Whether authenticated users need any remaining Commerce admin permissions for edge flows
- Vendor `view user email addresses` / `view any profile` narrowing
- Anonymous `view unpublished paragraphs`
- Whether `access commerce_order overview` should move to a staff-only role instead of vendor
