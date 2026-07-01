# Organiser Task Scorecard

Scores /10 from repository evidence + authenticated runtime probes. Dimensions:
**TC** Task completion · **EU** Ease of use · **TR** Trust · **A11y** · **Mob** Mobile ·
**Spd** Speed · **VC** Visual consistency · **BV** Business value · **Conf** Overall confidence.

`*` = a dimension carries an **Unable to verify** dependency (on-device / screen-reader / contrast)
that could move the score after a live a11y/mobile pass.

| Task | TC | EU | TR | A11y | Mob | Spd | VC | BV | Conf | Avg |
| --- | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: |
| Complete onboarding | 9 | 8 | 9 | 7* | 7* | 8 | 9 | 9 | 8 | **8.2** |
| Resume incomplete onboarding | 9 | 8 | 8 | 7* | 7* | 8 | 9 | 8 | 8 | **8.0** |
| Connect Stripe | 9 | 8 | 9 | 7* | 7* | 8 | 8 | 10 | 9 | **8.3** |
| Create first event | 9 | 8 | 8 | 7* | 6* | 8 | 9 | 9 | 8 | **8.0** |
| Edit event | 9 | 8 | 8 | 7* | 6* | 8 | 9 | 8 | 8 | **7.9** |
| Publish / submit for review | 9 | 8 | 8 | 7* | 7* | 8 | 9 | 8 | 8 | **8.0** |
| Unpublish event | 8 | 7 | 8 | 7* | 7* | 8 | 9 | 7 | 7 | **7.6** |
| Duplicate event | 0 | — | — | — | — | — | — | 8 | 2 | **N/A (missing)** |
| Create ticket type | 8 | 7 | 8 | 7* | 6* | 8 | 9 | 9 | 8 | **7.8** |
| Manage RSVP event | 8 | 7 | 8 | 7* | 7* | 8 | 8 | 8 | 7 | **7.6** |
| View attendee list | 9 | 8 | 8 | 7* | 7* | 8 | 9 | 8 | 8 | **8.0** |
| Export attendees | 9 | 8 | 8 | 7* | 7* | 8 | 8 | 8 | 8 | **8.0** |
| Check in attendees (PWA/QR) | 9 | 8 | 9 | 7* | 8* | 8 | 9 | 10 | 9 | **8.6** |
| Message attendees | **2** | 2 | 4 | 5 | 5 | 6 | 7 | 9 | 2 | **4.7 (OD-2 404)** |
| Issue / manage refunds | 9 | 8 | 9 | 7* | 7* | 8 | 9 | 9 | 9 | **8.3** |
| Boost event | 8 | 7 | 8 | 7* | 7* | 8 | 9 | 9 | 8 | **7.9** |
| View revenue (dashboard) | 9 | 9 | 9 | 7* | 7* | 8 | 9 | 10 | 9 | **8.6** |
| View deep analytics (Pro) | 8 | 7 | 8 | 7* | 6* | 7 | 9 | 8 | 7 | **7.4** |
| View event insights | **1** | 2 | 4 | 4 | 4 | 4 | 7 | 9 | 2 | **4.1 (OD-1 500)** |
| View payouts | 9 | 8 | 9 | 7* | 7* | 8 | 9 | 9 | 9 | **8.3** |
| Finance / BAS export | 8 | 7 | 9 | 7* | 6* | 8 | 8 | 8 | 8 | **7.7** |
| Upgrade to Pro | 8 | 7 | 8 | 7* | 7* | 8 | 9 | 9 | 7 | **7.8** |
| Discover Pro at lock points | **4** | 4 | 6 | 6 | 6 | 8 | 7 | 9 | 4 | **6.0 (OD-3 copy)** |
| Manage profile / settings | 8 | 8 | 8 | 7* | 7* | 8 | 9 | 7 | 8 | **7.8** |
| Get help / support | 8 | 7 | 8 | 7* | 7* | 8 | 8 | 7 | 7 | **7.4** |
| Recover from errors | 6 | 6 | 7 | 6 | 6 | 7 | 8 | 7 | 6 | **6.6** |

## Dimension means (scored tasks; excludes N/A duplicate)

| Dimension | Mean |
| --- | :-: |
| Task completion | 7.7 |
| Ease of use | 7.0 |
| Trust | 7.8 |
| Accessibility | 6.8* |
| Mobile | 6.7* |
| Speed | 7.7 |
| Visual consistency | 8.6 |
| Business value | 8.5 |
| Overall confidence | 7.3 |

## Task-group means

| Group | Mean | Note |
| --- | :-: | --- |
| Onboarding & Stripe | 8.2 | Strongest group |
| Event authoring | 7.7 | (duplicate missing) |
| Tickets & RSVP | 7.7 | — |
| Operations / event-day | 7.4 | dragged by Comms 404 |
| Analytics & finance | 7.2 | dragged by Insights 500 |
| Pro | 6.9 | dragged by lock-point copy |
| Settings / support | 7.6 | — |

## Headline

- **Visual consistency (8.6)** and **business value (8.5)** are the standout strengths.
- **Accessibility (6.8\*)** and **Mobile (6.7\*)** are the lowest — largely *unverified* rather than
  proven-bad; a live a11y/device pass is required to confirm or lift these.
- Three tasks score low due to **verified defects**: Message attendees (404), View event insights
  (500), Discover Pro at lock points (copy).
