# Vendor Studio — Component Library

**Version:** RC1  
**Status:** Design authority (documentation only)

## Purpose

Define reusable **component contracts** for Vendor Studio — purpose, behaviour, accessibility, responsive rules, and Drupal mapping stubs.

## Scope

Component-level contracts. Visual tokens: [11](11-design-tokens.md). Interaction detail: [07](07-interaction-guidelines.md). Philosophy: [DDR-004](decisions/DDR-004-component-philosophy.md). Page composition: [06](06-workspace-patterns.md).

## Audience

Designers, theme engineers, module developers attaching render arrays.

## Related documents

- [03-layout-system.md](03-layout-system.md)
- [07-interaction-guidelines.md](07-interaction-guidelines.md)
- [09-drupal-mapping.md](09-drupal-mapping.md)
- [11-design-tokens.md](11-design-tokens.md)
- [19-anti-patterns.md](19-anti-patterns.md)
- [DDR-004](decisions/DDR-004-component-philosophy.md)

---

## Why contracts

Without shared contracts, every surface invents a slightly different button, alert, or empty state — organisers relearn chrome, and theme debt compounds. Prefer extending existing `.mel-*` / vendor partials over new names for the same job.

For each component: **Purpose · Behaviour · Accessibility · Responsive · Future Drupal mapping**.

---

## 1. Workspace Hero

| | |
| --- | --- |
| **Purpose** | Orient the page: title, status, one primary action |
| **Behaviour** | Answers Three Questions Framework lines 1–3 in compact form; optional readiness line |
| **Accessibility** | Single H1; CTA is a real button/link; status not colour-only |
| **Responsive** | Stack CTA below title on mobile; no decorative full-bleed media |
| **Drupal mapping** | Twig page header partials in Event Workspace / console templates; preprocess supplies title, status, primary_url |

---

## 2. Metric Cards

| | |
| --- | --- |
| **Purpose** | Show a small set of KPIs that support decisions |
| **Behaviour** | Max four per row on desktop; each card: label, value, optional delta/context; click only if drill-down exists |
| **Accessibility** | Text labels; deltas announced with meaning (“up/down”), not colour alone |
| **Responsive** | 2-col mobile; avoid horizontal metric carousels that hide values |
| **Drupal mapping** | Render arrays from dashboard / analytics view models; SCSS card grid utilities |

---

## 3. Action Cards

| | |
| --- | --- |
| **Purpose** | Promote a single recoverable task (“Connect Stripe”, “Finish tickets”) |
| **Behaviour** | Severity + title + reason + one CTA; dismiss only when safe |
| **Accessibility** | Severity icon + text; focusable CTA |
| **Responsive** | Full width in reading/form containers |
| **Drupal mapping** | Action queue items (`VendorActionQueueBuilder` pattern); alert/card Twig |

---

## 4. Task Lists

| | |
| --- | --- |
| **Purpose** | Ranked work queue (dashboard attention) |
| **Behaviour** | Sorted by severity from backend; do not re-sort in Twig; empty = calm “caught up” |
| **Accessibility** | List semantics; each row has clear link name |
| **Responsive** | Single column; actions inline or overflow menu |
| **Drupal mapping** | View model list → Twig list component; cache tags from builders |

---

## 5. Data Tables

| | |
| --- | --- |
| **Purpose** | Compare and act on many records (orders, attendees) |
| **Behaviour** | Sticky header optional; row actions secondary; bulk actions explicit |
| **Accessibility** | `<table>` with headers; sortable columns announce state; row actions keyboardable |
| **Responsive** | Card-row transformation or horizontal scroll — pick one pattern per surface and keep it |
| **Drupal mapping** | Views / custom builders → table Twig; libraries for progressive enhancement only |

---

## 6. Forms

| | |
| --- | --- |
| **Purpose** | Edit organiser or event data safely |
| **Behaviour** | Grouped sections; autosave where established (Event Workspace); explicit Save when money/publish risk |
| **Accessibility** | Visible labels; helpers; field-level errors; `aria-invalid` / describedby |
| **Responsive** | Form container 800px; stacked fields; 44px targets |
| **Drupal mapping** | Form API + theme form alters; section forms in workspace plugins |

---

## 7. Inputs

| | |
| --- | --- |
| **Purpose** | Collect discrete values |
| **Behaviour** | Consistent height, focus ring, error/success adjacent text |
| **Accessibility** | Label association mandatory; placeholders never replace labels |
| **Responsive** | Full width in form column; avoid side-by-side inputs on mobile unless paired (e.g. time range) |
| **Drupal mapping** | Core Form API widgets themed in vendor `base/_forms` / `components/_forms` |

---

## 8. Buttons

| | |
| --- | --- |
| **Purpose** | Commit or navigate |
| **Behaviour** | Primary / secondary / quiet / destructive hierarchy; one primary per region |
| **Accessibility** | Disabled uses `disabled` or `aria-disabled` with explanation; focus visible |
| **Responsive** | Min 44×44px; full-width primary on mobile when sticky |
| **Drupal mapping** | `.mel-btn` variants in vendor theme; Form API actions themed consistently |

---

## 9. Badges

| | |
| --- | --- |
| **Purpose** | Status at a glance (Draft, Live, Paid, Refunded) |
| **Behaviour** | Sentence case; one badge emphasis per row/card |
| **Accessibility** | Text + optional icon; not colour-only |
| **Responsive** | Truncate with title tooltip only if full text available to AT |
| **Drupal mapping** | Badge partial / status field formatters |

---

## 10. Alerts

| | |
| --- | --- |
| **Purpose** | Page-level or inline system messages |
| **Behaviour** | Info / warning / error / success; include recovery when blocked |
| **Accessibility** | `role="status"` or `alert` appropriately; do not auto-dismiss errors |
| **Responsive** | Full content width of container |
| **Drupal mapping** | Drupal messengers themed; custom `vendor-alert` partials |

---

## 11. Panels

| | |
| --- | --- |
| **Purpose** | Group related content without implying a new page |
| **Behaviour** | Title optional; body; footer actions rare |
| **Accessibility** | Heading structure inside panel |
| **Responsive** | Stack; avoid multi-column panels on mobile |
| **Drupal mapping** | `.mel-card` / panel Twig; layout hierarchy modifiers (`--status`, `--next`, etc.) |

---

## 12. Charts

| | |
| --- | --- |
| **Purpose** | Reveal trends that change decisions |
| **Behaviour** | Always ship a text/table alternative for critical numbers; empty state when no data |
| **Accessibility** | Summaries in text; colour-blind safe series; keyboard focus for interactive legends if any |
| **Responsive** | Simplify series on mobile; prefer one chart per view |
| **Drupal mapping** | `chartjs` library in vendor theme; analytics templates; data from analytics services |

---

## 13. Drawers

| | |
| --- | --- |
| **Purpose** | Secondary context without leaving the page (filters, help, detail peek) |
| **Behaviour** | Esc closes; focus trap; return focus to opener |
| **Accessibility** | `dialog` pattern; labelled by title |
| **Responsive** | Full-height sheet on mobile |
| **Drupal mapping** | Progressive JS library + Twig wrapper; no business logic in JS beyond UI |

---

## 14. Dialogs

| | |
| --- | --- |
| **Purpose** | Confirm destructive or irreversible actions |
| **Behaviour** | State the consequence; confirm label names the action (“Refund order”); never “OK” for money |
| **Accessibility** | Focus trap; Esc; initial focus on safe control |
| **Responsive** | Near full-width on mobile with margins |
| **Drupal mapping** | Confirm forms / modal patterns; Drupal AJAX dialogs themed carefully |

---

## 15. Notifications

| | |
| --- | --- |
| **Purpose** | Time-sensitive feedback (saved, sent, failed) |
| **Behaviour** | Non-blocking toasts for success; persistent for errors until dismissed or fixed |
| **Accessibility** | Live region polite for success; assertive for errors |
| **Responsive** | Top or bottom safe area; do not cover sticky primary CTA |
| **Drupal mapping** | `mel_notifications` library; messenger integration |

---

## 16. Skeleton loading

| | |
| --- | --- |
| **Purpose** | Indicate structure while data loads |
| **Behaviour** | Mirrors final layout; no fake numbers that look real |
| **Accessibility** | `aria-busy` on container; text alternative “Loading” |
| **Responsive** | Same breakpoints as final content |
| **Drupal mapping** | Twig placeholders + CSS; avoid layout shift |

---

## 17. Empty states

| | |
| --- | --- |
| **Purpose** | Explain absence and offer the next step |
| **Behaviour** | Honest reason + primary CTA; optional secondary learn-more |
| **Accessibility** | Not image-only; heading + text |
| **Responsive** | Compact; CTA full width on mobile |
| **Drupal mapping** | `_empty-states.scss` + Twig empties per surface |

---

## 18. Progress indicators

| | |
| --- | --- |
| **Purpose** | Show setup/publish readiness or multi-step flows |
| **Behaviour** | Steps named in organiser language; current step clear |
| **Accessibility** | `aria-current`; percentage text if used |
| **Responsive** | Collapse step labels to current + count on mobile |
| **Drupal mapping** | Readiness providers / wizard step UIs |

---

## 19. Success panels

| | |
| --- | --- |
| **Purpose** | Confirm completion and route to the next useful action |
| **Behaviour** | Celebrate briefly; always include next step (View event, Share, Door Mode) |
| **Accessibility** | Status role; focus moves to heading or primary CTA |
| **Responsive** | Reading width |
| **Drupal mapping** | Confirmation Twig after publish / send / connect flows |

---

## 20. Help panels

| | |
| --- | --- |
| **Purpose** | Contextual guidance beside the task |
| **Behaviour** | Short, organiser audience only; deep links to Help Centre articles |
| **Accessibility** | Expand/collapse buttons labelled; content readable without hover |
| **Responsive** | Stack below content on mobile; drawer optional |
| **Drupal mapping** | `sidebar_help` region; support panel / help assistant components; enforce audience boundaries |

---

## Composition rules

- Prefer **Task List + Metric Cards** on Dashboard over new “widget” types ([12](12-dashboard-philosophy.md)).
- Prefer **Table + Drawer detail** over navigating away for lightweight inspection.
- Do not add a component that duplicates an existing MEL pattern under a new name ([DDR-004](decisions/DDR-004-component-philosophy.md)).
- Avoid nested cards and triple primaries ([19](19-anti-patterns.md)).

---

## Design implications

- Component additions require: purpose, a11y notes, layout intent, and Drupal mapping stubs in this file
- Token values defer to [11](11-design-tokens.md)

## Future considerations

- Visual QA against light theme until Phase 12 dark mode tokens exist
- AI assistant panel components parked ([20](20-vendor-studio-v2-vision.md))

## Related references

- [03](03-layout-system.md) · [07](07-interaction-guidelines.md) · [09](09-drupal-mapping.md) · [11](11-design-tokens.md) · [DDR-004](decisions/DDR-004-component-philosophy.md) · [19](19-anti-patterns.md)
