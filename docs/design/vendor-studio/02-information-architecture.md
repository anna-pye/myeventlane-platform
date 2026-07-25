# Vendor Studio — Information Architecture

**Version:** RC1.1  
**Status:** Design authority (documentation only)

## Purpose

Define **navigation philosophy** and the **workspace hierarchy** for Vendor Studio — the authoritative home for global IA.

## Scope

Shell navigation, hierarchy order, current vs future IA, object relationships. Does not redefine field-level object models in VX2 IA packs. Event Workspace section philosophy depth: [13](13-event-workspace-philosophy.md). Decision record: [DDR-001](decisions/DDR-001-shell-navigation.md), [DDR-002](decisions/DDR-002-event-workspace.md).

## Audience

Product, design, nav implementers, Technical Authority.

## Related documents

- [01-vendor-studio-vision.md](01-vendor-studio-vision.md)
- [13-event-workspace-philosophy.md](13-event-workspace-philosophy.md)
- [DDR-001](decisions/DDR-001-shell-navigation.md) · [DDR-002](decisions/DDR-002-event-workspace.md)
- [`docs/vendor-experience-convergence-information-architecture.md`](../../vendor-experience-convergence-information-architecture.md)
- [`docs/vendor-experience-convergence-navigation.md`](../../vendor-experience-convergence-navigation.md)
- [A01-glossary.md](appendices/A01-glossary.md)

---

## 1. Navigation philosophy

1. **One organiser navigation** — A single global shell. No parallel “Studio nav” vs “Manager nav”.
2. **Job-based labels** — Labels describe organiser jobs (Events, Payments), not Drupal concepts.
3. **≤10 top-level items** — Prefer fewer. If a tool is event-scoped, it lives inside Event Workspace.
4. **Context, not duplication** — The same capability (e.g. Orders) may exist globally and inside an event; the event view is filtered, not a second product.
5. **Create event is chrome, not a peer destination** — Persistent primary action in the header; not a competing sidebar item named “Event Editor”.
6. **URL namespace may say `/vendor/*`** — Visible copy says **Organiser**. Machine paths stay stable for continuity.

### What never appears in the shell

Commerce · Content · Stores · Media · Taxonomy · Configuration · Product variations · Entity references · Staff diagnostics

---

## 2. Workspace hierarchy

Vendor Studio has two levels of place. Hierarchy is **context depth**, not a forced click path every session.

```text
Vendor Studio (global shell)
│
├── Dashboard          ← attention home
├── Events             ← catalogue of events
│     └── Workspace    ← per-event application (entered from Events)
├── Orders             ← cross-event sales
├── Attendees          ← cross-event guest work
├── Messages           ← brand, templates, history (+ event-scoped send)
├── Payments           ← Stripe, payouts, refunds, tax
├── Analytics          ← business pulse
├── Marketing          ← Boost, share, growth hubs
├── Settings           ← organiser defaults
└── Support            ← help + escalations
```

### Why this order

| Position | Area | Why here |
| --- | --- | --- |
| 1 | Dashboard | Answers Golden Rule first: what needs me now |
| 2 | Events | Events are the product unit; everything else serves them |
| 3 | Workspace | Not a permanent global peer — a **context entered from Events** |
| 4 | Orders | Money trail after events exist; global for multi-event organisers |
| 5 | Attendees | Guest operations often span events; Door Mode remains event-primary |
| 6 | Messages | Communication tools after people and sales exist |
| 7 | Payments | Account-level money; high trust, lower daily frequency than orders |
| 8 | Analytics | Reflection after operational basics work |
| 9 | Marketing | Growth after the event can be found and sold |
| 10 | Settings | Infrequent configuration |
| 11 | Support | Always available; visually separated (footer of nav) |

**Workspace is contextual, not a sidebar twin of Events** — see [DDR-002](decisions/DDR-002-event-workspace.md).

**Orders before Attendees in global nav:** Cross-event sales reconciliation is a primary business job; guest list work is often event-scoped (Door Mode). Global Attendees remains for search across events. (Event Workspace still leads with Attendees for live ops.)

---

## 3. Current IA (runtime evidence)

Authority for convergence mapping: VX2 navigation blueprint and `VendorNavBuilder` → vendor theme sidebar.

**Approximate current shell grouping:**

```text
Home / Dashboard
Events (+ Event Editor as competing entry)
Operations-weighted items (Orders, Ticket holders, Check-in, Refund requests, Payouts)
Growth (Grow / Boost, Messaging brand-skewed, Insights)
Account (Support, Organiser settings)
```

### Problems this OS addresses

| Problem | Why it fails the Golden Rule |
| --- | --- |
| Event Editor competes with Events | Two doors to the same house |
| Check-in / refunds as global peers | Inflates shell; hides that they are event- or money-scoped |
| Insights vs Analytics naming | Label mismatch creates doubt |
| Messaging points at brand, not send | Wrong job at the top |
| No Payments hub | Money scattered across payouts / Stripe / refunds |
| Studio vs Manager mental model | Forces organisers to learn MEL’s internal history |

Current IA is **not** the target. New work must converge toward Future IA, not extend Current IA.

---

## 4. Future IA

### 4.1 Global organiser workspace

| Nav item | Job | Preferred path pattern |
| --- | --- | --- |
| Dashboard | What needs attention now | `/vendor/dashboard` |
| Events | Create and open events | `/vendor/events` |
| Orders | Cross-event sales | `/vendor/orders` |
| Attendees | Cross-event guest work | `/vendor/attendees` |
| Messages | Brand, templates, history | `/vendor/messages` |
| Payments | Stripe, payouts, refunds, tax | `/vendor/payments` |
| Analytics | Business pulse | `/vendor/analytics` |
| Marketing | Boost, share, growth | `/vendor/marketing` |
| Settings | Profile, venues, defaults | `/vendor/settings` |
| Support | Help + escalations | `/vendor/support` |

### 4.2 Event Workspace (per-event application)

Entered from Events / Dashboard. One shell for builder and operations.

```text
Overview → Details → Schedule → Venue → Images → Tickets
→ Orders → Attendees → Messages → Marketing → Analytics
→ Publishing → Settings
```

| Rule | Why |
| --- | --- |
| No Studio / Manager duplicate nav | Context switch is Global ↔ Event only |
| Door Mode under Attendees | Check-in is a mode of guest ops, not a global product |
| Advanced ticket tools nested under Tickets | Progressive disclosure |
| Merch, Series, Diagnostics deferred from top-level | Until job frequency justifies shell weight |

Canonical event paths: `/vendor/events/{id}` and `/vendor/events/{id}/{section}`. Plural `/vendor/events/` is canonical; singular legacy paths redirect.

**Depth authority for Workspace philosophy:** [13-event-workspace-philosophy.md](13-event-workspace-philosophy.md).

### 4.3 Object relationships (organiser mental model)

```text
Organiser
  └── Events[]
        ├── Tickets[]
        ├── Attendees[]  (orders + RSVPs + waitlist + comps)
        ├── Orders[]
        ├── Messages[]
        ├── Marketing
        └── Analytics
  ├── Payments (account)
  ├── Settings (defaults)
  └── Support
```

---

## 5. Decision log (summary)

Full DDRs supersede this summary when present.

| Decision | Reason | DDR |
| --- | --- | --- |
| Keep `/vendor/*` URLs | Continuity; copy says Organiser | [DDR-001](decisions/DDR-001-shell-navigation.md) |
| Workspace not permanent global nav | Avoids empty context and dual products | [DDR-002](decisions/DDR-002-event-workspace.md) |
| Payments hub over scattered money links | Trust and findability | [DDR-006](decisions/DDR-006-payments-hub.md) |
| Marketing separate from Analytics | Different jobs: grow vs understand | [DDR-007](decisions/DDR-007-marketing-analytics-separation.md) |
| Support last + separated | Always reachable; not an operational peer of Events | (IA rule in this doc; promote to DDR if contested) |

---

## Design implications

- Nav PRs cite this document and DDR-001/002
- New global items require Product Owner justification against ≤10 rule
- Access remains workspace-ownership based; IA never implies wider visibility than access allows

## Future considerations

- Small-screen collapse may nest Orders under Events and Marketing under Analytics **only if** research shows overload; product default remains the full list
- Staff-only diagnostics never join organiser IA
- URL rename to `/organiser/*` is parked ([A03](appendices/A03-future-ideas-parking-lot.md))

## Related references

- [01](01-vendor-studio-vision.md) · [13](13-event-workspace-philosophy.md) · [09](09-drupal-mapping.md) · [DDR-001](decisions/DDR-001-shell-navigation.md) · [DDR-002](decisions/DDR-002-event-workspace.md) · [A01](appendices/A01-glossary.md) · [19](19-anti-patterns.md)
