# Public vs Vendor Boundary Audit

**Brand rollout:** The Hidden Gem + The Guide (Bright Edition)
**Audit date:** 2026-06-14
**Method:** Evidence-based. Source: all `web/modules/custom/*/*.routing.yml` (458 custom route paths) + theme negotiation (`theme-architecture.md`).

> **This is the critical boundary for the rollout.** The Guide must live in **PUBLIC DISCOVERY**, not in operational vendor workflows or admin.

---

## 1. Route totals (evidence)

| Class | Count (path-prefix) |
|---|---|
| Total custom route paths | **458** |
| `/admin/*` | 102 |
| `/vendor/*` | 215 |
| `/api/*`, `/auth/*` | 15 |
| Public / account / system (remainder) | ~126 |

The boundary is **also enforced at the theme layer** by `VendorThemeNegotiator` (domain-based) + `AdminThemeNegotiator` — see `theme-architecture.md §2`. Public vendor profile routes (`entity.myeventlane_vendor.canonical`, `myeventlane_vendor.public_list`, `myeventlane_vendor.organisers`) are **explicitly forced back to the public theme**.

---

## 2. Surface classification

### 🟢 PUBLIC DISCOVERY — *The Guide's primary home* (marketplace theme, anonymous-facing)

| Surface | Routes (evidence) |
|---|---|
| Homepage | `/home` (front) |
| Browse | `/events`, `/events/category/%`, `/events/free`, `/events/popular`, `/events/this-weekend`, `/events/today` |
| Search | `/search`, `/search/autocomplete` |
| Event detail | `/event/{node}` (canonical), `/event/{node}/review`, `/event/{node}/calendar.ics`, `/event/{node}/ics` |
| Calendar | `/calendar` |
| Help Centre | `/help`, `/help/index`, `/help/attendees`, `/help/organisers`, `/help/vendors`, `/help/policies`, `/help/category/{category}`, `/help/search`, `/help/ask`, `/help/assistant` |
| Blog / editorial | `/blog`, `/resources` |
| **Public organiser profiles** | `/organisers`, `/vendor/{myeventlane_vendor}`, `/vendor/{myeventlane_vendor}/follow` *(forced public theme)* |
| Venues | `/venues/{myeventlane_venue}` |
| Trust / legal | `/trust`, `/refund-policy`, `/cookies`, `/cookies/preferences`, `/sitemap`, `/sitemap.xml` |
| Discovery AJAX | `/mel/filter-events`, `/mel/event-suggestions`, `/my-categories` (follow) |

### 🟡 PUBLIC ACCOUNT / TRANSACTIONAL — *Guide secondary / light touch* (marketplace theme, authenticated attendee)

| Surface | Routes |
|---|---|
| Account hub | `/my-account`, `/my-profile/*`, `/my-settings`, `/my-settings/{user}` |
| Personal discovery | `/my-events`, `/my-past-events`, `/my-categories`, `/my-organisers` |
| Saved / wallet | `/my-tickets`, `/wallet/apple/{…}`, `/wallet/google/{…}`, `/ticket/*` |
| RSVP / booking | `/event/{node}/book`, `/event/{event}/rsvp`, `/event/{event}/rsvp/thank-you`, `/rsvp/{rsvp_id}/cancel`, waitlist routes |
| Cart / checkout | `/cart/attendee-info/{order_item}` (+ Commerce cart/checkout) |
| Attendee onboarding | `/onboard/account`, `/onboard/explore`, `/onboard/first-action`, `/onboard/my-tickets`, `/mel/post-login` |
| Notifications | `/my-notifications`, `/my-notifications/settings`, `/myeventlane/notifications/*` |
| Support (attendee) | `/my/support`, `/my/support/escalations*`, `/support`, `/support/tickets` |

> The Guide appears here as **personalised discovery** (recommendations, "your gems") — see `onboarding-audit.md` (post-login hub). It must **not** turn account/transactional flows into marketing.

### 🟠 VENDOR WORKSPACE — *operational; Guide ABSENT / minimal* (vendor theme, vendor domain)

| Area | Routes (sample of 215) |
|---|---|
| Console | `/vendor`, `/vendor/dashboard`, `/dashboard`, `/create-event` |
| Event ops | `/vendor/event/{event}/edit`, `/advanced`, `/content`, `/design`, `/payments`, `/promote`, `/comms`, `/checkout-questions`, `/rsvps`, Event Studio |
| Analytics | `/vendor/analytics/*`, `/vendor/charts/*` |
| Attendees / check-in | `/vendor/attendees`, `/vendor/attendee/{…}/checkin`, `/vendor/check-in/*`, `/event/{event}/tickets/checkin/*` |
| Billing / boost / donations | `/vendor/billing/*`, `/vendor/boost`, `/vendor/donations`, `/vendor/donate/*` |
| Vendor onboarding | `/vendor/onboard`, `/account`, `/profile`, `/stripe`, `/branding`, `/first-event` |
| Audience / comms | `/vendor/audience`, `/vendor/dashboard/messaging/brand` |

> **Do not deploy the discovery Guide persona here.** The vendor theme has its own token system (cool grey/blue). At most, a *light, operational* assistant tone (already present via help assistant) — never the "what wonderful thing can I discover?" voice.

### 🔵 ADMIN — *back-office; Guide ABSENT* (Gin)

102 `/admin/*` routes (help insights, AI guardrails, messaging settings, reports, etc.). Out of brand scope.

### ⚙️ SYSTEM / INTEGRATION — *no brand surface*

`/stripe/*`, `/webhooks/postmark/*`, `/stripe/webhook/*`, `/api/*`, `/auth/*`, `/health`, `/ai/job/{ai_job}`, service workers (`sw.js`), manifests.

---

## 3. Boundary integrity findings

| Finding | Evidence | Implication |
|---|---|---|
| Boundary enforced at **theme negotiation**, not just URL | `VendorThemeNegotiator` (domain) + forced public-profile list | Clean, reliable place to scope brand themes |
| Public organiser profile lives under `/vendor/*` but renders **public theme** | `PUBLIC_VENDOR_ROUTES` | Profile pages ARE discovery surfaces — Guide-eligible despite `/vendor` prefix |
| Check-in routes appear on public domain but are **organiser-operational** | `/event/{event}/tickets/checkin/*` | Treat as operational, not discovery |
| `/create-event`, `/dashboard` are vendor-operational despite no `/vendor` prefix | `myeventlane_vendor.routing.yml`, `myeventlane_views.routing.yml` | Classify by *function*, not prefix |

---

## 4. Guide placement decision matrix

| Surface class | Guide presence | Voice |
|---|---|---|
| 🟢 Public discovery | **Primary** | Full discovery persona ("what can I discover?") |
| 🟡 Public account/transactional | **Secondary** | Personalised discovery, gentle ("your gems", "saved for you") |
| 🟠 Vendor workspace | **Minimal/none** | Operational helper tone only (existing assistant) |
| 🔵 Admin | **None** | — |
| ⚙️ System | **None** | — |

---

## 5. Verdicts

| Verdict | Item |
|---|---|
| **GUIDE LIVES HERE** | All 🟢 public discovery routes + public organiser profiles + 🟡 personalised account discovery |
| **DON'T TOUCH (no Guide persona)** | 🟠 vendor workspace (215 routes), 🔵 admin (102), ⚙️ system/integration |
| **REUSE** | Domain-based theme negotiation as the enforcement boundary |
| **WATCH** | Function-not-prefix surfaces (`/create-event`, `/dashboard`, check-in) — operational despite public-looking URLs |

**Bottom line:** The public/operational boundary is **already cleanly enforced by domain-based theme negotiation**. The Guide's territory = public discovery + public organiser profiles + personalised account discovery. The 215 vendor + 102 admin routes are operational and **out of scope** for the discovery brand. This is the lowest-risk possible boundary because the codebase already separates the two at the theme layer.
