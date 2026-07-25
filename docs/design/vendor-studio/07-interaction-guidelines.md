# Vendor Studio — Interaction Guidelines

**Version:** RC1  
**Status:** Design authority (documentation only)

## Purpose

Define how Vendor Studio **behaves** under pointer, keyboard, save, load, and validation — so interactions increase certainty.

## Scope

Hover, focus, animation, transitions, autosave, notifications, loading, validation, keyboard shortcuts, accessibility behaviour. Motion **token durations**: [11](11-design-tokens.md). Autosave/publish philosophy in Workspace: [13](13-event-workspace-philosophy.md).

## Audience

Designers, frontend engineers, accessibility reviewers.

## Related documents

- [04-design-language.md](04-design-language.md)
- [05-component-library.md](05-component-library.md)
- [08-mobile-guidelines.md](08-mobile-guidelines.md)
- [11-design-tokens.md](11-design-tokens.md)
- [13-event-workspace-philosophy.md](13-event-workspace-philosophy.md)

---

## Why certainty over cleverness

If motion, hover, or autosave obscures state, remove it. Organisers under door stress and money risk need predictable feedback more than delightful chrome.

---

## 1. Hover

| Rule | Why |
| --- | --- |
| Hover may enhance affordance (lift, underline, background) | Helps pointer users scan |
| Hover never reveals essential information alone | Touch and keyboard users must still succeed |
| Row hover on tables is subtle | Avoid noisy zebra + heavy shadow |
| Disabled controls show default cursor and no hover affordance | Honesty |

Duration: motion-fast (~120ms) per [11](11-design-tokens.md).

---

## 2. Focus

| Rule | Why |
| --- | --- |
| Visible focus ring on all interactive elements | Keyboard accessibility (WCAG) |
| Focus colour uses vendor focus token (coral/accent family) | Distinct from purple primary fill |
| Focus order follows reading order | Predictability |
| Modals/drawers trap focus and restore on close | Prevents lost context |
| Skip link to main content in shell | Efficient navigation |

Never remove focus outlines for aesthetics.

---

## 3. Animations

| Allowed | Disallowed |
| --- | --- |
| Short panel expand/collapse | Decorative looping motion in chrome |
| Dialog enter ≤400ms | Parallax or large layout thrash |
| Success check moments that do not block | Confetti/FOMO celebration patterns |
| Skeleton shimmer reduced under `prefers-reduced-motion` | Motion required to understand state |

---

## 4. Transitions

| Property | Guidance |
| --- | --- |
| Colour / opacity / shadow | Prefer for hover/focus |
| Width / height | Avoid animating layout dimensions when possible; use transform/opacity |
| Route changes | Instant or soft fade; do not delay primary content for animation |

---

## 5. Autosave

| Context | Behaviour |
| --- | --- |
| Event Workspace section forms (where established) | Autosave with explicit “Saving / Saved / Error” status |
| Money, publish, refunds, Stripe | **No silent autosave commits** — explicit confirmation |
| Failure | Error stays visible with retry; do not claim Saved |
| Conflict / session | Fail loudly with recovery path (see Event Studio draft recovery docs) |

**Why:** Autosave reduces anxiety in long forms; silent money mutations destroy trust. Workspace philosophy: [13](13-event-workspace-philosophy.md).

---

## 6. Notifications

| Type | Behaviour |
| --- | --- |
| Success | Polite toast; auto-dismiss after short delay |
| Error | Persistent until dismissed or corrected |
| Warning | Persistent or sticky to the relevant panel |
| Background job | Progress + completion notification |

Do not stack endless toasts; coalesce where possible. Copy: [15](15-copywriting-guide.md).

---

## 7. Loading

| Pattern | When |
| --- | --- |
| Skeleton | Initial page structure known |
| Inline spinner | Small component refresh |
| Button busy state | Preventing double-submit |
| Full-page blocker | Rare — only when leaving mid-state is harmful |

Never show fabricated metrics during load ([19](19-anti-patterns.md)).

---

## 8. Validation

| Rule | Why |
| --- | --- |
| Validate on submit; inline on blur for corrected fields | Balance noise vs guidance |
| Field-level messages adjacent to inputs | Recoverability |
| Summaries at top for multi-error forms | Orientation |
| Server errors mapped to fields when possible | Trust |
| Publishing readiness separate from field validation | Different jobs |

Copy explains how to fix, not only that something is wrong ([15](15-copywriting-guide.md)).

---

## 9. Keyboard shortcuts

| Principle | Detail |
| --- | --- |
| Optional accelerators | Never the only path |
| Document in Support / tooltips | Discoverability |
| Avoid browser conflicts | No hijacking of reserved shortcuts |
| Door Mode | Prefer large controls over shortcut memorisation under stress |

Suggested future set (parked for v1 runtime): `/` focus search where search exists; `Esc` close overlay; `?` shortcut help. See [A03](appendices/A03-future-ideas-parking-lot.md).

---

## 10. Accessibility behaviour

| Requirement | Standard |
| --- | --- |
| Contrast | WCAG AA for text and essential icons |
| Targets | ≥44×44px interactive targets |
| Semantics | Buttons for actions, links for navigation |
| Live regions | For save/notification status |
| Reduced motion | Honoured globally |
| Severity | Icon + text + colour |
| Forms | Labels, instructions, errors programmatically associated |

Vendor isolation and access checks remain server-side — interaction design never “hides” as security.

---

## Design implications

- New JS behaviour ships with keyboard and screen-reader notes in the PR
- Money/publish interactions require explicit confirmation patterns

## Future considerations

- Shortcut maps confirmed with organiser testing before promotion
- Command palette parked ([20](20-vendor-studio-v2-vision.md))

## Related references

- [05](05-component-library.md) · [08](08-mobile-guidelines.md) · [11](11-design-tokens.md) · [13](13-event-workspace-philosophy.md) · [15](15-copywriting-guide.md) · [16](16-design-review-checklist.md)
