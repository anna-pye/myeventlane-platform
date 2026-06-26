# Customer Acceptance — Priority Matrix

Impact (launch/customer harm if unaddressed) × Effort (to close). IDs map to
`customer-backlog.md`.

## Impact × Effort grid

```
            LOW EFFORT                 MED EFFORT                 HIGH EFFORT
          +--------------------------+--------------------------+--------------------------+
 HIGH     | CB-06 /calendar reach    | CB-01 paid-state bind     | CB-02 refund guards      |
 IMPACT   | CB-13 home skip-link     | CB-04 canonical author    | CB-03 payout/settlement  |
          | CB-05 saved-events       | CB-10 waitlist confirm    | CB-07 WCAG AA critical   |
          | decision                 | CB-11 mobile book CTA     | CB-08 email set+deliver  |
          |                          | CB-09 Pro entitlement     |                          |
          |                          | CB-12 publish/review      |                          |
          +--------------------------+--------------------------+--------------------------+
 MED      | CB-15 dir canonical      | CB-14 branded empties     | CB-16 analytics canon    |
 IMPACT   | CB-18 auth shell parity  | CB-19 empty states        | CB-17 check-in canon     |
          | CB-23 home trust anchor  | CB-20 onboard progress    |                          |
          |                          | CB-21 home copy pass      |                          |
          +--------------------------+--------------------------+--------------------------+
 LOW      | CB-24 profile badge      |                          | CB-22 dashboard refactor |
 IMPACT   |                          |                          |                          |
          +--------------------------+--------------------------+--------------------------+
```

## Sequenced plan

### Wave 0 — Blockers (do first, before any go decision)
- **CB-01** paid-state binding (med)
- **CB-02** refund guards (high)
- **CB-03** payout/settlement + webhook signature (high)

> Rationale: all three are money/trust correctness. A single incorrect paid/refund/payout
> at launch is a customer-trust and potentially financial-liability event.

### Wave 1 — Pre-launch must-fix (high impact, mostly low/med effort)
- **CB-06** /calendar (low) — remove dead end or confirm it works
- **CB-13** home skip-link (low)
- **CB-05** Saved Events decision (low — product call)
- **CB-04** canonical authoring path (med)
- **CB-10** waitlist confirmation (med)
- **CB-11** mobile booking CTA (med)
- **CB-09** Pro entitlement gating (med)
- **CB-12** publish/review consistency (med)
- **CB-07** WCAG AA on critical path (high)
- **CB-08** transactional email coverage (high)

### Wave 2 — Fast-follow (first 2 weeks post-launch)
- CB-14, CB-15, CB-16, CB-17, CB-18, CB-19, CB-20, CB-21

### Wave 3 — Polish / debt
- CB-22, CB-23, CB-24

## Quick wins (high impact / low effort — schedule immediately)
- CB-06 (/calendar), CB-13 (skip-link), CB-05 (Saved Events decision), CB-15 (dir canonical).
