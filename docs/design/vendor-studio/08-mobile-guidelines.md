# Vendor Studio — Mobile Guidelines

**Version:** RC1  
**Status:** Design authority (documentation only)

## Purpose

Define mobile-first operating rules so urgent organiser jobs succeed on a phone — authoritative home for mobile behaviour (with [DDR-005](decisions/DDR-005-mobile-first.md)).

## Scope

Navigation, cards, tables, forms, sticky/bottom actions, touch targets, responsive priorities, Door Mode mobile. Layout structure: [03](03-layout-system.md). Tokens: [11](11-design-tokens.md).

## Audience

Designers, frontend engineers, Door Mode implementers.

## Related documents

- [03-layout-system.md](03-layout-system.md)
- [07-interaction-guidelines.md](07-interaction-guidelines.md)
- [13-event-workspace-philosophy.md](13-event-workspace-philosophy.md)
- [DDR-005](decisions/DDR-005-mobile-first.md)
- [11-design-tokens.md](11-design-tokens.md)

---

## Why mobile-first ops

Mobile is a first-class operating surface for urgent jobs (Door Mode, today’s attention, quick sales checks). It is not a shrunk desktop.

**Baseline:** 390px width; enhance upward.

---

## 1. Navigation

| Rule | Why |
| --- | --- |
| Global nav in drawer / sheet, not a permanent miniature sidebar | Preserves content width |
| Show current section in header | Answers “Where am I?” |
| Event Workspace uses horizontal scroll tabs or a section picker | Many sections; avoid nested drawers of doom |
| Create event remains reachable from header or FAB-equivalent primary | Chrome primary action |
| Limit visible priority destinations if using bottom nav | ≤5 items; remainder in menu |

Do not ship two competing mobile nav systems on one surface ([19](19-anti-patterns.md)).

---

## 2. Cards

| Rule | Why |
| --- | --- |
| Cards for interactive units (event row, action item) | Touch scanning |
| One primary action per card | Golden Rule |
| Avoid card-inside-card nesting | Hierarchy collapse |
| Metric cards in 2-column grids max | Readability |

If a card is only decorative grouping, prefer flat panels with spacing ([DDR-004](decisions/DDR-004-component-philosophy.md)).

---

## 3. Tables

Choose **one** pattern per surface and keep it:

| Pattern | Best for |
| --- | --- |
| **Card rows** | Attendees, events list — few columns matter |
| **Horizontal scroll table** | Orders with many comparable columns |

| Rule | Why |
| --- | --- |
| Sticky first column optional for scroll tables | Context while scanning |
| Row actions behind a clear “Actions” control | Prevent mis-taps |
| Never rely on hover-only actions | Touch |

---

## 4. Forms

| Rule | Why |
| --- | --- |
| Single column | Error association and speed |
| 16px minimum input text | iOS zoom / readability |
| Labels above fields | Stable layout |
| Date/time inputs use appropriate mobile keyboards / pickers | Fewer typos |
| Group with short section titles | Progressive completion |
| Autosave status visible near the top or sticky | Anxiety reduction |

---

## 5. Sticky actions

| Use when | Avoid when |
| --- | --- |
| Primary commit must remain available while scrolling long forms | Multiple sticky bars compete |
| Door Mode primary scan/check-in action | Marketing pages with no commit |

Sticky bars use elevation separation and safe-area insets. They must not cover error messages — scroll errors into view on submit.

---

## 6. Bottom actions

| Pattern | Guidance |
| --- | --- |
| Single primary button full width | Default |
| Primary + quiet text secondary | Acceptable |
| Two equal primary buttons | Not allowed |
| Destructive + primary | Destructive is secondary/outline; confirm in dialog |

---

## 7. Touch targets

| Spec | Value |
| --- | --- |
| Minimum target | 44×44px |
| Spacing between targets | ≥8px |
| List rows | Comfortable height ≥48px where actionable |

Icon-only controls need accessible names. Token home: [11](11-design-tokens.md).

---

## 8. Responsive priorities

When space is scarce, preserve in this order:

1. **Primary task CTA**
2. **Blocking status / errors**
3. **Core content for the job**
4. Metrics that change the decision
5. Secondary nav / help
6. Decorative illustration / marketing modules

Hide or relocate (5)–(6) before compromising (1)–(3).

---

## Door Mode (special case)

- Maximise list/search; minimise shell chrome
- Large check-in controls
- Offline / failure messaging must be unmistakable
- Prefer one-handed reach zones for primary action

Full Door Mode philosophy: [13-event-workspace-philosophy.md](13-event-workspace-philosophy.md). Offline-first enhancements: [20](20-vendor-studio-v2-vision.md) / [A03](appendices/A03-future-ideas-parking-lot.md).

---

## Design implications

- Mobile QA is mandatory for Door Mode, Dashboard attention, and sticky commit flows
- Hover-only essential actions fail review

## Future considerations

- Bottom navigation is optional and must be validated against IA count; do not assume it replaces the drawer
- Tablet landscape may keep compact rail; treat as tablet rules in [03](03-layout-system.md), not phone rules

## Related references

- [03](03-layout-system.md) · [07](07-interaction-guidelines.md) · [13](13-event-workspace-philosophy.md) · [DDR-005](decisions/DDR-005-mobile-first.md) · [19](19-anti-patterns.md)
