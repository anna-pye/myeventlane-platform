# Vendor Studio Design Operating System — Vision

**Version:** RC1  
**Status:** Design authority (documentation only)  
**Language:** Australian English · Organiser (human) · `vendor` (machine / URLs)

## Purpose

State the mission, product philosophy, Ten Design Principles, Golden Rule, Three Question Framework, and personality that govern every Vendor Studio screen.

## Scope

Foundational product design authority. Does **not** own numeric tokens ([11](11-design-tokens.md)), Dashboard composition depth ([12](12-dashboard-philosophy.md)), Event Workspace section spec ([13](13-event-workspace-philosophy.md)), or metric definitions ([18](18-product-success-metrics.md)).

## Audience

Product, design, theme, and Drupal contributors — especially new contributors (read after the [poster](DESIGN_PRINCIPLES_POSTER.md)).

## Related documents

- [DESIGN_PRINCIPLES_POSTER.md](DESIGN_PRINCIPLES_POSTER.md) · [QUICK_REFERENCE.md](QUICK_REFERENCE.md)
- [02-information-architecture.md](02-information-architecture.md)
- [14-visual-identity.md](14-visual-identity.md) · [15-copywriting-guide.md](15-copywriting-guide.md)
- [18-product-success-metrics.md](18-product-success-metrics.md)
- [`docs/vendor-experience-convergence.md`](../../vendor-experience-convergence.md)
- [README.md](README.md)

---

## 1. Mission

Vendor Studio is the organiser’s operating system for creating, running, and growing events on MyEventLane.

It exists so organisers can focus on:

- their event
- their attendees
- their tickets
- their revenue

It must never force them to think about Drupal, Commerce stores, products, variations, media entities, taxonomy, or paragraphs. Those remain implementation details.

**Mission statement:** Help organisers create successful events with calm confidence — from first draft to door check-in to payout.

---

## 2. Product philosophy

Vendor Studio is a **tool for operators**, not a marketing site and not a CMS admin.

| Belief | Consequence |
| --- | --- |
| The event is the centre of gravity | Global tools exist to support events; events do not exist to fill a dashboard |
| Attention is scarce | Surfaces lead with what needs action, not with decorative density |
| Trust is earned through clarity | Money, capacity, and publish states are explicit and recoverable |
| Complexity belongs backstage | Progressive disclosure; advanced tools appear when needed |
| MEL is warm and local | Tone stays community-focused; never exclusive, VIP, or FOMO-driven |

Vendor Studio shares MEL’s public brand soul (Hidden Gem + Guide) but serves a different job: **reliable operations**. Warmth never replaces precision when money, access, or publishing is involved.

Visual feel: [14-visual-identity.md](14-visual-identity.md).

---

## 3. Design principles

**Authoritative home for the Ten Design Principles.** Other documents cite; they do not restate the full list.

These principles govern every future screen. If a proposal violates them, it does not ship.

1. **One primary action** — Each screen (and each mobile viewport) has one obvious next step. Secondary actions stay visually subordinate.
2. **Guide, don’t overwhelm** — Progressive disclosure, readiness cues, and next-step CTAs. The Guide presence is encouraging, never a mascot occupying chrome.
3. **Always show the next step** — Empty, error, blocked, and success states all answer “what now?”
4. **Always explain why** — Blocks include reason and recovery. Never a dead end.
5. **Hide platform complexity** — Commerce and CMS vocabulary stay out of organiser copy.
6. **Celebrate progress** — Milestones feel earned; never gamified pressure.
7. **Mobile-capable operations** — Door Mode, sales monitoring, and urgent actions work on a phone.
8. **Accessible by default** — WCAG AA contrast, visible focus, keyboard paths, severity not colour-only, `prefers-reduced-motion` respected.
9. **Cards earn their size** — If removing a border, shadow, or radius does not hurt understanding, it should not be a card.
10. **Consistency over novelty** — Reuse shell, layout intents, and components before inventing new patterns.

Anti-pattern counterparts: [19-anti-patterns.md](19-anti-patterns.md).

---

## 4. Emotional journey

Organisers move through recurring emotional states. Design must match the state, not fight it.

```text
Curiosity          →  “Can I run my event here?”
Commitment         →  “I’ve started; don’t make me regret it.”
Setup pressure     →  “What am I missing before publish?”
Launch adrenaline  →  “Is it live? Are tickets selling?”
Operational focus  →  “Who’s coming? What’s broken?”
Door stress        →  “I need speed and certainty.”
Aftermath clarity  →  “What happened? What do I owe / earn?”
Growth ambition    →  “How do I do better next time?”
```

| State | Design response |
| --- | --- |
| Curiosity / commitment | Short paths, plain language, visible progress |
| Setup pressure | Readiness + next action, not a wall of fields |
| Launch / ops | Live status, action queue, calm density |
| Door stress | Large targets, offline-tolerant patterns, minimal chrome |
| Aftermath / growth | Clear numbers, exportable truth, non-judgemental insights |

---

## 5. Experience principles

Operational rules that sit under the design principles:

| Experience principle | Why |
| --- | --- |
| **Global ↔ Event context switch** | Organisers manage a business and a single event; they do not manage “Studio vs Manager” |
| **Action before content vanity** | Dashboards lead with attention, not hero theatre |
| **Readiness is honest** | Publish/block states reflect real capability, not cosmetic green ticks |
| **Money surfaces are sober** | Payments and refunds use precise language and irreversible-action patterns |
| **Help is contextual** | Support appears beside the task; staff-only content never leaks to organisers |
| **Australian English** | Consumer- and organiser-facing copy follows brand + [15](15-copywriting-guide.md) |

---

## 6. Golden Rule

> **If the organiser cannot answer “What should I do next?” within five seconds of landing on a screen, the screen has failed.**

Everything else — visual polish, density, charts, secondary tools — is subordinate to that answer.

---

## 7. Three Questions Framework

Every Vendor Studio screen must answer three questions, in this order:

1. **Where am I?** — Global organiser context or which event / section.
2. **What needs me?** — Attention, blockers, live status, or empty calm.
3. **What is the next useful action?** — One primary CTA; secondary paths available but quiet.

Use this framework in critiques, acceptance criteria, and pattern reviews. Screens that only answer “here is data” are incomplete.

---

## 8. Product personality

| Trait | Sounds like | Does not sound like |
| --- | --- | --- |
| Warm | “You’re almost ready to publish.” | “Incomplete configuration detected.” |
| Capable | “Refund this order” with clear consequence | Softening irreversible money actions |
| Local / community | “Nearby”, “community”, “discover” on public MEL | “VIP”, “exclusive”, “members only” in Studio |
| Calm | Steady hierarchy, generous whitespace | Dashboard panic, badge spam |
| Honest | “Payouts unavailable until Stripe is connected — here’s why.” | Fake readiness |

Personality is carried by **copy, hierarchy, and motion restraint** — not by decorative illustration in the shell. Copy authority: [15](15-copywriting-guide.md).

---

## 9. Success measures (summary)

Success is behavioural and operational, not aesthetic.

**Authoritative metric definitions:** [18-product-success-metrics.md](18-product-success-metrics.md).

| Theme | Signal of success |
| --- | --- |
| Time to value | First publish, Stripe, ticket, booking without staff rescue |
| Action clarity | Top Action Queue item resolved without hunting |
| Door Mode | Check-in under time pressure on mobile |
| Trust in money | Refund/payout confusion declines; states match reality |
| Consistency | New screens reuse shell, intents, components |

**Non-goals for this design system:** visual novelty for its own sake; feature parity with every competitor chrome detail; dark mode as a brand statement before operational completeness ([10](10-roadmap.md) Phase 12).

---

## Design implications

- Every Vendor Studio PR must be justifiable against these principles and the Golden Rule
- Foundational changes to principles require Design Authority review and CHANGELOG entry
- Visual/token disputes defer to [11](11-design-tokens.md); IA disputes to [02](02-information-architecture.md)

## Future considerations

- Event Workspace and Vendor Studio remain one product with two contexts ([DDR-002](decisions/DDR-002-event-workspace.md))
- Public MEL brand docs remain authority for consumer discovery; Studio extends for operations
- When vision and a locked runtime contract conflict, document the conflict; do not silently override checkout, payments, or access rules
- Level 4–5 ambition lives in [20](20-vendor-studio-v2-vision.md), not in silent principle edits

## Related references

- [README.md](README.md) · [DESIGN_PRINCIPLES_POSTER.md](DESIGN_PRINCIPLES_POSTER.md) · [02](02-information-architecture.md) · [14](14-visual-identity.md) · [15](15-copywriting-guide.md) · [18](18-product-success-metrics.md) · [19](19-anti-patterns.md) · [DDR-001](decisions/DDR-001-shell-navigation.md)
