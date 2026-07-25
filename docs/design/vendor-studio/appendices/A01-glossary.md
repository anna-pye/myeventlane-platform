# Appendix A01 — Glossary

**Version:** RC1.1  
**Status:** Design authority (documentation only)

## Purpose

Define shared vocabulary for the Vendor Studio Design Operating System so every document means the same thing by the same words.

## Scope

Organiser-facing terms, MEL product terms, and translations from Drupal/Commerce. Not a full platform dictionary.

## Audience

All contributors.

## Related documents

- [15-copywriting-guide.md](../15-copywriting-guide.md)
- [02-information-architecture.md](../02-information-architecture.md)
- [README.md](../README.md)

---

## Organiser terms

| Term | Meaning |
| --- | --- |
| **Organiser** | Human who runs events on MEL. Preferred in UI. Machine/URLs may say `vendor`. |
| **Vendor Studio** | The organiser console product (global shell + Event Workspace). |
| **Dashboard** | Global attention home — what needs me now. |
| **Events** | Catalogue of the organiser’s events; gateway into Workspace. |
| **Event** | A single dated experience the organiser builds and runs. |
| **Event Workspace** | Per-event application for build + ops. |
| **Attendee** | Guest associated with an event (order, RSVP, waitlist, comp — as product defines). |
| **Order** | A purchase record organisers reconcile and may refund (Commerce-backed). |
| **Ticket** | Sellable admission option (Commerce variation backstage). |
| **Payment / payouts** | Money movement and Stripe connection states — always honesty-bound. |
| **Marketing** | Growth tools (Boost, share, embeds). |
| **Messages** | Brand, templates, history, and sending to audiences. |
| **Door Mode** | High-speed check-in mode under Attendees. |
| **Readiness** | Honest publish/capability checklist with blockers and recovery. |
| **Action Queue** | Ranked attention list on Dashboard (and similar surfaces). |
| **Publishing** | Making an event live/visible per product rules — deliberate, not accidental. |

---

## MEL terminology

| Term | Meaning |
| --- | --- |
| **MEL** | MyEventLane. |
| **Hidden Gem** | Public brand discovery idea — high-value local experiences; not VIP theatre in Studio. |
| **Guide** | Warm encouraging presence at decision points — not a mascot in console chrome. |
| **Boost** | Paid/promoted growth capability (as product defines). |
| **VX2** | Vendor Experience convergence programme docs (delivery history; OS wins on design philosophy). |

---

## Drupal terminology (backstage)

| Term | Organiser-facing translation |
| --- | --- |
| Node / content entity | Event (or specific content type name only if already organiser-facing) |
| Media | Image / file as labelled in UI |
| Taxonomy | Category / tags |
| Permission | Access (plain language) |
| Theme region | (omit in UI) |
| Config | (omit in UI) |

---

## Commerce terminology (backstage)

| Term | Organiser-facing translation |
| --- | --- |
| Store | (omit) |
| Product / variation | Ticket / ticket type |
| Order | Order |
| Order item | Line item / ticket line |
| Payment gateway | Stripe / payment method |
| Adjustment | Fee / discount (plain) |
| Checkout | Checkout (public); organisers see orders/payments aftermath |

---

## Design OS terms

| Term | Meaning |
| --- | --- |
| **Product Design System (PDS)** | Canonical name for this pack — permanent product design standard (v1.0 FROZEN). |
| **Design Operating System** | Accepted alias for the PDS (describes what Vendor Studio does for organisers). |
| **ADR** | Architecture/authority decision record; [ADR-0001](../decisions/ADR-0001-design-authority.md) is the constitution. |
| **DDR** | Design Decision Record in `decisions/`. |
| **Definition of Done** | Mandatory completion gates ([21](../21-definition-of-done.md)). |
| **Layout intent** | Named content max-width contract (Form, Reading, Workspace, Dashboard, Wide). |
| **Golden Rule** | Next-step clarity within five seconds ([01](../01-vendor-studio-vision.md)). |
| **Three Questions** | Where am I? What needs me? What’s next? |
| **Maturity level** | Functional → Predictive ladder ([17](../17-design-maturity-model.md)). |
| **Precedence** | Higher documents win when guidance conflicts ([ADR-0001](../decisions/ADR-0001-design-authority.md)). |

---

## Design implications

- New terms require an entry here before widespread use in OS docs  
- UI copy must prefer organiser column, not backstage column  

## Future considerations

- Expand with role names when team collaboration ships  
- Sync with VX2 language guide without duplicating full essays  

## Related references

- [15](../15-copywriting-guide.md) · [02](../02-information-architecture.md) · [docs/vendor-experience-convergence-language-guide.md](../../../vendor-experience-convergence-language-guide.md)
