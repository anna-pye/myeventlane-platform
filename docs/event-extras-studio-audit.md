# Event Extras Studio — Phase 0 audit

**Date:** 2026-05-18  
**Git:** clean working tree, no merge in progress.

## 1. Routes

| Path | Route name | Controller | Section | Access |
|------|------------|------------|---------|--------|
| `/vendor/events/{node}/studio/merchandise` | `myeventlane_event_studio.workspace_merchandise` | `EventStudioController::workspace` | `merchandise` | `EventStudioAccess::access` |
| `/vendor/events/{node}/studio/addons` | `myeventlane_event_studio.workspace_addons` | same | `addons` | same |
| `/vendor/events/{node}/studio/add-ons` | `myeventlane_event_studio.workspace_add_ons` | same | `addons` | same |
| `/vendor/events/{node}/studio/fulfilment` | `myeventlane_event_studio.workspace_fulfilment` | same | `fulfilment` | same |
| `/vendor/events/{node}/studio/extras` | — | — | — | **Did not exist** |

All workspace routes use theme `mel_event_studio_workspace`; section content from `EventStudioSectionRenderer` + section plugins.

## 2. Section plugins

| Section | State | Render target | Nav |
|---------|-------|---------------|-----|
| `merchandise` | active | `EventStudioProductisationForm` | visible |
| `addons` | deferred | `deferred_empty` | visible (shows “Planned”) |
| `fulfilment` | active | `EventStudioOperationalCapabilityForm` | visible |

**Merchandise vs add-ons:** Not duplicate logic. Merchandise is the full productisation studio; add-ons is a deferred placeholder only.

## 3. Services / forms

- `EventStudioProductisationForm` — multi-type productisation (5 types), link-or-create Commerce, autosave key `merchandise`.
- `VendorOperationalProductCreationManager` — **create-only** on explicit save; strips forbidden keys; sizes on create; **no update**, **no images** on create.
- `EventOperationalAddonBuilder` — booking page reads products via `field_event` + published status (not productisation JSON).
- `OperationalExtraVisualPresenter` — gallery, sizes, pickup note from entity fields.
- `VendorProductisationStudioBuilder` — cards + preview strip.

## 4. CRUD capabilities (pre-unification)

| Capability | Supported? |
|------------|------------|
| Create product | Yes (`createOperationalProductForEvent`) |
| Update product | **No** dedicated method |
| Multiple size variations | Yes on **create** only |
| Update variations / preserve IDs | **No** |
| Unpublish removed sizes | **No** |
| Vendor/event/store ownership on create | Yes |
| `field_mel_extra_images` in Studio | **No** (Commerce admin form display only) |

## 5. Raw Commerce product edit

No vendor-facing links to `entity.commerce_product.edit_form` found in custom modules. Admin `/admin/commerce/products/{id}/edit` remains debug path.

## 6. Config

Confirmed in `config/sync`: `field_mel_extra_images`, `field_mel_extra_short_desc`, `field_mel_extra_pickup_note`, `field_mel_operational_product`, `field_event` on operational bundles; `field_mel_size` on `operational_merchandise_var`; form displays use `media_library_widget` for images.

## 7. Proceed / stop

**Proceed:** access (`EventStudioAccess`, `EventVendorAccessChecker`), services exist, ownership patterns clear. Gaps (update, images, unified UX) are the implementation target.
