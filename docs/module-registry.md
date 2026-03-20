# MyEventLane Module Registry

Source of truth: `web/modules/custom/*/*.info.yml` (description fields) plus module filesystem contents.

| Module | Path | Responsibility (from module metadata) | Evidence |
|---|---|---|---|
| `mel_admin_dashboard` | `web/modules/custom/mel_admin_dashboard` | Platform Control Centre for MyEventLane; replacement for `myeventlane_admin_dashboard` on `/admin/myeventlane`. | `web/modules/custom/mel_admin_dashboard/mel_admin_dashboard.info.yml` |
| `myeventlane_account` | `web/modules/custom/myeventlane_account` | Customer My Account experience (dashboard/profile/settings). | `web/modules/custom/myeventlane_account/myeventlane_account.info.yml` |
| `myeventlane_admin_dashboard` | `web/modules/custom/myeventlane_admin_dashboard` | Platform administration dashboard (events/vendors/users/platform stats). | `web/modules/custom/myeventlane_admin_dashboard/myeventlane_admin_dashboard.info.yml` |
| `myeventlane_ai` | `web/modules/custom/myeventlane_ai` | Generic AI provider abstraction/utilities. | `web/modules/custom/myeventlane_ai/myeventlane_ai.info.yml` |
| `myeventlane_analytics` | `web/modules/custom/myeventlane_analytics` | Vendor analytics and reporting (time-series, funnels, event analytics). | `web/modules/custom/myeventlane_analytics/myeventlane_analytics.info.yml` |
| `myeventlane_api` | `web/modules/custom/myeventlane_api` | Public and vendor-scoped REST API endpoints. | `web/modules/custom/myeventlane_api/myeventlane_api.info.yml` |
| `myeventlane_attendee` | `web/modules/custom/myeventlane_attendee` | Attendee abstraction for RSVP and ticket attendees. | `web/modules/custom/myeventlane_attendee/myeventlane_attendee.info.yml` |
| `myeventlane_auth` | `web/modules/custom/myeventlane_auth` | Branded login/register UX and account type flow. | `web/modules/custom/myeventlane_auth/myeventlane_auth.info.yml` |
| `myeventlane_automation` | `web/modules/custom/myeventlane_automation` | Automated event notifications/reminders/waitlist/cancellation/export messaging. | `web/modules/custom/myeventlane_automation/myeventlane_automation.info.yml` |
| `myeventlane_blocks` | `web/modules/custom/myeventlane_blocks` | Custom homepage blocks (feature/category chart). | `web/modules/custom/myeventlane_blocks/myeventlane_blocks.info.yml` |
| `myeventlane_boost` | `web/modules/custom/myeventlane_boost` | Event promotion/featuring with Commerce boost packages. | `web/modules/custom/myeventlane_boost/myeventlane_boost.info.yml` |
| `myeventlane_capacity` | `web/modules/custom/myeventlane_capacity` | Event capacity tracking/enforcement. | `web/modules/custom/myeventlane_capacity/myeventlane_capacity.info.yml` |
| `myeventlane_cart` | `web/modules/custom/myeventlane_cart` | Cart enhancements for per-ticket attendee capture. | `web/modules/custom/myeventlane_cart/myeventlane_cart.info.yml` |
| `myeventlane_checkin` | `web/modules/custom/myeventlane_checkin` | Mobile-first event check-in system. | `web/modules/custom/myeventlane_checkin/myeventlane_checkin.info.yml` |
| `myeventlane_checkout_flow` | `web/modules/custom/myeventlane_checkout_flow` | Custom single-page checkout flow. | `web/modules/custom/myeventlane_checkout_flow/myeventlane_checkout_flow.info.yml` |
| `myeventlane_checkout_paragraph` | `web/modules/custom/myeventlane_checkout_paragraph` | Paragraph-based checkout pane for attendee capture. | `web/modules/custom/myeventlane_checkout_paragraph/myeventlane_checkout_paragraph.info.yml` |
| `myeventlane_commerce` | `web/modules/custom/myeventlane_commerce` | Commerce integration for booking/tickets/orders. | `web/modules/custom/myeventlane_commerce/myeventlane_commerce.info.yml` |
| `myeventlane_core` | `web/modules/custom/myeventlane_core` | Core shared platform services/utilities/foundation. | `web/modules/custom/myeventlane_core/myeventlane_core.info.yml` |
| `myeventlane_dashboard` | `web/modules/custom/myeventlane_dashboard` | Vendor dashboard hub for events/RSVPs/ticket sales/analytics. | `web/modules/custom/myeventlane_dashboard/myeventlane_dashboard.info.yml` |
| `myeventlane_demo` | `web/modules/custom/myeventlane_demo` | Demo data seeding and Drush commands. | `web/modules/custom/myeventlane_demo/myeventlane_demo.info.yml` |
| `myeventlane_diagnostics` | `web/modules/custom/myeventlane_diagnostics` | Event diagnostics and readiness feedback. | `web/modules/custom/myeventlane_diagnostics/myeventlane_diagnostics.info.yml` |
| `myeventlane_domain_events` | `web/modules/custom/myeventlane_domain_events` | Append-only domain event store and queue projectors. | `web/modules/custom/myeventlane_domain_events/myeventlane_domain_events.info.yml` |
| `myeventlane_donations` | `web/modules/custom/myeventlane_donations` | Platform and RSVP donation flows. | `web/modules/custom/myeventlane_donations/myeventlane_donations.info.yml` |
| `myeventlane_escalations` | `web/modules/custom/myeventlane_escalations` | Escalation management for support issues. | `web/modules/custom/myeventlane_escalations/myeventlane_escalations.info.yml` |
| `myeventlane_escalations_ai` | `web/modules/custom/myeventlane_escalations_ai` | AI suggestions for escalations. | `web/modules/custom/myeventlane_escalations_ai/myeventlane_escalations_ai.info.yml` |
| `myeventlane_escalations_ai_draft` | `web/modules/custom/myeventlane_escalations_ai_draft` | Staff-only AI draft reply generation for escalations. | `web/modules/custom/myeventlane_escalations_ai_draft/myeventlane_escalations_ai_draft.info.yml` |
| `myeventlane_escalations_analytics` | `web/modules/custom/myeventlane_escalations_analytics` | Escalation analytics dashboards/vendor health scoring. | `web/modules/custom/myeventlane_escalations_analytics/myeventlane_escalations_analytics.info.yml` |
| `myeventlane_escalations_capacity` | `web/modules/custom/myeventlane_escalations_capacity` | Staff workload/capacity analytics for escalations. | `web/modules/custom/myeventlane_escalations_capacity/myeventlane_escalations_capacity.info.yml` |
| `myeventlane_escalations_policy` | `web/modules/custom/myeventlane_escalations_policy` | Weekly automated policy evaluation for vendor escalation risk. | `web/modules/custom/myeventlane_escalations_policy/myeventlane_escalations_policy.info.yml` |
| `myeventlane_escalations_portal` | `web/modules/custom/myeventlane_escalations_portal` | Customer/vendor support portal with threaded comments and status flows. | `web/modules/custom/myeventlane_escalations_portal/myeventlane_escalations_portal.info.yml` |
| `myeventlane_escalations_refunds` | `web/modules/custom/myeventlane_escalations_refunds` | Read-only correlation of escalations and refunds. | `web/modules/custom/myeventlane_escalations_refunds/myeventlane_escalations_refunds.info.yml` |
| `myeventlane_escalations_sla` | `web/modules/custom/myeventlane_escalations_sla` | SLA policy/timers/breach detection/escalation levels. | `web/modules/custom/myeventlane_escalations_sla/myeventlane_escalations_sla.info.yml` |
| `myeventlane_event` | `web/modules/custom/myeventlane_event` | Canonical event orchestration (presentation/CTA/RSVP-ticket integration). | `web/modules/custom/myeventlane_event/myeventlane_event.info.yml` |
| `myeventlane_event_attendees` | `web/modules/custom/myeventlane_event_attendees` | Unified attendance tracking across RSVP and ticket purchases. | `web/modules/custom/myeventlane_event_attendees/myeventlane_event_attendees.info.yml` |
| `myeventlane_event_state` | `web/modules/custom/myeventlane_event_state` | Event state machine and state resolution. | `web/modules/custom/myeventlane_event_state/myeventlane_event_state.info.yml` |
| `myeventlane_finance` | `web/modules/custom/myeventlane_finance` | Financial reporting and BAS aggregation. | `web/modules/custom/myeventlane_finance/myeventlane_finance.info.yml` |
| `myeventlane_front` | `web/modules/custom/myeventlane_front` | Front page UI blocks. | `web/modules/custom/myeventlane_front/myeventlane_front.info.yml` |
| `myeventlane_help_assistant` | `web/modules/custom/myeventlane_help_assistant` | Grounded AI assistant for Help Centre content. | `web/modules/custom/myeventlane_help_assistant/myeventlane_help_assistant.info.yml` |
| `myeventlane_help_centre` | `web/modules/custom/myeventlane_help_centre` | Public/vendor help centre with contextual article linking. | `web/modules/custom/myeventlane_help_centre/myeventlane_help_centre.info.yml` |
| `myeventlane_help_centre_ai` | `web/modules/custom/myeventlane_help_centre_ai` | Legacy `/help/ask` route (302 to Help Assistant). | `web/modules/custom/myeventlane_help_centre_ai/myeventlane_help_centre_ai.info.yml` |
| `myeventlane_launch` | `web/modules/custom/myeventlane_launch` | Launch readiness orchestration and safeguards. | `web/modules/custom/myeventlane_launch/myeventlane_launch.info.yml` |
| `myeventlane_legal` | `web/modules/custom/myeventlane_legal` | Legal compliance (terms/privacy/cookie consent/audit). | `web/modules/custom/myeventlane_legal/myeventlane_legal.info.yml` |
| `myeventlane_location` | `web/modules/custom/myeventlane_location` | Event location + map/autocomplete integration. | `web/modules/custom/myeventlane_location/myeventlane_location.info.yml` |
| `myeventlane_messaging` | `web/modules/custom/myeventlane_messaging` | Template-driven email/notifications. | `web/modules/custom/myeventlane_messaging/myeventlane_messaging.info.yml` |
| `myeventlane_metrics` | `web/modules/custom/myeventlane_metrics` | Centralized event metrics service. | `web/modules/custom/myeventlane_metrics/myeventlane_metrics.info.yml` |
| `myeventlane_page_visuals` | `web/modules/custom/myeventlane_page_visuals` | Admin-managed route-to-visual mapping via media entities. | `web/modules/custom/myeventlane_page_visuals/myeventlane_page_visuals.info.yml` |
| `myeventlane_privacy` | `web/modules/custom/myeventlane_privacy` | Privacy/cookie consent + consent-aware script wiring. | `web/modules/custom/myeventlane_privacy/myeventlane_privacy.info.yml` |
| `myeventlane_pro` | `web/modules/custom/myeventlane_pro` | Professional subscription feature layer. | `web/modules/custom/myeventlane_pro/myeventlane_pro.info.yml` |
| `myeventlane_public_trust` | `web/modules/custom/myeventlane_public_trust` | Public trust signal aggregation. | `web/modules/custom/myeventlane_public_trust/myeventlane_public_trust.info.yml` |
| `myeventlane_questions` | `web/modules/custom/myeventlane_questions` | Vendor reusable attendee question library. | `web/modules/custom/myeventlane_questions/myeventlane_questions.info.yml` |
| `myeventlane_refunds` | `web/modules/custom/myeventlane_refunds` | Vendor refunds/cancellations/customer recovery. | `web/modules/custom/myeventlane_refunds/myeventlane_refunds.info.yml` |
| `myeventlane_reporting` | `web/modules/custom/myeventlane_reporting` | Vendor/admin reporting UI with KPIs/charts/exports. | `web/modules/custom/myeventlane_reporting/myeventlane_reporting.info.yml` |
| `myeventlane_rsvp` | `web/modules/custom/myeventlane_rsvp` | RSVP workflows (forms/waitlist/cancel/ICS/check-in/vendor views). | `web/modules/custom/myeventlane_rsvp/myeventlane_rsvp.info.yml` |
| `myeventlane_schema` | `web/modules/custom/myeventlane_schema` | Core field/content-type/taxonomy/schema definitions. | `web/modules/custom/myeventlane_schema/myeventlane_schema.info.yml` |
| `myeventlane_search` | `web/modules/custom/myeventlane_search` | Site-wide Search API integration with grouped result types. | `web/modules/custom/myeventlane_search/myeventlane_search.info.yml` |
| `myeventlane_seed` | `web/modules/custom/myeventlane_seed` | Deterministic test seed data/reset tooling. | `web/modules/custom/myeventlane_seed/myeventlane_seed.info.yml` |
| `myeventlane_shared` | `web/modules/custom/myeventlane_shared` | Shared utilities/services (including centralized colors). | `web/modules/custom/myeventlane_shared/myeventlane_shared.info.yml` |
| `myeventlane_staff_playbooks` | `web/modules/custom/myeventlane_staff_playbooks` | Internal staff playbooks (non-AI, cacheable). | `web/modules/custom/myeventlane_staff_playbooks/myeventlane_staff_playbooks.info.yml` |
| `myeventlane_staff_playbooks_ai` | `web/modules/custom/myeventlane_staff_playbooks_ai` | AI-assisted summarisation for staff playbooks. | `web/modules/custom/myeventlane_staff_playbooks_ai/myeventlane_staff_playbooks_ai.info.yml` |
| `myeventlane_stripe` | `web/modules/custom/myeventlane_stripe` | Stripe-backed payout/connect services. | `web/modules/custom/myeventlane_stripe/myeventlane_stripe.info.yml` |
| `myeventlane_summary` | `web/modules/custom/myeventlane_summary` | Pre-aggregated platform metrics for control-centre dashboards. | `web/modules/custom/myeventlane_summary/myeventlane_summary.info.yml` |
| `myeventlane_support_console` | `web/modules/custom/myeventlane_support_console` | Staff support landing page aggregating support signals/action queue. | `web/modules/custom/myeventlane_support_console/myeventlane_support_console.info.yml` |
| `myeventlane_theme_settings` | `web/modules/custom/myeventlane_theme_settings` | Admin UI for theme hero images/settings. | `web/modules/custom/myeventlane_theme_settings/myeventlane_theme_settings.info.yml` |
| `myeventlane_tickets` | `web/modules/custom/myeventlane_tickets` | Ticket creation/groups/access codes/purchase surfaces/downloads/PDFs. | `web/modules/custom/myeventlane_tickets/myeventlane_tickets.info.yml` |
| `myeventlane_vendor` | `web/modules/custom/myeventlane_vendor` | Vendor entity and organizer workflows for event management. | `web/modules/custom/myeventlane_vendor/myeventlane_vendor.info.yml` |
| `myeventlane_vendor_ai` | `web/modules/custom/myeventlane_vendor_ai` | Vendor-facing assistant for policy/help-centre questions. | `web/modules/custom/myeventlane_vendor_ai/myeventlane_vendor_ai.info.yml` |
| `myeventlane_vendor_analytics` | `web/modules/custom/myeventlane_vendor_analytics` | Store-scoped vendor KPI aggregation service. | `web/modules/custom/myeventlane_vendor_analytics/myeventlane_vendor_analytics.info.yml` |
| `myeventlane_vendor_comms` | `web/modules/custom/myeventlane_vendor_comms` | Vendor event update communications to attendees. | `web/modules/custom/myeventlane_vendor_comms/myeventlane_vendor_comms.info.yml` |
| `myeventlane_vendor_nudges` | `web/modules/custom/myeventlane_vendor_nudges` | Supportive educational nudges for vendor dashboards. | `web/modules/custom/myeventlane_vendor_nudges/myeventlane_vendor_nudges.info.yml` |
| `myeventlane_venue` | `web/modules/custom/myeventlane_venue` | Reusable vendor-managed venues with sharing/public directory. | `web/modules/custom/myeventlane_venue/myeventlane_venue.info.yml` |
| `myeventlane_views` | `web/modules/custom/myeventlane_views` | Views integration, CSV export, and custom Views access plugins. | `web/modules/custom/myeventlane_views/myeventlane_views.info.yml` |
| `myeventlane_wallet` | `web/modules/custom/myeventlane_wallet` | Apple/Google wallet pass generation for tickets. | `web/modules/custom/myeventlane_wallet/myeventlane_wallet.info.yml` |
| `myeventlane_webhooks` | `web/modules/custom/myeventlane_webhooks` | Webhook delivery system for event-driven integrations. | `web/modules/custom/myeventlane_webhooks/myeventlane_webhooks.info.yml` |
