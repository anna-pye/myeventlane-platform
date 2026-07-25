# DDR-007 — Marketing separate from Analytics

**Status:** Accepted  
**Date:** 2026-07-25  
**Pack version:** RC1.1  
**Owners:** Design Authority · Product Owner

---

## Decision

**Marketing** (Boost, share, growth actions) and **Analytics** (understand performance) are separate global hubs with separate jobs. They must not be merged into a single “Insights/Grow” chimera.

---

## Problem

Collapsing growth actions and reporting into one nav item forces organisers to learn MEL’s internal naming (e.g. Insights vs Analytics) and buries spend decisions inside charts — or buries truth inside promotional chrome.

---

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| Single “Insights” hub for both | Job confusion; naming debt |
| Marketing only inside Event Workspace | Cross-event growth tools still need a global home; event-scoped marketing remains in Workspace |
| Analytics under Dashboard only | Dilutes Dashboard Action Queue; reporting needs a dedicated pulse |
| Competitor-parity mega growth suite | Scope and FOMO risk; violates MEL brand |

---

## Reason

- Product clarity: **grow** vs **understand**  
- Dashboard stays attention-led ([12](../12-dashboard-philosophy.md))  
- Marketing uses Wide layout intent; Analytics uses honest KPIs/charts ([11](../11-design-tokens.md), [06](../06-workspace-patterns.md))  
- Avoids decorative metrics dressed as growth ([19](../19-anti-patterns.md))  

---

## Consequences

- Global nav includes both Marketing and Analytics  
- Event Workspace retains event-scoped Marketing and Analytics sections  
- Labels prefer Analytics over ambiguous Insights in future IA  
- Small-screen nesting only with research ([02](../02-information-architecture.md) future notes)  

---

## Future review triggers

- Research-proven need to nest Marketing under Analytics on small screens  
- New growth products that change job frequency enough to warrant IA redesign (requires new DDR)  
