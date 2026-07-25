# Implementation Checklist — Vendor Dashboard v2 Foundation

**Note:** Canonical `IMPLEMENTATION_CHECKLIST.md` / `IMPLEMENTATION_WORKFLOW.md` are **missing** from the frozen PDS pack. This checklist is the pre-merge gate for this PR.

| # | Gate | Status | Notes |
| --- | --- | --- | --- |
| 1 | Design package reviewed (`00`–`06`, `07`–`08`) | YES | Discovery + Slice reviews present |
| 2 | PDS citations prepared | YES | [14](14-pds-references.md) |
| 3 | No Studio/Manager fork | YES | Single shell |
| 4 | Extends existing components only | YES | Action cards, empty state, skeleton, buttons |
| 5 | No parallel dashboard architecture | YES | Same theme hook / builders |
| 6 | No config/sync changes | YES | |
| 7 | No new routes / permissions | YES | |
| 8 | Access unchanged (server-side) | YES | Door Mode via existing access-checked URLs |
| 9 | Cache contexts considered | YES | `user`, `user.roles`, `timezone` |
| 10 | Cache tags considered | YES | node/commerce/vendor tags |
| 11 | Finite max-age for relative copy | YES | 300s (0 for Pro welcome) |
| 12 | No fake metrics in loading UI | YES | Skeleton shapes only |
| 13 | Empty queue calm state | YES | “You're all caught up.” |
| 14 | ≤4 KPIs, no duplicate strips | YES | `model.kpis` only on first paint |
| 15 | Tools / Pro below attention path | YES | |
| 16 | Australian English copy | YES | |
| 17 | Mobile-first SCSS | YES | Grid collapses; 44px CTAs |
| 18 | Lint / build run | YES* | Re-run after hardening before commit |
| 19 | Drush cache rebuild | YES* | Re-run before commit |
| 20 | DDR required? | NO | Alignment fixes only |
| 21 | Design Authority sign-off | OPEN | Human |
| 22 | Commit created | NO | Stopped per instruction |

\*Prior session validated; re-validate after merge-prep hardening.

---

## Residual (accepted for merge with follow-up)

| Item | Severity | Follow-up |
| --- | --- | --- |
| Controller vs view-model dual event load | P2 | Slice 3 / perf |
| Cache tags may miss some membership-resolved nids | P2 | Align tag source to view model |
| Daily Brief overnight uses PHP `strtotime('today')` | P2 | Prefer user timezone explicitly |
| Skeleton progressive JS not wired | P2 | Optional; SSR honest |
| ADR-0002 file missing in PDS pack | Governance | Add via DDR/docs PR |
| PDS workflow templates missing | Governance | Add via docs PR |
