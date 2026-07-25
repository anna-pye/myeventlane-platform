# DDR-006 — Payments hub

**Status:** Accepted  
**Date:** 2026-07-25  
**Pack version:** RC1.1  
**Owners:** Design Authority · Product Owner · Technical Authority

---

## Decision

Vendor Studio provides a single global **Payments** hub for Stripe connection, payouts, refunds entry points, and tax-related organiser tasks — rather than scattering money links across the shell.

---

## Problem

When payouts, Stripe, and refunds appear as disconnected nav items, organisers cannot answer “Where is money?” in five seconds. Trust erodes; support tickets rise; Commerce reality feels fragmented.

---

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| Keep Payouts / Stripe / Refunds as separate global peers | Inflates nav; duplicates money anxiety |
| Bury all money under Settings | Hides high-trust jobs; fails findability |
| Put all refunds only under Orders | Misses account-level Stripe/payout readiness |
| Expose Commerce payment gateway UI vocabulary | Violates hide-complexity principle |

---

## Reason

- Organiser experience: one money home  
- Product clarity: Orders = records; Payments = account money apparatus  
- Commerce-aware: UI hubs must not invent state; deep links to truthful flows  
- IA weight: high trust, moderate frequency → hub after Messages, before Analytics  

Authoritative IA: [02-information-architecture.md](../02-information-architecture.md).

---

## Consequences

- Shell shows **Payments**, not a cluster of money synonyms  
- Refund *actions* remain deliberate from Orders; Payments hub provides orientation + account setup  
- Copy stays sober ([15](../15-copywriting-guide.md)); no playful money ([19](../19-anti-patterns.md))  
- Implementation must not weaken Stripe Connect / access rules  

---

## Future review triggers

- Tax product expansion requiring IA weight change  
- Evidence that refunds need a top-level peer again (unlikely — prefer Orders + Payments)  
- Multi-entity payout structures for teams/collaboration  
