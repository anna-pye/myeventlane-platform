# MEL UX Patterns

**Status:** Complete — reusable organiser patterns  
**Date:** 2026-07-24  
**Authority:** Screen specs · IA · VX2-02A layout · Product System  
**Type:** Documentation only

Every pattern is reusable. Prefer these over inventing a one-off layout.

---

## 1. Dashboard pattern

**Purpose:** Know what needs attention; celebrate momentum.

| Slot | Rule |
| --- | --- |
| Container | `.mel-layout--dashboard` (1280) |
| First viewport | Action queue (or Create event if empty) |
| Secondary | KPI strip — revenue, tickets sold, upcoming, attention |
| Tertiary | Recent events · Stripe health · optional Pro value |
| Mobile | Queue then KPIs |
| Anti-pattern | CMS “overview of everything” equal-weight cards |

---

## 2. Workspace pattern (Event Workspace)

**Purpose:** One application for one event — builder + ops.

| Slot | Rule |
| --- | --- |
| Shell | Topbar + section nav; Boost banner only when active |
| Container | `.mel-layout--workspace` (1200) |
| Home row 1 | Event Ready \| Next recommended action |
| Home row 2 | Compact expandable readiness |
| Home row 3+ | Operational KPI cards with CTAs |
| Activity | Timeline of bookings/orders (messages later) |
| Anti-pattern | Dual Studio vs Manager chrome; Jump-to dumps |

---

## 3. Card pattern

**Base:** `.mel-card` (+ hierarchy utilities).

| Variant | Use |
| --- | --- |
| Default | Content grouping |
| `--status` / `--readiness` / `--next` | Constrain to reading width |
| `--primary` | Subtle emphasis for primary decision |
| Stack / grid | Rhythm without five equal full-bleed cards |

Rules: tokens for padding/radius/shadow; clickable lift respects reduced motion; one badge max on public event cards (brand law).

---

## 4. Status card pattern

**Purpose:** Health at a glance.

| Element | Required |
| --- | --- |
| Title | Human label (e.g. Payment health, Event Ready) |
| State | Ready / Needs attention / Incomplete / Failed |
| Why | One sentence |
| CTA | Fix / Connect / Continue |
| A11y | Text + icon; region label |

---

## 5. Metric card pattern

**Purpose:** Operational summary — not a nav dump.

| Element | Required |
| --- | --- |
| Metric | Confirmed number only (no invented page views) |
| Label | Organiser language |
| CTA | Deep link to the owning workspace section |
| Empty | Em dash or “—” with explanation, not fake zeros that imply activity |

---

## 6. Timeline pattern

**Purpose:** Recent activity (Home activity; Messages history).

| Element | Rule |
| --- | --- |
| Order | Newest first |
| Item | Actor/object · action · relative time |
| Empty | “Activity will appear as bookings arrive” |
| Failure | Failed sends visible — never silent |

---

## 7. Table pattern

**Purpose:** Dense operational lists (orders, payouts history).

| Element | Rule |
| --- | --- |
| Prefer cards | Guest lists / tickets on mobile |
| Prefer tables | Cross-event orders, payout history on desktop |
| Columns | Scannable; money right-aligned |
| Row action | One clear primary; secondary in overflow |
| Empty | Table-level empty pattern, not blank thead |

---

## 8. Search pattern

**Purpose:** Find a person or object fast.

| Element | Rule |
| --- | --- |
| Placement | Immediate — above the list |
| Scope | Name, email, code (attendees) |
| Feedback | Live filter; “No matches” distinct from empty list |
| A11y | Labelled input; results region |

---

## 9. Filters pattern

**Purpose:** Narrow without leaving the workspace.

| Element | Rule |
| --- | --- |
| Chips | Ticket type, RSVP, waitlist, checked in, refunded, cancelled |
| Density | Wrap on 390px; no horizontal page overflow |
| Clear | One control to reset |
| Anti-pattern | Separate top-level apps per filter dimension |

---

## 10. Forms pattern

| Element | Rule |
| --- | --- |
| Container | `.mel-layout--form` (800) |
| Width | Labels and fields comfortable; help text under field |
| Save | Visible primary; sticky where long mobile forms need it |
| Errors | Field-level + summary; blame-free |
| Anti-pattern | Full-bleed admin forms on ultra-wide |

---

## 11. Confirmation panel pattern

**Use for:** Refund, delete, cancel event, take offline, irreversible archive.

| Element | Required |
| --- | --- |
| Title | Verb + object (“Refund Alex?”) |
| Consequence | “This can’t be undone from here.” |
| Primary | Destructive or confirm — explicit |
| Secondary | Cancel / Keep |
| Prefer | Governed `mel_modal` confirmation variant |

---

## 12. Warning pattern

| Severity | Use |
| --- | --- |
| Info | Guidance, tips |
| Warning | Needs attention soon (Stripe incomplete, publish blockers) |
| Critical | Money or access failure |

Always: reason + recovery. Never colour alone.

---

## 13. Publishing pattern

| Step | UX |
| --- | --- |
| Blocked | Human checklist; each item Fix CTA |
| Ready | Primary Publish |
| Success | Celebration + Share event link |
| Take offline | Confirm + explain attendee visibility |

---

## 14. Payments pattern

| Slot | Rule |
| --- | --- |
| Hub | `/vendor/payments` — health first |
| States | Connected / Needs attention / Incomplete |
| Sections | Stripe · Payouts · Refunds · Tax · Billing · Support |
| Language | Never Store / Gateway / Commerce |
| Mirror | Dashboard Stripe chip links here |

---

## 15. Messages pattern

| Slot | Rule |
| --- | --- |
| Hub | Brand · templates · history · compose entry |
| Event | Same product — not a second messaging app |
| Types | Announcement · Reminder · Important update · Cancellation · Thank you |
| Audience | Explicit selection + counts before send |
| History | Sent / Scheduled / Failed / Needs attention |

---

## 16. Analytics pattern

| Slot | Rule |
| --- | --- |
| Product name | Analytics (not Insights / Reporting as product names) |
| Free | Business pulse KPIs |
| Pro | Depth + upgrade story — never bare 403 |
| Per-event | Basics on Workspace; deep charts under Pro |

---

## 17. Empty state pattern

| Slot | Required |
| --- | --- |
| Why | One sentence |
| Primary CTA | One |
| Secondary | Optional learn link |
| A11y | `role="status"` / polite live when governed |

Examples (IA):

- Events — Create your first event  
- Tickets — Add a ticket so people can register  
- Attendees — Guests will appear here after their first booking  
- Messages — Send your first update when you’re ready  
- Payments — Connect Stripe to get paid  

---

## 18. Success state pattern

| Moment | Copy direction |
| --- | --- |
| Published | You’re live. Share your event link. |
| Stripe connected | Ready to receive payments. |
| Ticket saved | Ticket added — continue setup or publish. |
| Message sent | Message sent to {audience}. |
| Check-in | Checked in — confirm visually for door staff. |

---

## 19. Error state pattern

| Element | Rule |
| --- | --- |
| Title | What failed (human) |
| Body | Why, without stack traces |
| CTA | Retry · Fix · Contact support |
| Log | Server-side logging — fail loudly for ops, calmly for UI |

---

## Pattern selection cheat sheet

| Organiser goal | Pattern |
| --- | --- |
| What should I do? | Dashboard / Workspace Home |
| Change event story | Form |
| Sell entry | Tickets cards + Advanced disclosure |
| Run the door | Attendees + Door Mode |
| Get paid | Payments status |
| Tell guests | Messages |
| Understand performance | Analytics pulse |
| Grow reach | Marketing / Boost |

---

**Reuse rule:** If a new screen cannot name its pattern from this list, stop and extend the list — do not invent a parallel one silently.
