# Platform governance audit

Companion to **`docs/platform-canonical-surface-map.md`**. This document captures governance decisions, inventories, risks, and recommended follow-ups from the consolidation pass (`feature/platform-consolidation-audit`).

---

## Canonical terminology

Public-facing consolidation targets are listed in **Section A** of the surface map. Governance rule: **new user-visible strings** should prefer **Organiser**, **Organiser Dashboard**, **Ticket holder** (except established Help paths titled “Attendees”), **Support request** for customer-facing escalation language, **Event Manager** / **Event Editor**, and **Promote Event** for marketing surfaces—not internal Boost SKU naming.

---

## Canonical route ownership

**Authoritative tables:** `docs/platform-canonical-surface-map.md` Section B.

**Governance rule:** Prefer **`/vendor/dashboard`**, **`/vendor/events/{event}/workspace`**, and **Event Editor** routes for organiser UX; treat **`/vendor/event/{event}/*`** manage-event steps as **legacy/secondary** except where they remain the only implementation.

---

## Deprecated surface inventory

| Surface | Behaviour | Notes |
|---------|-----------|-------|
| `/help/index` | 301 → `/help` | Public index consolidation |
| `/vendor/help` | 301 → `/help` | Legacy vendor entry |
| `/stripe/connect/callback` | Stripe callback | Parallel to `/stripe/callback` for in-flight Account Links |
| Manage-event singular paths | Some redirect to workspace/studio | See vendor routing (e.g. tickets redirect controller) |

---

## Placeholder surface inventory

All **`ManageEventPlaceholderController`** routes: **`promote`**, **`payments`**, **`comms`**, **`advanced`** under **`/vendor/event/{event}/…`**.

**Controls:** `noindex,nofollow`, `X-Robots-Tag`, themed shell classes on `myeventlane_manage_event`.

---

## AI grounding rules

1. **Bundles:** `HelpRetriever` queries **`help_article`** only on index **`mel_content`**.
2. **Published + status:** Published nodes; `field_help_status` must be `published` or `approved` when present.
3. **Allow list:** `field_help_ai_allowed` must be truthy.
4. **Audience:** No `staff`; only **`public`** / **`vendor`** consistent with current user.
5. **Node access:** `$node->access('view')` required.
6. **Unified path:** `UnifiedHelpRetriever` repeats policy validation—preserve defence in depth.

**Explicit non-goals:** Staff playbooks and internal-only nodes must never enter vendor/public retrieval pipelines.

---

## Help Centre governance

- **Public hub:** `/help` (`access content` for home; several sub-paths `_access: 'TRUE'` for listings—ensure content access still enforced inside views/controllers).
- **Vendor topics:** `/vendor/help/topic/{category}` requires **`view vendor help centre`** — now granted to **`vendor`** and **`mel_pro`** roles in sync.
- **Contextual map:** `config/sync/myeventlane_help_centre.contextual_help.yml` drives structured links; resolver uses register IDs / titles (`ContextualHelpResolver`).
- **Staff authoring:** Separate admin paths (`administer escalations`, `view mel help insights`) — keep segregated from vendor menus.

---

## Customer journey map

See surface map **Section E (Customer)**. **Gap:** customer IA is assembled from **multiple menu plugins and theme regions**; a future task should generate a single **`links.menu.yml` tree** or dashboard builder output aligned to the canonical list without duplicating builders.

---

## Organiser journey map

See surface map **Section E (Organiser)**. **Gap:** reconcile **Boost**, **Promote placeholder**, and **Event Studio** naming in shell chrome (`VendorConsolePagePreprocess`, dashboard templates).

---

## Security & access findings

| Area | Assessment | Detail |
|------|------------|--------|
| Manage-event placeholders | **Safe** | Inherits `ManageEventControllerBase::access` (owner/vendor team/admin) |
| `/vendor/help` redirect | **Safe** | Broad `access content`; redirect only |
| `/vendor/help/topic/*` | **Safe post-fix** | Permission now on organiser roles |
| Stripe `_access: TRUE` callbacks | **Needs operational discipline** | Expected for webhooks/returns; must retain signature/idempotency elsewhere |
| Site search `mel_content` | **Safe** | Content access processor on index; `/search` filters non-event bundles |
| Vendor AI `HelpArticleRetriever` | **Risk: note** | Uses `accessCheck(FALSE)` then loads nodes—ensure callers never expose raw output without equivalent checks |

**Governance:** Any consolidation of access services must preserve **`VendorConsoleAccess`** onboarding/domain behaviour.

---

## Recommended future consolidations

1. **Terminology pass:** Update `_title` defaults and prominent Twig headings (`Vendor dashboard` → Organiser Dashboard) with content/design sign-off.
2. **Customer menu:** Single builder or ordered plugin list matching canonical hierarchy.
3. **FAQ in Search API:** Only if product wants FAQs on **`/search`**; requires bundle fields, view modes, and grouping rules.
4. **Access audit sprint:** Inventory every `_access: 'TRUE'` route into staff/public/vendor buckets (spreadsheet), no code change required first.

---

## Launch risks

- **Placeholder URLs** are reachable from manage-event navigation—users may perceive incomplete product.
- **Dual naming** (Vendor vs Organiser) persists in role labels and routes—support and AI agents must rely on this governance doc until copy alignment ships.

---

## Operational risks

- **Search reindex** not required for permission-only config change; if `mel_content` changes later, schedule **`search-api-reindex`**.
- **Caches:** Route/service changes require **`drush cr`**.

---

## AI support risks

- **Terminology drift** increases hallucinated navigation (“Vendor Dashboard” vs `/vendor/dashboard` vs “Organiser”).
- **Misrouting** if staff-only paths are ever added to public contextual maps—guard with audience checks (current resolver uses published `help_article` + access).

---

## Changes applied in this branch (implementation ledger)

| Change | Location |
|--------|----------|
| Placeholder `noindex` meta + shell TWIG classes | `ManageEventPlaceholderController`, `myeventlane-manage-event.html.twig`, `myeventlane_vendor.module` theme vars |
| `X-Robots-Tag` | `ManageEventPlaceholderNoIndexSubscriber` |
| Organiser help permission | `config/sync/user.role.vendor.yml`, `config/sync/user.role.mel_pro.yml` |
| Routing commentary | `myeventlane_vendor.routing.yml`, `myeventlane_help_centre.routing.yml` |
