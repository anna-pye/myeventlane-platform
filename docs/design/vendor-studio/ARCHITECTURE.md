# Vendor Studio — Product Architecture

**Product Design System (PDS) v1.0 — FROZEN**  
**Also known as:** Design Operating System

## Purpose

Provide a **visual overview** of Vendor Studio — how the Design Operating System layers relate, and how the product is structured for organisers.

## Scope

ASCII architecture diagrams and short explanations. Detail lives in linked authoritative documents. Not a Drupal module dependency graph.

## Audience

New contributors, product and engineering leads, reviewers.

## Related documents

- [01-vendor-studio-vision.md](01-vendor-studio-vision.md)
- [02-information-architecture.md](02-information-architecture.md)
- [INDEX.md](INDEX.md)
- [ADR-0001](decisions/ADR-0001-design-authority.md)

---

## Why diagrams

A contributor should see the whole system in one page before reading forty documents. Diagrams encode precedence and product shape without replacing the owning specs.

---

## 1. Design Operating System stack

```text
Vision (01) + Constitution (ADR-0001)
        ↓
Information Architecture (02)
        ↓
Layout System (03)
        ↓
Design Tokens (11)
        ↓
Components (05)  ←  Design Language (04) · Visual Identity (14) · Copy (15)
        ↓
Patterns (06)  ←  Dashboard (12) · Event Workspace (13)
        ↓
Interaction (07) · Mobile (08)
        ↓
Drupal Mapping (09)
        ↓
Implementation (theme + modules — outside this pack)
        ↓
Runtime (.mel-vendor console)
```

**Roadmap (10)** sequences implementation. **v2 / parking (20, A03)** sit beside the stack — not inside current delivery.

Governance wraps the stack: Lifecycle (23) · DoD (21) · Checklist (16) · Health (22).

---

## 2. Document precedence (constitutional)

```text
ADR-0001
  → Mission / Vision / Principles
    → Information Architecture
      → Layout System
        → Design Tokens
          → Component Library
            → Workspace Patterns
              → Implementation Mapping
                → Roadmap
```

No implementation may contradict a higher layer. Full rules: [ADR-0001](decisions/ADR-0001-design-authority.md).

---

## 3. Organiser product shape (global)

```text
Global Vendor Studio (shell)
│
├── Dashboard          Attention home
├── Events             Catalogue → enter Workspace
│     └── Event Workspace   Per-event application
├── Orders             Cross-event sales
├── Attendees          Cross-event guest work
├── Messages           Brand · templates · history · send
├── Payments           Stripe · payouts · refunds · tax
├── Analytics          Business pulse
├── Marketing          Boost · share · growth
├── Settings           Organiser defaults
└── Support            Help · escalations
```

Authoritative IA: [02-information-architecture.md](02-information-architecture.md).

---

## 4. Context switch

```text
┌─────────────────────┐         ┌──────────────────────────┐
│  Global Studio      │  open   │  Event Workspace         │
│  Dashboard, Events, │ ──────► │  Overview … Settings     │
│  Orders, Payments…  │ ◄────── │  (this event only)       │
└─────────────────────┘  leave  └──────────────────────────┘
```

Only one context switch: **Global ↔ Event**. Never Studio vs Manager.

---

## 5. Event Workspace sections

```text
Overview → Details → Schedule → Venue → Images → Tickets
→ Orders → Attendees (incl. Door Mode) → Messages → Marketing
→ Analytics → Publishing → Settings
```

Philosophy: [13-event-workspace-philosophy.md](13-event-workspace-philosophy.md).

---

## 6. Runtime mapping (summary)

```text
Design concept          Runtime home (typical)
─────────────────────   ─────────────────────────────────
Shell / nav             myeventlane_vendor_theme + VendorNavBuilder
Layout intents          .mel-layout--* under .mel-vendor
Components              vendor theme partials / .mel-* 
Action Queue            builders in myeventlane_vendor*
Event Workspace         vendor + event studio related modules
Orders / payments       Commerce entities (truth) + vendor UI
```

Confirm in repository at implementation time: [09-drupal-mapping.md](09-drupal-mapping.md).

---

## Design implications

- New features must declare where they sit in diagrams 1 and 3  
- If it does not fit, that is an IA/DDR question — not a one-off page  

## Future considerations

- Add a module dependency diagram only when Technical Authority needs it for onboarding  
- Keep ASCII stable; avoid tool-specific diagram formats as source of truth  

## Related references

- [02](02-information-architecture.md) · [09](09-drupal-mapping.md) · [ADR-0001](decisions/ADR-0001-design-authority.md) · [INDEX.md](INDEX.md)
