# DDR-001 — Single global shell navigation

**Status:** Accepted  
**Date:** 2026-07-25  
**Version:** RC1  
**Owners:** Design Authority · Product Owner · Technical Authority

---

## Decision

Vendor Studio uses **one global organiser shell** with a single job-based navigation. There is no parallel “Studio nav” vs “Manager nav”. Visible copy says **Organiser**; URL namespace may remain `/vendor/*`.

---

## Problem

Historical MEL surfaces taught organisers two mental models for one business. Duplicate entries (e.g. Event Editor vs Events) and ops tools promoted to global peers inflated the shell and failed the Golden Rule.

---

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| Dual products (Studio + Manager) | Permanent cognitive tax; duplicate IA |
| CMS-style toolbar of Drupal tools | Exposes platform complexity |
| Flat mega-list of every feature | Exceeds ≤10 top-level guidance; poor mobile |
| Rename URLs immediately to `/organiser/*` | Breaks continuity; copy change suffices for v1 |

---

## Reason

- Better organiser experience: one place to learn  
- Product clarity: jobs not modules  
- Mobile usability: fewer top-level destinations  
- Maintainability: one nav builder and theme shell  
- Drupal-aware: paths can stay stable while UX converges  

Authoritative detail: [02-information-architecture.md](../02-information-architecture.md).

---

## Consequences

- New features must justify global nav weight or nest in Event Workspace / hubs  
- Convergence work retires competing entries rather than adding “temporary” twins  
- Support and Settings stay visually separated at the end of nav  

---

## Future review triggers

- Research shows small-screen overload requiring nested hubs  
- Product Owner approves `/organiser/*` URL migration  
- Team collaboration requires a new top-level “Team” job with sustained frequency  
