# DDR-004 — Component philosophy (extend MEL; cards earn their size)

**Status:** Accepted  
**Date:** 2026-07-25  
**Version:** RC1  
**Owners:** Design Authority · Technical Authority

---

## Decision

Vendor Studio **extends existing MEL / vendor components** (`.mel-btn`, `.mel-card`, alerts, forms, etc.) under `.mel-vendor`. New component names for the same job are forbidden. **Cards earn their size** — if removing border/shadow/radius does not hurt understanding or interaction, it should not be a card.

---

## Problem

Parallel component trees (`vendor-studio-*` forks), nested cards, and decorative panels create drift from public MEL DNA while failing ops clarity.

---

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| Brand-new Studio-only kit | Duplicates maintenance; splits brand |
| Card everything for “premium” | Hierarchy collapse; mobile chrome heaviness |
| Raw Drupal admin components | Wrong language and aesthetics |
| Copy public event-card system into console | Wrong job (discovery vs ops) |

---

## Reason

- Consistency over novelty ([01](../01-vendor-studio-vision.md))  
- Maintainability in `myeventlane_vendor_theme`  
- Accessibility: fewer one-off patterns to QA  
- Aligns with brand governance: reuse before invent  

Contracts: [05-component-library.md](../05-component-library.md) · anti-patterns: [19](../19-anti-patterns.md).

---

## Consequences

- Component additions require purpose, a11y, responsive, Drupal mapping stubs in [05](../05-component-library.md)  
- Dashboard prefers Task List + Metric Cards over novel widgets  
- Nested cards are a review failure  

---

## Future review triggers

- Genuine new interaction model with no MEL analogue (must still document in 05)  
- Design system package extraction (if productised later)  
- Dark mode requiring component token audits (Phase 12)  
