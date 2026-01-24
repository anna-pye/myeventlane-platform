# Header & Footer Architecture — MyEventLane Themes

This document describes how header and footer content is sourced and rendered in the main and vendor themes. **Headers and footers are include-driven**: they are built from includes and preprocess, not from wholesale region rendering.

---

## Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  MAIN THEME (myeventlane_theme)                                             │
├─────────────────────────────────────────────────────────────────────────────┤
│  page.html.twig                                                             │
│    └── div.mel-region-header                                                │
│          └── include: includes/header.html.twig                            │
│                • checkbox (mobile)  • <header>  • overlay                   │
│                • Uses: page.header.main_menu, page.header.cart_block         │
│                • Preprocess injects blocks; Twig never renders {{ page.header }} │
│    └── div.mel-region-footer                                                │
│          └── include: includes/footer.html.twig                            │
│                • Uses: page.footer.footer_menu (or fallback links)          │
│                • post_footer: hardcoded in include; region UNUSED           │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│  VENDOR THEME (myeventlane_vendor_theme)                                    │
├─────────────────────────────────────────────────────────────────────────────┤
│  page.html.twig                                                             │
│    └── header.mel-vendor-shell__header  (wrapper)                           │
│          └── include: includes/header.html.twig                            │
│                • Uses: page.workspace_name, page.quick_actions, page.user_menu │
│                • All from preprocess; NO header region                      │
│    └── footer.mel-vendor-shell__footer  (wrapper)                           │
│          └── include: includes/footer.html.twig                             │
│                • Uses: page.footer_links (often unset) → fallback links     │
│                • NO footer region                                          │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Main vs Vendor — Differences

| Aspect | Main theme | Vendor theme |
|--------|------------|--------------|
| **Header/footer regions** | `header`, `footer`, `post_footer` exist in `.info.yml` | No header or footer regions |
| **How content is sourced** | Preprocess injects specific keys into `page.header.*` / `page.footer.*`; blocks placed in regions are *sources*, not rendered via `{{ page.header }}` | 100% preprocess: `page.workspace_name`, `page.quick_actions`, `page.user_menu`, `page.footer_links` |
| **Rendering** | `includes/header.html.twig`, `includes/footer.html.twig`; Twig reads only the keys preprocess populates | Same: includes only; no `{{ page.header }}` or `{{ page.footer }}` |
| **Block placement in UI** | Blocks can be placed in `header` / `footer` only as *sources* for preprocess; **they do not render by region** | N/A — no header/footer regions |

---

## Main Theme: Header Block Mapping

### 1. Site branding

- **Source:** Logo is rendered **directly in the header include** (`templates/includes/header.html.twig`). No block.
- **Block "Site branding"** may exist in region `header` as a source for other logic; the logo markup itself is hardcoded in Twig.

### 2. Primary navigation

- **Source:**
  - Block: `myeventlane_theme_main_menu`
  - Menu: Main navigation
  - Region: `header`
- **Preprocess:** `myeventlane_theme_preprocess_page()` loads this block from the `header` region and assigns it to `page.header.main_menu` (or maps `page.header.myeventlane_theme_main_menu` → `page.header.main_menu`).
- **Rendered in:** `templates/includes/header.html.twig` (desktop `.mel-nav-desktop` and mobile `.mel-nav-mobile`).
- **Key:** `page.header.main_menu`

### 3. Cart block

- **Source:** Commerce cart block **plugin**.
- **NOT placed via block UI.** Injected in `myeventlane_theme_preprocess_page()` into `page.header.cart_block`.
- **Rendered in:** `templates/includes/header.html.twig` (desktop `.mel-header-cart` and mobile `.mel-mobile-cart`), only when `page.header.cart_block` is set.
- **Key:** `page.header.cart_block`

### 4. Account / Login / Create Event

- **Source:** Hardcoded links and user-context logic in the header include.
- **NOT blocks.** Rendered directly in `templates/includes/header.html.twig`.

---

## Main Theme: Footer Block Mapping

### 1. Footer menu

- **Source:**
  - Block ID used in region: `footer_menu` (or equivalent so it appears as `page.footer.footer_menu`).
  - Menu: Footer (e.g. Attendees, Organisers, Support, Legal).
  - Region: `footer`
- **Accessed as:** `page.footer.footer_menu`
- **Rendered in:** `templates/includes/footer.html.twig` inside `.mel-footer-nav`. If unset, fallback hardcoded links are used.

### 2. Post-footer (copyright, social)

- **Source:** Hardcoded markup in `templates/includes/footer.html.twig` (`.mel-post-footer`, `.mel-post-footer--dark`).
- **Region `post_footer`:** **Exists in `.info.yml` but is intentionally UNUSED.** No blocks are read from it.

---

## Vendor Theme: Header / Footer Mapping

### Header

- **Content:** `page.workspace_name`, `page.quick_actions`, `page.user_menu`
- **Injected by:** `myeventlane_vendor_theme_preprocess_page()`
- **Not blocks, not regions.** Any change requires preprocess and `templates/includes/header.html.twig`.

### Footer

- **Content:** `page.footer_links` (often not set) or fallback links in Twig: **Settings**, **Payouts**, **Main Site**.
- **Rendered in:** `templates/includes/footer.html.twig`
- **Not blocks, not regions.** Changes go through preprocess and the footer include.

---

## What NOT to Do

### Main theme

1. **Do not** place additional blocks in the `header` region expecting them to render automatically. The region is used only as a **source** for preprocess; only `main_menu` (and any logic that reads other blocks) is wired. The include never outputs `{{ page.header }}`.
2. **Do not** place blocks in `post_footer` expecting output. That region is **intentionally unused**; post-footer content is hardcoded in the footer include.
3. **Do not** assume `{{ page.footer }}` or `{{ page.header }}` is rendered. Only specific keys (e.g. `page.footer.footer_menu`, `page.header.main_menu`, `page.header.cart_block`) are used in the includes.

### Vendor theme

1. **Do not** attempt block placement for the vendor header or footer. There are no header/footer regions; everything is preprocess + include.
2. **Do not** add header/footer regions in vendor `.info.yml` without a decision to change this architecture; all current content is provided by `myeventlane_vendor_theme_preprocess_page()` and the includes.

### Both

- **Do not** remove or bypass the include-driven pattern without updating preprocess, includes, and this doc. Headers and footers are **include-driven**, not region-rendered.

---

## Pointers to Code

### Templates (include-driven)

| Theme  | Header include | Footer include |
|--------|----------------|----------------|
| Main   | `web/themes/custom/myeventlane_theme/templates/includes/header.html.twig` | `web/themes/custom/myeventlane_theme/templates/includes/footer.html.twig` |
| Vendor | `web/themes/custom/myeventlane_vendor_theme/templates/includes/header.html.twig` | `web/themes/custom/myeventlane_vendor_theme/templates/includes/footer.html.twig` |

### Preprocess

| Theme  | File | Function |
|--------|------|----------|
| Main   | `web/themes/custom/myeventlane_theme/myeventlane_theme.theme` | `myeventlane_theme_preprocess_page()` |
| Vendor | `web/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.theme` | `myeventlane_vendor_theme_preprocess_page()` |

### Main theme regions (for block *sources* only)

- **Header:** `header` — used as source for `main_menu` (and cart via plugin, not UI).
- **Footer:** `footer` — used as source for `footer_menu` (key `page.footer.footer_menu`).
- **Post-footer:** `post_footer` — **unused**; do not place blocks here for output.

---

*Last updated: Stage 3 — Block & region mapping (runbook).*
