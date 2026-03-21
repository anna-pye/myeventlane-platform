# Vendor Event Management Flow - Implementation Analysis

## Current Implementation Summary

### How Vendors Currently Edit Events

**Primary Route:** `/node/{event}/edit`
- Uses standard Drupal node edit form
- Form is altered by `myeventlane_event.module` hooks
- `EventFormAlter` service handles conditional logic and field visibility
- Form includes all event fields: title, dates, venue, vendor, store, ticket types, RSVP settings, etc.

### Ticket Types Management

**Data Model (v2):**
- Canonical tiers are `mel_ticket_type` entities; events reference them via `field_ticket_types` (entity reference), ordered on the node.
- Paid tiers project to Commerce variations through `TicketTierLifecycleService` + `TicketTypeManager` (event `field_product_target` holds the ticket product).
- Legacy `ticket_type_config` paragraph storage is not the active model; see `docs/TICKETING_V2_MIGRATION.md` for migration notes.

**Current Editing:**
- Vendor workspace: `/vendor/events/{event}/tickets` and wizard step `/vendor/events/{event}/build/tickets` both use `EventTicketsBuilder` + thin root forms (`EventTicketsWorkspaceForm`, `EventWizardTicketsForm`).

### Checkout Questions Management

**Data Model:**
- Questions stored as `attendee_extra_field` paragraphs
- Field: `field_attendee_questions` (entity_reference_revisions to paragraphs)
- Each paragraph defines: question type, label, required flag, options (for select)
- Questions applied during checkout via `TicketHolderParagraphPane`

**Current Editing:**
- Questions edited inline within the event node edit form
- Uses Paragraphs inline entity form widget

### Existing Vendor Routes

- `/vendor/dashboard` - Vendor dashboard listing all events
- `/vendor/event/{node}/attendees` - Attendee management
- `/vendor/event/{node}/attendees/export` - CSV export
- `/create-event` - Gateway to event creation (enforces vendor onboarding)

### Access Control

- Events checked by owner (node->getOwnerId() === current user)
- Vendor entity linked via `field_event_vendor` on event
- Store linked via `field_event_store` on event
- Access handlers in `VendorAttendeeController` check event ownership

### Preview Logic

- No dedicated preview currently
- Public event view at `/node/{event}` shows full event page
- Event view modes: `full`, `default`, `teaser`

## New Vendor Journey Architecture

### Base Route Structure

- `/vendor/event/{event}` - Overview/dashboard (future)
- `/vendor/event/{event}/edit` - Event information (wraps node edit form)
- `/vendor/event/{event}/design` - Page design settings
- `/vendor/event/{event}/content` - Page content editing
- `/vendor/event/{event}/tickets` - Ticket types management
- `/vendor/event/{event}/checkout-questions` - Checkout questions management
- `/vendor/event/{event}/promote` - Promotion settings (placeholder)
- `/vendor/event/{event}/payments` - Payments & fees (placeholder)
- `/vendor/event/{event}/comms` - Communications (placeholder)
- `/vendor/event/{event}/advanced` - Advanced settings (placeholder)

### Implementation Plan

1. **Base Controller** - `ManageEventControllerBase` with shared logic
2. **Navigation Service** - Builds step navigation array
3. **Shared Layout Template** - Two-column layout with left nav
4. **Step Controllers** - Individual controllers for each step
5. **Access Control** - Reuse existing vendor/owner checks
6. **Styling** - Vendor-friendly theme within admin theme

## Testing Checklist

### Phase 1: Basic Navigation
- [ ] Vendor can access `/vendor/event/{id}/edit` and see left nav + right panel
- [ ] Left sidebar shows all steps with correct labels
- [ ] Current step is highlighted in the navigation
- [ ] Navigation links work and route to correct pages
- [ ] Event title and status display correctly in header
- [ ] Preview and Publish buttons appear in header

### Phase 2: Event Information Step
- [ ] `/vendor/event/{id}/edit` wraps the existing node edit form
- [ ] Form saves correctly and preserves all existing functionality
- [ ] Conditional fields still work (ticket types, RSVP settings, etc.)
- [ ] Form validation works as expected
- [ ] Back/Continue buttons navigate correctly

### Phase 3: Page Design Step
- [ ] `/vendor/event/{id}/design` displays design form
- [ ] Form shows available design fields (hero, logo, color, CTA)
- [ ] Form saves correctly
- [ ] Preview panel shows event preview (if available)

### Phase 4: Page Content Step
- [ ] `/vendor/event/{id}/content` displays content form
- [ ] Body/description field is editable
- [ ] Form saves correctly
- [ ] Preview panel shows updated content

### Phase 5: Ticket Types Step
- [ ] `/vendor/event/{id}/tickets` displays ticket types table
- [ ] Table shows existing ticket types with name, price, capacity, status
- [ ] "Add Paid Ticket" button creates new ticket type
- [ ] "Add Free Ticket" button creates free ticket type
- [ ] Edit/Delete operations work for each ticket
- [ ] Total capacity is calculated and displayed
- [ ] Changes sync to Commerce variations on save

### Phase 6: Checkout Questions Step
- [ ] `/vendor/event/{id}/checkout-questions` displays questions table
- [ ] Default questions info is shown
- [ ] Table shows custom questions with type, label, required, apply to
- [ ] "Add Question" button creates new question paragraph
- [ ] Edit/Delete operations work for each question
- [ ] Questions save correctly to event

### Phase 7: Placeholder Steps
- [ ] Promote, Payments, Comms, Advanced routes show "Coming soon" message
- [ ] Navigation shows "Coming soon" badge for these steps
- [ ] Links are disabled for placeholder steps

### Phase 8: Responsive Design
- [ ] Layout works on desktop (1200px+)
- [ ] Layout adapts on tablet (768px-1024px) - sidebar becomes horizontal
- [ ] Layout works on mobile (<768px) - sidebar collapses, content stacks
- [ ] All buttons meet 44x44px tap targets
- [ ] Forms are readable and usable on all screen sizes

### Phase 9: Access Control
- [ ] Event owner can access all steps
- [ ] Vendor users (via field_vendor_users) can access all steps
- [ ] Site admins can access all steps
- [ ] Unauthorized users get access denied
- [ ] Access checks work for all routes

### Phase 10: Integration
- [ ] Existing event edit form still works at `/node/{id}/edit`
- [ ] Vendor dashboard links to manage event pages
- [ ] All existing functionality preserved
- [ ] No breaking changes to existing workflows
