# Onboarding Audit

**Brand rollout:** The Hidden Gem + The Guide (Bright Edition)
**Audit date:** 2026-06-14
**Method:** Evidence-based.

---

## 1. Registration / first login (evidence)

| Surface | Evidence | Notes |
|---|---|---|
| Account creation | Standard Drupal `user.register`, altered by `myeventlane_auth_form_user_register_form_alter()` (`myeventlane_auth.module:287`) + post-save redirect (`:411`) | No custom multi-field registration |
| Login | `/auth/login` (`AuthController::login`); OAuth/token stack (`/auth/authorize`, `/auth/token`, `/auth/refresh`, `/auth/me`, `/auth/revoke`); vendor SSO `/vendor/sso/callback` | |
| **Post-login routing** | `/mel/post-login` → `MelPostLoginController`; `PostLoginDecision`, `PostAuthRedirectResolver`, `PostLoginHubRedirectSubscriber` | Sophisticated post-auth decision layer |
| **Post-login hub** | `templates/mel-post-login-hub.html.twig`, built by `PostLoginHubBuilder` (data-only template) | **First screen after login** |

### Post-login hub structure (`mel-post-login-hub.html.twig`)
Variables from `PostLoginHubBuilder`: `mode` (customer/vendor), `badges`, `headline`, `body`, `primary_cta`, `secondary_ctas`, and **`recommendation`** (`hub.recommendation`).

> **Top onboarding finding:** the post-login hub **already exposes a `recommendation` slot and a `headline/body`** with a customer/vendor mode switch. This is the **single best Guide-moment surface** — the first thing a logged-in attendee sees, already wired for a recommendation, driven by a builder service (no Twig logic). A Guide welcome ("Welcome back — here's a gem the Guide found for you") is a builder + copy change.

---

## 2. Vendor onboarding (evidence)

Full step-by-step flow (`myeventlane_vendor.routing.yml`):

| Step | Path |
|---|---|
| Start | `/vendor/onboard` |
| Account | `/vendor/onboard/account` |
| Profile | `/vendor/onboard/profile` |
| Stripe Connect | `/vendor/onboard/stripe` (+ return/refresh) |
| Branding | `/vendor/onboard/branding` |
| First event | `/vendor/onboard/first-event` (`VendorOnboardFirstEventController::firstEvent`) |
| **Create-event gateway** | enforces onboarding before event creation (`routing.yml:167`) |

Supporting UI: `vendor-onboard-step.html.twig`, `onboarding-journey-steps.html.twig`, `onboarding-progress.html.twig`, `vendor-onboard-tooltip.js`, `vendor-onboard.js`, and a **`vendor-onboard-complete-celebration.html.twig`** (completion moment). Dashboard onboarding panel: `vendor-dashboard-onboarding-panel.html.twig`.

> Vendor onboarding is **operational workspace** (per `surface-boundary-audit.md`). The Guide should appear here only **lightly** (encouraging, not the discovery persona). The completion celebration is a good light-touch Guide moment.

---

## 3. Customer / attendee onboarding & event-creation onboarding

| Surface | Evidence |
|---|---|
| Customer onboard step | `myeventlane_core/templates/customer-onboard-step.html.twig` (`myeventlane_core/css/onboarding.css`) |
| Surface onboarding step | `myeventlane_surface/templates/components/mel-onboarding-step.html.twig` |
| Onboarding SCSS system | `myeventlane_theme/src/scss/onboarding/`: `_onboarding-shell`, `_onboarding-start`, `_onboarding-progress`, `_onboarding-forms`, `_onboarding-footer` |
| Event creation | gated by vendor onboarding (Create-event gateway); Event Studio (`myeventlane_event_studio`) |

> **Repository evidence not found** for an attendee **interest/preference/vibe capture step at registration**. Registration is standard Drupal. This is a *gap* — and an opportunity: a Guide-led "what are you into?" step (reusing the Vibe Mixer) would seed recommendations.

---

## 4. Empty states (Guide-moment surfaces)

Reusable component `templates/components/empty-state.html.twig` (`.mel-empty-state--governed`): `title`, `text`, primary + secondary CTA. SCSS split (`_mel-empty-states.scss` vs `utilities/_empty-states.scss`) flagged in `component-inventory.md`. Empty states appear in browse, calendar, saved events, search no-results.

> Empty states are **prime Guide moments**: "Nothing saved yet — let the Guide find your first gem." The component already supports heading + copy + CTAs.

---

## 5. Where Guide moments could exist (evidence-mapped)

| Moment | Surface | Existing hook | Effort | Priority (public discovery focus) |
|---|---|---|---|---|
| **Welcome + first recommendation** | Post-login hub | `recommendation` slot + `PostLoginHubBuilder` | Builder + copy | ★★★ Highest |
| **"What are you into?" preference capture** | Registration / first session | Vibe Mixer component (unwired) | New step (reuse component) | ★★★ |
| **Empty-state nudges** | saved/search/browse empties | `empty-state.html.twig` | Copy + CTA | ★★ |
| **Discovery digest opt-in** | Post-login / onboarding | Category digest pipeline + notification prefs (`email-audit.md`) | Copy + toggle | ★★ |
| **Vendor onboarding encouragement** | Vendor flow + celebration | `vendor-onboard-complete-celebration.html.twig` | Light copy only | ★ (operational — keep light) |

---

## 6. Verdicts

| Verdict | Item |
|---|---|
| **SAFE TO REUSE** | Post-login hub + builder (with recommendation slot), `empty-state` component, onboarding SCSS system, vendor onboarding flow, celebration template |
| **NEEDS EVOLUTION** | Post-login hub copy/recommendation → Guide voice; empty-state copy; re-skin onboarding to Bright Edition |
| **ADD (reuse components)** | Attendee interest/vibe capture step (reuse Vibe Mixer); discovery digest opt-in |
| **DON'T TOUCH** | Auth/OAuth/token flow, Stripe Connect onboarding, post-login *redirect decision logic* (access/security) |

**Bottom line:** Onboarding already has the ideal Guide entry point — a **post-login hub with a recommendation slot and a builder service**. The highest-value Guide moment is a **copy + builder change there**, plus an optional reuse of the Vibe Mixer for interest capture. No new onboarding architecture required; security/auth flows untouched.
