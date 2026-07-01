# Customer Acceptance — Scorecard

Scores are out of 10 per dimension, derived from **repository evidence** (routes, controllers,
templates, access services, design-system conformance). They are **not** runtime measurements.

- **Performance**, **Mobile**, **Visual Consistency** scores are evidence proxies (presence of
  responsive shells, mobile bottom nav, lazy-loading, single token system, locked hero/card
  contracts) and must be confirmed with a live device + Lighthouse pass (see checklist).
- Cells marked `*` carry a material **VERIFY-LIVE** dependency that could lower the score.

Dimensions: **UX · Trust · A11y · Mobile · Perf · Visual · Conv** (Conversion clarity).

---

## Visitor journey

| Page / Route | UX | Trust | A11y | Mobile | Perf | Visual | Conv | Avg |
| --- | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: |
| Landing `/home` | 8 | 6 | 7* | 8 | 8 | 9 | 8 | **7.7** |
| Discovery / Browse `view.upcoming_events.*` | 8 | 7 | 9 | 8 | 8 | 9 | 8 | **8.1** |
| Search `/search` | 8 | 8 | 8 | 8 | 8 | 8 | 7 | **7.9** |
| Calendar `/calendar` | 5* | 6 | 6* | 6* | 6 | 7 | 5* | **5.9** |
| Categories `page--events--category` | 7 | 6 | 8 | 8 | 8 | 8 | 7 | **7.4** |
| Event page `node--event` | 9 | 9 | 8 | 7* | 8 | 9 | 8* | **8.3** |
| Organiser profile `/organisers`,`/vendors` | 7 | 6* | 7 | 7 | 8 | 8 | 6 | **7.0** |
| Registration `/user/register`,`/onboard/*` | 8 | 7 | 7 | 7 | 8 | 7* | 8 | **7.4** |
| Login `/user/login` | 8 | 7 | 7 | 7 | 8 | 7* | 8 | **7.4** |

## Customer journey

| Page / Route | UX | Trust | A11y | Mobile | Perf | Visual | Conv | Avg |
| --- | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: |
| Purchase/Checkout (Commerce flow) | 8 | 8 | 8 | 7* | 7 | 8 | 8* | **7.7** |
| Cart empty `commerce-cart-empty-page` | 9 | 8 | 9 | 8 | 9 | 9 | 8 | **8.6** |
| Checkout completion | 9 | 8 | 9 | 8 | 8 | 9 | 8* | **8.4** |
| RSVP `/event/{event}/rsvp` | 8 | 8 | 8 | 8 | 8 | 8 | 8 | **8.0** |
| Waitlist `/event/{node}/waitlist/*` | 6* | 7 | 7 | 7 | 8 | 7 | 6* | **6.9** |
| Emails (transactional) | 7* | 8 | 7 | 8 | 8 | 8 | 7 | **7.6** |
| My Tickets `/my-tickets` | 8 | 8 | 8 | 7 | 8 | 8 | 8 | **7.9** |
| Saved Events | — | — | — | — | — | — | — | **N/A** |
| Customer dashboard `/my-account`,`/my-events` | 7 | 7 | 7* | 7 | 8 | 8 | 7 | **7.3** |
| Refund (buyer) `/my-tickets/.../refund` | 7 | 8 | 7 | 7 | 7 | 8 | 7* | **7.3** |

> Saved Events scored **N/A** — Repository evidence not found (see CB-05). Not counted in averages.

## Organiser journey

| Page / Route | UX | Trust | A11y | Mobile | Perf | Visual | Conv | Avg |
| --- | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: |
| Onboarding `/vendor/onboard/*` | 8 | 8 | 7 | 7 | 8 | 8 | 8 | **7.7** |
| Dashboard `/vendor/dashboard` | 7 | 8 | 7 | 7 | 6 | 8 | 7 | **7.1** |
| Event Studio / build wizard | 7* | 7 | 7 | 6* | 7 | 8 | 7* | **7.0** |
| Publish / submit-for-review | 7* | 8 | 7 | 7 | 8 | 8 | 7 | **7.4** |
| Promotion / Boost wizard | 8 | 7 | 7 | 7 | 8 | 8 | 8 | **7.6** |
| Analytics / Insights | 7 | 8 | 7 | 6 | 6 | 8 | 7 | **7.0** |
| Attendees / Check-in (PWA) | 8 | 8 | 7 | 8 | 8 | 8 | 8 | **7.9** |
| Messaging / Comms | 7 | 8 | 7 | 7 | 8 | 8 | 7 | **7.4** |
| Payouts / Finance / Stripe | 7 | 8* | 7 | 7 | 7 | 8 | 7 | **7.3** |
| Pro features | 7 | 7* | 7 | 7 | 8 | 8 | 7 | **7.3** |

---

## Dimension roll-up (mean across scored pages)

| Dimension | Mean | Read |
| --- | :-: | --- |
| UX | 7.5 | Strong, consistent shells; a few unverified paths |
| Trust | 7.5 | Refund/payout/policy surfaces present; home trust thin |
| Accessibility | 7.4 | Good primitives; needs formal AA pass on critical path |
| Mobile | 7.2 | Mobile-first system; booking/checkout need device verify |
| Performance | 7.6 | Lazy-load + tokens; no measured CWV yet |
| Visual consistency | 8.0 | **Strongest area** — locked design system + parity governance |
| Conversion clarity | 7.3 | Good CTAs; authoring-path + paid-state ambiguity drag |

## Journey roll-up

| Journey | Mean page avg |
| --- | :-: |
| Visitor | **7.5** |
| Customer (excl. N/A) | **7.7** |
| Organiser | **7.4** |

---

## Overall launch-readiness score

**Repository-evidence readiness: 7.5 / 10 — "Strong, conditionally launchable."**

This is a **weighted** verdict, not a simple mean:

- The platform scores **8.0 on visual consistency** and **7.5+ on UX/Trust/Perf** — genuinely
  launch-grade build quality and design governance.
- It is **gated below "go"** by **3 unresolved P0 Commerce-correctness items** (paid-state,
  refunds, payouts) that are binary trust risks, plus path-clarity/coverage P1s.

### Gating rule applied

> No P0 may be open at go-live. With CB-01/02/03 open, **acceptance status = NOT YET CLEAR**.
> On closing the three P0s (pass) the effective readiness rises to **~8.2 / 10 = GO with P1 fast-follow**.

| Condition | Readiness | Verdict |
| --- | :-: | --- |
| As-is (P0s open, unverified) | 7.5 | **Conditional / NOT YET CLEAR** |
| P0s verified-pass | 8.2 | **GO** (P1s as fast-follow) |
| P0 verified-fail (any) | ≤5.0 | **NO-GO** until fixed |

See `customer-launch-checklist.md` for the go/no-go gate.
