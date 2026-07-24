# VX2-09 — Marketing surface inventory

**Epic:** VX2-09 Marketing (Event Growth Centre)  
**Branch:** `feature/vx2-marketing`  
**Date:** 2026-07-24  
**Authority:** Vendor Experience Convergence · Screen Specs A9/B10 · Language Guide · Implementation Plan

## Disposition legend

| Code | Meaning |
| --- | --- |
| **KEEP** | Remains as deep page / infra |
| **MERGE** | Content or entry absorbed into Marketing hub |
| **RENAME** | Organiser-facing label changes |
| **HIDE** | Remove from nav once Marketing owns entry |
| **REDIRECT** | Legacy URL → Marketing destination |
| **RETIRE** | Remove after redirect bake-in |

---

## Shell / vendor-global

| Surface | Path | Disposition | Notes |
| --- | --- | --- | --- |
| Marketing hub (new) | `/vendor/marketing` | **CREATE** | Event Growth Centre |
| Boost campaigns page | `/vendor/boost` | **REDIRECT** → `/vendor/marketing?section=boost` | Content merged into hub Boost section; JS scrolls to `#boost` |
| Audience | `/vendor/audience` | **MERGE** entry | Deep page kept; Marketing · Audience Growth CTA |
| Boost vendor export | `/vendor/dashboard/boost/export` | **KEEP** | Linked from Marketing Performance |
| Growth CTA / dismiss | `/vendor/growth/*` | **KEEP** | Infra; guidance surfaces under Marketing |
| Shell nav Marketing | was → boost | **REPOINT** → marketing hub | Label already Marketing |
| Analytics “Open Marketing” | CTA → events | **REPOINT** → marketing hub | |

## Event Workspace

| Surface | Path | Disposition | Notes |
| --- | --- | --- | --- |
| Marketing section | `/vendor/events/{id}/studio/marketing` | **KEEP / deepen** | Share, social, QR, widgets, Boost |
| Overview Marketing card CTA “Promote” | Overview | **RENAME** → Share / Open Marketing | |
| Boost local task / legacy tab | workspace | **HIDE** preferred | Marketing owns entry; Boost deep pages remain |
| Manage-event promote stub | `/vendor/event/{id}/promote` | **REDIRECT** | → workspace Marketing (was Boost page) |

## Boost product

| Surface | Disposition | Notes |
| --- | --- | --- |
| Wizard steps / purchase / success | **KEEP** | Premium guided flow |
| `/vendor/events/{id}/boost` | **KEEP** | Deep link from Marketing |
| `/event/{id}/boost` | **KEEP** (optional later redirect) | Still used by some CTAs |
| Impression beacon / exports / PDF | **KEEP** | Infra |

## Widgets & embeds

| Surface | Disposition | Notes |
| --- | --- | --- |
| Ticket widgets CRUD | **KEEP** | Single destination under Tickets |
| Marketing entry to widgets | **MERGE** | Link only — no second CRUD |

## Share / QR / social

| Surface | Disposition | Notes |
| --- | --- | --- |
| Public event URL + view | **KEEP / expand** | Copy link on hub + event Marketing |
| Publish celebrate social | **MERGE** pattern | Same Facebook / LinkedIn / Email / Instagram guidance |
| Event-share QR | **CREATE** | Uses existing ticket QR generator for public URL payload only |
| Ticket / check-in QR | **DO NOT MERGE** | Door ops |

## Explicitly out of Marketing

| Surface | Why |
| --- | --- |
| `/vendor/events/{id}/promotion` | Messages compose (VX2-06) |
| Studio `/studio/promotions` | Redirects to Messages |
| Waitlist “Promote” | Attendee ops |
| Staff Boost / Growth admin | Staff only |

## Language

| Avoid | Prefer |
| --- | --- |
| Grow / Grow event | Marketing |
| Promotion / Promote (growth sense) | Marketing / Share / Boost |
| Purchase surface | Ticket widget / Embed |
| **Boost** | Keep as product name |
