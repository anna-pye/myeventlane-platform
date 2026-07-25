# DDR-003 — Intent-based content containers

**Status:** Accepted  
**Date:** 2026-07-25  
**Version:** RC1  
**Owners:** Design Authority · Technical Authority

---

## Decision

The Vendor Studio **shell may be full width**; **content uses named layout intents** with tokenised max-widths (Form/Reading 800, Workspace 1200, Dashboard 1280, Wide/Marketing 1400). Twig applies classes; SCSS owns widths. No hardcoded content max-widths in templates.

---

## Problem

Competing max-width contracts (`1080`, `1120`, dual page containers) created inconsistent reading lengths, form strain on ultra-wide monitors, and theme debt.

---

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| Always fluid full-bleed content | Forms become unreadable; line length fails |
| Single max-width for all pages | Dashboard boards and settings forms have different jobs |
| Hardcode widths in Twig per page | Unmaintainable; breaks token remaps (dark mode, density) |
| Marketing full-bleed heroes in ops | Wrong emotional model for console |

---

## Reason

- Product clarity: width signals job type  
- Accessibility / readability: form/reading lengths stay sane  
- Maintainability: one token remap  
- Aligns with VX2-02A layout convergence direction  

Authoritative numbers: [11-design-tokens.md](../11-design-tokens.md) · structure: [03-layout-system.md](../03-layout-system.md).

---

## Consequences

- New pages must declare an intent  
- Next-action blocks inside wide workspaces prefer Reading width  
- Ultra-wide side space on forms is intentional  

---

## Future review triggers

- Evidence for a new intent (must not be a one-off pixel value)  
- Density themes (comfortable/compact) requiring paired intent tokens  
- Conflict with a locked runtime contract — document, don’t silently fork  
