# Event Node Page - Discovery Report

## PHASE 0 — DISCOVERY SUMMARY

### 1) Event Content Type
- **Machine name**: `event`
- **Location**: `web/modules/custom/myeventlane_schema/config/install/node.type.event.yml`

### 2) Fields Available on Event Node

#### Date/Time
- `field_event_start` (datetime, required) - Event start date & time
- `field_event_end` (datetime, optional) - Event end date & time
- `field_sales_start` (datetime, optional) - When ticket sales begin
- `field_sales_end` (datetime, optional) - When ticket sales end

#### Location
- `field_location` (address field) - Full address with components
- `field_venue_name` (string) - Venue name
- `field_location_latitude` (decimal) - Latitude for maps
- `field_location_longitude` (decimal) - Longitude for maps

#### Capacity
- `field_capacity` (integer) - Maximum attendees (0 or empty = unlimited)
- `field_waitlist_capacity` (integer, optional) - Waitlist capacity

#### Accessibility
- `field_accessibility` (taxonomy term reference) - Accessibility features
- `field_accessibility_entry` (text, optional) - Entry accessibility details
- `field_accessibility_parking` (text, optional) - Parking accessibility
- `field_accessibility_directions` (text, optional) - Accessibility directions
- `field_accessibility_contact` (text, optional) - Contact for accessibility needs

#### Tickets / RSVP
- `field_event_type` (list_string, required) - Values: `rsvp`, `paid`, `both`, `external`
- `field_product_target` (entity reference) - Commerce product for tickets
- `field_ticket_types` (paragraphs) - Ticket type configurations
- `field_external_url` (link) - External ticket URL (for external mode)

#### Price
- Price is determined via Commerce product variations linked via `field_product_target`
- No direct price field on event node

#### Host/Organizer
- `field_event_vendor` (entity reference) - Vendor entity (preferred)
- `field_event_store` (entity reference) - Commerce store (fallback)
- Node owner (fallback) - User who created the event

#### Policy / Refund
- `field_refund_policy` (list_string) - Values: `none_specified`, `1_day`, `7_days`, `14_days`, `30_days`, `no_refunds`

#### Tags / Categories
- `field_category` (taxonomy term reference) - Event category
- `field_tags` (taxonomy term reference, optional) - Event tags/keywords

#### Other
- `field_event_image` (image) - Hero image
- `body` (text_long) - Event description
- `field_featured` (boolean) - Featured event flag

### 3) Drupal Commerce Usage
- **Yes, Drupal Commerce is used for tickets**
- Commerce product type: `ticket`
- Product variation type: `ticket_variation`
- Event links to product via `field_product_target`
- Service: `myeventlane_commerce` module handles ticket booking
- Route: `myeventlane_commerce.event_book` for booking flow

### 4) RSVP Implementation
- **Module**: `myeventlane_rsvp`
- **Entity**: `RsvpSubmission` (custom entity)
- **Forms**: 
  - `RsvpPublicForm` - Public RSVP submission
  - `RsvpSubmissionForm` - RSVP submission handler
- **Service**: `myeventlane_rsvp.capacity` (RsvpCapacityService)
- **Route**: `myeventlane_commerce.event_book` (shared with tickets)
- **Availability check**: Via `AttendanceManagerInterface::getAvailability()`

### 5) Existing Templates
- **Main template**: `web/themes/custom/myeventlane_theme/templates/node--event.html.twig`
- **CTA template**: `web/themes/custom/myeventlane_theme/templates/event/_event-cta.html.twig`
- **Other variants**: `node--event--full.html.twig`, `node--event--teaser.html.twig`, `node--event--default.html.twig`

### 6) Existing Preprocess Logic
- **File**: `web/themes/custom/myeventlane_theme/myeventlane_theme.theme`
- **Function**: `myeventlane_theme_preprocess_node()` (lines 710-1314)
- **Current CTA variables**:
  - `mel_cta_state` - 'enabled' or 'disabled'
  - `mel_cta_label` - Button label
  - `mel_cta_url` - Button URL
  - `mel_cta_helper` - Helper text
  - `mel_event_cta` - Structured array (already exists but needs consolidation)
- **Services used**:
  - `myeventlane_event.mode_manager` (EventModeManager) - Booking mode logic
  - `myeventlane_event_state.resolver` (EventStateResolver) - Event state (scheduled/live/sold_out/etc)
  - `myeventlane_metrics.service` (EventMetricsService) - Capacity/attendee counts

### 7) Key Services Available
- `myeventlane_event.mode_manager` - Determines booking mode and provides CTAs
- `myeventlane_event_state.resolver` - Resolves event state (scheduled/live/sold_out/cancelled/ended)
- `myeventlane_metrics.service` - Provides capacity and attendee metrics
- `myeventlane_event_attendees.manager` - Attendance management

### 8) Current Template Structure
The existing template has:
- Hero section with image, title, meta chips
- Two-column layout (main content + sticky sidebar)
- Action card in sidebar with CTA, price, date, location, capacity
- Mobile sticky CTA bar
- Information cards: About, Accessibility, Refund Policy, Hosted by, Tags

### 9) Questions / Clarifications Needed
**NONE** - All field names, services, and logic paths are confirmed from codebase.

---

## IMPLEMENTATION PLAN

### Phase 1: CTA Logic Consolidation
- Consolidate existing CTA logic into single canonical `event_cta` variable
- Ensure it handles all states: Buy tickets, RSVP, Sold out, Event ended
- Consider event date, capacity, ticket availability, RSVP enabled/disabled

### Phase 2: Template Refinement
- Ensure template structure matches requirements
- Verify all information sections are present
- Ensure no duplicate CTAs

### Phase 3: Sticky Action Card
- Desktop: Sticky right column
- Mobile: Fixed bottom CTA bar
- SCSS for sticky behavior

### Phase 4: Information Completeness
- Verify all required information is surfaced
- Report any missing fields

### Phase 5: Testing
- Visual checklist
- Functional tests
- Commands for validation
