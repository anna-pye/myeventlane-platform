# Platform canonical surface map

**Branch:** `feature/platform-consolidation-audit`  
**Purpose:** Single reference for terminology, route ownership, placeholders, help/AI boundaries, and navigation mental models.  
**Method:** Inspection of routing YAML, controllers, Search API config, help modules, menu links, and preprocess hooks referenced below—no inferred entities or permissions.

---

## Section A — Canonical terminology

Approved **public-facing** language below is **prescriptive for future copy**; legacy strings are **not** bulk-rewritten in this pass.

| Internal / legacy | Canonical public | Audience | Legacy still appears (examples) |
|-------------------|------------------|----------|--------------------------------|
| Vendor | **Organiser** | Public + authenticated | Route `/vendors`, entity label “Vendor”, role label `Vendor`, admin paths “Vendor settings”, UI “Vendor help”, `myeventlane_vendor.public_list` title “Event Organisers” (mixed model: URL `/vendors` vs title organiser-forward) |
| Vendor Dashboard | **Organiser Dashboard** | Organiser | Route default `_title: 'Vendor dashboard'` (`myeventlane_vendor.console.dashboard`), mixed with `/dashboard` shell |
| Vendor Settings | **Organiser Settings** | Organiser | Admin menu `Organiser settings`; console settings via `myeventlane_vendor.console.settings` (vendor_settings module) |
| Attendee | **Ticket holder** (canonical); **Attendee** retained where aligned with routes/help (“Help for Attendees”, attendee help articles) | Customer | Help routes `/help/attendees`, “Attendee help”, article audience “Attendees”; product language sometimes “attendee” in permissions (`access attendee repository`) |
| Escalation | **Support request** | Customer + organiser + staff | Module/vendor naming `myeventlane_escalations*`, permissions `view vendor escalations`; customer menu “Support” (`myeventlane_escalations_portal.customer_support`) |
| Workspace | **Event Manager** | Organiser | Route `myeventlane_vendor.console.event_workspace` path `/vendor/events/{event}/workspace`, Event Studio vs “manage event” legacy shell |
| Studio | **Event Editor** | Organiser | Routes `myeventlane_vendor.console.studio`, `console.event_editor` use `_title: 'Event Editor'` |
| Boost | **Promote Event** (canonical marketing surface); **Boost** remains **internal/product** where tied to commerce SKU (`boost_upgrade`, `purchase boost for events`) | Organiser | Onboarding “Promote your event with Boost”; vendor analytics/boost surfaces |

**Staff-only language:** Staff playbooks, internal docs, and admin help insights remain **non-public** and **out of vendor/public AI grounding** (see Section D).

---

## Section B — Route ownership (vendor-related patterns)

**Legend:** Canonical = intended primary UX; Legacy = kept for bookmarks/integration; Alias = same behaviour as another path.

### Shell and dashboard entrypoints

| Route pattern | Canonical? | Deprecated? | Notes |
|---------------|------------|---------------|-------|
| `/dashboard` | Shell redirect | No | `myeventlane_vendor.shell.dashboard` → entrypoint redirect |
| `/vendor` | Shell redirect | No | `myeventlane_vendor.shell.vendor_root` |
| `/vendor/dashboard` | **Canonical** organiser dashboard | No | `VendorConsoleAccess`; `_title` still “Vendor dashboard” (terminology drift) |
| `/vendor/studio`, `/vendor/events/{event}/editor` | **Canonical** Event Editor | No | Vendor theme |

### Event operations (workspace / console)

| Route pattern | Canonical? | Deprecated? | Notes |
|---------------|------------|---------------|-------|
| `/vendor/events`, `/vendor/events/add` | Canonical listing/create | No | |
| `/vendor/events/{event}/workspace` | Canonical workspace shell | No | “Event Manager” in governance wording |
| `/vendor/console/...` API-style paths | Mixed | Partial legacy naming | Many routes under `myeventlane_vendor.console.*` |
| `/vendor/event/{event}/*` manage-event steps | **Mixed** | Partial legacy | Singular `event`; some steps redirect to workspace/studio elsewhere |

### Placeholders (see Section C)

| Route pattern | Canonical? | Deprecated? | Notes |
|---------------|------------|---------------|-------|
| `/vendor/event/{event}/promote` | Placeholder | No | Coming soon |
| `/vendor/event/{event}/payments` | Placeholder | No | |
| `/vendor/event/{event}/comms` | Placeholder | No | |
| `/vendor/event/{event}/advanced` | Placeholder | No | |

### Help

| Route pattern | Canonical? | Deprecated? | Notes |
|---------------|------------|---------------|-------|
| `/help` | **Canonical** hub | No | |
| `/help/index` | Legacy | Yes | 301 → `/help` |
| `/vendor/help` | Legacy entry | Redirect | 301 → `/help` (`HelpCentreController::vendorHelp`); permission `access content` |
| `/vendor/help/topic/{category}` | Canonical scoped topic | No | Requires **`view vendor help centre`** |

### Stripe / onboarding (selected)

| Route pattern | Canonical? | Deprecated? | Notes |
|---------------|------------|---------------|-------|
| `/stripe/connect`, `/stripe/manage` | Canonical | No | Custom Stripe access |
| `/stripe/callback`, `/stripe/connect/callback` | Callback | Legacy pathsecond | Legacy callback retained |
| `/vendor/onboard/*` | Canonical onboarding | No | Mixed `_permission: access content` + login |

### Public organiser directory

| Route pattern | Canonical? | Deprecated? | Notes |
|---------------|------------|---------------|-------|
| `/vendors`, `/organisers` | Alias pair | No | Same controller; title “Event Organisers” |
| `/vendor/{entity}` | Canonical organiser profile | No | Entity `myeventlane_vendor` |

---

## Section C — Placeholder surfaces (`ManageEventPlaceholderController`)

| Route name | Path | Visibility | Linked from nav? | Indexed (risk) | Contextual help | AI grounding |
|------------|------|------------|------------------|----------------|-----------------|--------------|
| `myeventlane_vendor.manage_event.promote` | `/vendor/event/{event}/promote` | Event owners + vendor team members (+ node admins) via `ManageEventControllerBase::access` | Yes — manage-event sidebar steps | **Mitigated:** `noindex,nofollow` meta + `X-Robots-Tag` | No card on `myeventlane_manage_event` layout | Not in HelpRetriever (`help_article` only) |
| `manage_event.payments` | `/vendor/event/{event}/payments` | Same | Yes | Same | Same | Same |
| `manage_event.comms` | `/vendor/event/{event}/comms` | Same | Yes | Same | Same | Same |
| `manage_event.advanced` | `/vendor/event/{event}/advanced` | Same | Yes | Same | Same | Same |

**Support risk:** Users can open real URLs that only show “coming soon”; mitigate with product comms and prioritisation (out of scope here).

**Implementation (this pass):** Meta robots + `X-Robots-Tag`, TWIG shell classes `mel-coming-soon-shell`, subscriber `ManageEventPlaceholderNoIndexSubscriber`.

---

## Section D — Help, search, and AI grounding

### Modules inspected

- `myeventlane_help_assistant` — `HelpRetriever`
- `myeventlane_help_centre` — routes, `HelpCentreController`, `ContextualHelpResolver`, `myeventlane_help_centre.module` preprocess
- `myeventlane_help_shared` — `UnifiedHelpRetriever`
- Config: `config/sync/search_api.index.mel_content.yml`, `config/sync/myeventlane_help_centre.contextual_help.yml`

### Help assistant security model (verified)

| Requirement | Status | Mechanism |
|-------------|--------|-----------|
| Staff audience excluded from assistant | Enforced | Query filter + `nodeAudienceAllowedForAssistant` rejects `staff`; Search API OR group only `public`/`vendor` list values |
| Unpublished excluded | Enforced | `status` condition + `$node->isPublished()` |
| `field_help_ai_allowed` | Enforced | Required truthy in `isNodeRetrievableForAssistant` / `UnifiedHelpRetriever::validateNode` |
| Node access | Enforced | `$node->access('view', $user)` |
| Audience filtering | Enforced | Per-user allowed audience list + per-node audience intersection |

**Do not weaken** the above when changing retrieval.

### Vendor help permission drift (fixed in sync)

- Permission **`view vendor help centre`** exists (`myeventlane_help_centre.permissions.yml`) and gates **`myeventlane_help_centre.vendor_topic`**.
- **`user.role.vendor`** and **`user.role.mel_pro`** now include this permission so organisers with console access can open topic URLs without false 403s.
- **`myeventlane_help_centre.vendor_help`** remains a **301 redirect** with **`access content`** so bookmarks still clear.

### FAQ vs Search API (`mel_content`)

| Surface | FAQ (`node.type.faq`) | Notes |
|---------|----------------------|-------|
| Help hub | Yes | `HelpCentreController::homepage` embeds view `mel_help_faq` |
| Help search view `mel_help_search` | **No** | Filters **bundle = `help_article` only** (`views.view.mel_help_search.yml`) |
| `mel_content` index | **No** | Selected bundles: `article`, `event`, `help_article`, `help_landing_page`, `page` |
| Site `/search` “Pages / Blog” group | **No** for FAQ | `SearchController::runContentQuery` keeps only `article` + `page` rows from results |

**Recommendation:** Keep FAQ **out of `mel_content`** unless product explicitly wants FAQs in **site-wide** search; adding them would require teaser/view-mode mapping, field parity, and a deliberate decision on `/search` grouping—not done here.

---

## Section E — Canonical navigation models (target mental models)

### Customer (account menu + primary IA)

**Target hierarchy:** Discover → Event → Booking → Tickets → Support → Organisers → Saved Events → Categories → Notifications → Settings.

**Observed deviations (non-exhaustive):**

- Account menu links are **split across modules** (vendor dashboard/events vs RSVP export vs Support vs Help centre)—there is **no single YAML** declaring the full hierarchy.
- **Discover** is primarily theme/global IA, not one menu plugin.
- **Settings** for organisers routes through **`myeventlane_vendor.console.settings`** (weight −47) labelled “Settings”; customer profile settings may live under core user routes—verify per environment.

### Organiser (vendor console)

**Target hierarchy:** Dashboard → Events → Event Editor → Orders → Attendees → Check-in → Payouts → Promote → Messaging → Analytics → Support → Settings.

**Observed deviations:**

- **Promote** appears both as **Boost/commerce** surfaces and as a **placeholder** manage-event step (`/vendor/event/{event}/promote`).
- **Event Editor** vs **legacy manage-event** sidebar coexist (terminology and URLs overlap).
- Console **tabs** are built in PHP (`VendorEventTabsService`, workspace view models)—compare to target list in ongoing consolidation.

---

## Section F — Security & access snapshot (audit-only)

**Themes for detailed findings:** See `docs/platform-governance-audit.md`.

- **`_access: 'TRUE'`** appears on intentional surfaces (e.g. Stripe callbacks, login alias, some analytics POST endpoints)—each needs route-by-route justification (documented in governance file summary).
- **Vendor isolation:** `VendorConsoleAccess`, `VendorPathMembershipGuardSubscriber`, `ManageEventControllerBase::access`, `VendorManagedEventConsoleAccess` — **do not consolidate without a single ownership review** (risk of bypass).

---

## File references (inspection anchors)

- `web/modules/custom/myeventlane_vendor/myeventlane_vendor.routing.yml`
- `web/modules/custom/myeventlane_help_centre/myeventlane_help_centre.routing.yml`
- `web/modules/custom/myeventlane_vendor/src/Controller/ManageEventPlaceholderController.php`
- `web/modules/custom/myeventlane_help_assistant/src/Service/HelpRetriever.php`
- `web/modules/custom/myeventlane_help_shared/src/Service/UnifiedHelpRetriever.php`
- `config/sync/search_api.index.mel_content.yml`
- `config/sync/views.view.mel_help_search.yml`
- `web/modules/custom/myeventlane_search/src/Controller/SearchController.php`
