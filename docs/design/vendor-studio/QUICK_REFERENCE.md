# Vendor Studio — Quick Reference

**Product Design System (PDS) v1.0 — FROZEN**  
**Audience:** Designers and developers implementing Vendor Studio  
**Authority:** Summaries only — full ownership in linked docs  
**Landing:** [INDEX.md](INDEX.md) · **Done:** [21-definition-of-done.md](21-definition-of-done.md)  
**Max length:** Keep to two pages when printed

---

## Ten Design Principles

Full text and rationale: [01-vendor-studio-vision.md](01-vendor-studio-vision.md)

1. One primary action  
2. Guide, don’t overwhelm  
3. Always show the next step  
4. Always explain why  
5. Hide platform complexity  
6. Celebrate progress  
7. Mobile-capable operations  
8. Accessible by default  
9. Cards earn their size  
10. Consistency over novelty  

---

## Three Question Framework

Every screen, in order:

1. **Where am I?**  
2. **What needs me?**  
3. **What is the next useful action?**  

---

## Golden Rule

> If the organiser cannot answer “What should I do next?” within five seconds of landing on a screen, the screen has failed.

---

## Layout Intents

Shell is full width. Content chooses an intent ([03](03-layout-system.md), [11](11-design-tokens.md)):

| Intent | Max width | Use |
| --- | --- | --- |
| Form | 800px | Settings, Stripe, wizards |
| Reading | 800px | Help, readiness, next-action |
| Workspace | 1200px | Event Workspace, lists, tickets |
| Dashboard | 1280px | Organiser home, hubs |
| Wide / Marketing | 1400px | Boost grids, boards |

Gutters: 16 / 24 / 32px (mobile / tablet / desktop).

---

## Navigation Hierarchy

```text
Dashboard → Events → (Event Workspace) → Orders → Attendees
→ Messages → Payments → Analytics → Marketing → Settings → Support
```

- One global shell; Workspace is entered from Events (not a permanent sidebar twin)  
- Copy says **Organiser**; URLs may say `/vendor/*`  
- Full IA: [02-information-architecture.md](02-information-architecture.md)

---

## Component Hierarchy

Prefer in this order:

1. Action / attention (Task List, Action Cards)  
2. Status / KPIs (Metric Cards)  
3. Data board (Tables, lists)  
4. Help / guidance (quiet)

Contracts: [05-component-library.md](05-component-library.md)  
Avoid: [19-anti-patterns.md](19-anti-patterns.md)

---

## Typography Scale

| Role | Mobile | Notes |
| --- | --- | --- |
| H1 | 28px | One per view |
| H2 | 24px | Sections |
| H3 | 20px | Subsections / card titles |
| Body | 16px min | Line-height ~1.5 |
| Small / meta | 14px | Sentence case |
| Label | 12–13px | Prefer sentence case |

Full tokens: [11-design-tokens.md](11-design-tokens.md)

---

## Colour Hierarchy

1. Warm cream canvas · white surfaces  
2. Purple = **one** primary action / active nav  
3. Coral = focus / warm accent (not a second primary)  
4. Semantic: error / warning / success / info (+ text/icon, never colour alone)  
5. Discovery gold = rare; not Studio warnings  

---

## Mobile Rules

- Baseline **390px**; enhance upward  
- One primary job per viewport  
- Nav: drawer / sheet; Create event always reachable  
- Targets ≥ **44×44px**  
- Tables: card rows **or** x-scroll — one pattern per surface  
- Door Mode: max content, min chrome  

Full: [08-mobile-guidelines.md](08-mobile-guidelines.md)

---

## Accessibility Rules

- WCAG AA contrast  
- Visible focus (vendor focus token)  
- Keyboard paths for primary tasks  
- Severity = colour + text + icon  
- Labels on inputs; placeholders never replace labels  
- Honour `prefers-reduced-motion`  
- UI hiding is never security  

---

## Primary Action Rule

- **One** primary CTA per screen region  
- Desktop: page header top-right  
- Mobile: sticky when commit is required  
- Destructive actions are never styled as the sole primary without confirmation  
- Chrome primary: **Create event** (does not compete with page primary inside Workspace)

---

## Before you ship

- [21-definition-of-done.md](21-definition-of-done.md) gates  
- [16-design-review-checklist.md](16-design-review-checklist.md) detail  
- Cite the OS docs you followed in the PR ([CONTRIBUTING.md](CONTRIBUTING.md))  
- Money / publish / access: Technical Authority + risk callout  
- Precedence: [ADR-0001](decisions/ADR-0001-design-authority.md)  
