# Brand Implementation Audit

**Brand rollout:** The Hidden Gem + The Guide (Bright Edition)
**Audit date:** 2026-06-14
**Method:** Evidence-based, full-repo asset search.

---

## 1. Logos (evidence)

| Asset | Path | Used by |
|---|---|---|
| Public logo (SVG) | `myeventlane_theme/images/logo.svg` | Public theme |
| Public logo icon | `myeventlane_theme/images/logo-icon.svg` | Favicon/compact |
| Public logo (PNG) | `myeventlane_theme/images/myeventlane-logo.png` | Raster fallback |
| **Email logo** | `myeventlane_theme/images/mel-email-logo.png` | `mel-email-base.html.twig` (`logo_path`) |
| Vendor logo | `myeventlane_vendor_theme/logo.svg` + `myeventlane_vendor_theme/images/logo.svg` | Vendor theme |
| Admin logo | `myeventlane_admin_theme/logo.svg` | Admin |
| Maintenance logo | `mel_maintenance/logo.svg` | Error/maintenance |

Every theme carries its own logo. A Bright Edition wordmark/logo swap touches **one SVG per theme + email PNG**.

---

## 2. Mascot / character — **exists, single asset**

| Asset | Path |
|---|---|
| **MEL mascot** | `myeventlane_theme/images/mel/mel-mascot-round.png` |

> **Key finding for "The Guide":** a mascot **already exists** (`mel-mascot-round.png`) — the only character asset in the repo. The Guide persona has a starting point. **Repository evidence not found** for any other mascot pose, expression set, or illustrated character system in-repo (only this single round PNG).

---

## 3. Illustrations / hero imagery (evidence)

| Asset | Path | Status |
|---|---|---|
| Homepage hero (desktop) | `images/mel/hero/mel-hero-home.png` | Present |
| Homepage hero (mobile) | `images/mel/hero/mel-hero-mobile.png` | Present |
| Homepage hero (abstract SVG) | `images/mel/hero/mel-hero-home-abstract.svg` | Present |
| Events hero | `images/mel/hero/mel-hero-events.png` | **Documented in README, file not committed** |
| Search hero | `images/mel/hero/mel-hero-search.png` | **Documented, not committed** |
| Category heroes | `images/mel/categories/mel-category-{slug}.png` | **Convention only — dir holds `.gitkeep`** (DB term upload preferred) |
| Empty-state image | `images/mel/empty/mel-empty-events.png` | **Documented, not committed (`.gitkeep`)** |
| Empty cart | `images/mel/../mel-empty-cart.png` | Present |
| Default placeholder | `images/mel/placeholders/mel-placeholder-default.svg` | Present |

### Brand-asset convention (`images/mel/README.md`)
- **Category hero:** taxonomy term "Category Image" field (DB) takes precedence; theme file `mel-category-{slug}.png` is fallback.
- **Listing heroes:** per-page paths (`mel-hero-events.png`, `mel-hero-search.png`).
- **Empty states:** `mel-empty-events.png`.

> **The asset pipeline is convention-driven and mostly unpopulated.** Slots and naming are documented; most files are placeholders (`.gitkeep`). This is a **gift for the rollout**: a Bright Edition illustration/mascot pack drops into pre-defined, documented paths (or taxonomy uploads) with **little or no code change**.

---

## 4. Iconography / value graphics

| Asset | Path |
|---|---|
| Accessibility icon | `images/icon-accessibility.svg` |
| Community icon | `images/icon-community.svg` |
| No-fees icon | `images/icon-no-fees.svg` |

Used by value-prop blocks (`_front-values.html.twig`, `_value-cards.scss`).

---

## 5. Not brand assets (clarification)

- Repo-root `assets/` contains only **browser test screenshots** (`browser-screenshot-*.png`, `Screenshot_*.png`) — QA artifacts, not brand graphics.
- External asset folders on disk (`~/Desktop/mel-assets-illustrated`, `~/Desktop/mel-assets-final`, Trash `mel-illustrations-pack`) are **outside the repository** and therefore **not part of the codebase**. Per repository-first rule they are noted as existing on the machine but are **not committed evidence**. If they hold the Bright Edition pack, they must be brought into `images/mel/` to count.

---

## 6. What can be evolved

| Verdict | Item | Why |
|---|---|---|
| **EVOLVE** | `mel-mascot-round.png` → The Guide character | Only mascot; the brand's hero persona |
| **EVOLVE** | All logos (`logo.svg` ×4 + `mel-email-logo.png`) | Bright Edition wordmark |
| **EVOLVE** | Hero imagery (home/mobile/abstract) | Bright Edition art direction |
| **POPULATE (low risk)** | Documented-but-empty slots: events/search/category heroes, `mel-empty-events.png` | Slots & conventions already exist |
| **EVOLVE** | Value icons (accessibility/community/no-fees) | Brand consistency |
| **KEEP** | Placeholder/empty-cart defaults | Functional; re-skin optional |
| **DON'T TOUCH** | Vendor/admin logos beyond optional parity | Operational/back-office |

---

## 7. Verdicts summary

| Verdict | Detail |
|---|---|
| **REUSE (structure)** | The entire `images/mel/` convention + README naming; email `logo_path`; per-page hero slots; taxonomy category-image field |
| **EVOLVE (art)** | Mascot → Guide; logos; heroes; value icons |
| **ADD** | Populate empty documented slots with Bright Edition art; Guide mascot pose/expression set (only one pose exists today) |
| **GAP** | No multi-pose mascot system, no illustration library, no OG/social share image system found in-repo |

**Bottom line:** Branded *infrastructure* (logo slots, hero conventions, an email logo hook, a mascot, value icons, a documented asset pipeline) exists, but the *art* is minimal and many documented slots are empty. The Bright Edition is mostly an **art-replacement + slot-population exercise into an existing, documented pipeline** — very low code risk. The biggest creative net-new is turning the single mascot into a full "Guide" character system.
