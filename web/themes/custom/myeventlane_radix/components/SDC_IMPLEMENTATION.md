# Single Directory Components (SDCs) Implementation Guide

**Theme:** MyEventLane Radix  
**Drupal Version:** 11  
**Status:** Examples Ready for Adoption

---

## Overview

This directory contains example Single Directory Components (SDCs) for the MyEventLane Radix theme. SDCs are Drupal 11's component system that co-locates Twig templates, CSS, JavaScript, and metadata in a single directory.

---

## Available Components

### 1. Event Card (`event-card/`)

**Purpose:** Displays an event in card format with image, title, date, location, and CTA.

**Props:**
- `title` (string): Event title
- `url` (string): Link to event page
- `image` (object): Image with `src` and `alt`
- `date` (string): Formatted date
- `location` (string): Location string
- `price` (object): Price with `amount`, `currency`, `formatted`
- `event_type` (string): Event type (RSVP, Paid, Both, External)
- `featured` (boolean): Featured styling

**Usage:**
```twig
{{ include_component('event-card', {
  title: event.title,
  url: event.url,
  image: { src: event.image_url, alt: event.title },
  date: event.date_formatted,
  location: event.location,
  price: { formatted: '$50.00' },
  event_type: 'Paid',
  featured: false
}) }}
```

---

### 2. Ticket Row (`ticket-row/`)

**Purpose:** Displays a ticket type row for checkout/cart tables.

**Props:**
- `name` (string): Ticket name
- `description` (string): Optional description
- `price` (object): Price object
- `available` (number): Available tickets
- `total` (number): Total capacity
- `sold_out` (boolean): Sold out state
- `required` (boolean): Required ticket

**Usage:**
```twig
{{ include_component('ticket-row', {
  name: ticket.name,
  description: ticket.description,
  price: { formatted: '$25.00' },
  available: ticket.available,
  total: ticket.total,
  sold_out: false,
  required: false
}) }}
```

---

### 3. Price Display (`price-display/`)

**Purpose:** Displays formatted price with optional sale price.

**Props:**
- `amount` (number): Price amount
- `currency` (string): Currency code (default: AUD)
- `formatted` (string): Pre-formatted price
- `sale_amount` (number): Optional sale price
- `sale_formatted` (string): Pre-formatted sale price
- `show_currency` (boolean): Show currency symbol
- `size` (string): Display size (small, medium, large)

**Usage:**
```twig
{{ include_component('price-display', {
  amount: 50.00,
  currency: 'AUD',
  formatted: '$50.00',
  size: 'large'
}) }}
```

---

### 4. Wizard Step Header (`wizard-step-header/`)

**Purpose:** Displays wizard step header with number, title, and description.

**Props:**
- `step_number` (number): Step number
- `title` (string): Step title
- `description` (string): Optional description
- `is_active` (boolean): Active state
- `is_complete` (boolean): Completed state

**Usage:**
```twig
{{ include_component('wizard-step-header', {
  step_number: 1,
  title: 'Basics',
  description: 'Tell us about your event',
  is_active: true,
  is_complete: false
}) }}
```

---

## SDC Directory Structure

Each SDC component follows this structure:

```
component-name/
├── component-name.component.yml    # Component metadata and props
├── component-name.twig             # Twig template
├── component-name.scss             # Component styles (optional)
└── component-name.js                # Component JavaScript (optional)
```

---

## Component Metadata (`.component.yml`)

The `.component.yml` file defines:
- Component name and description
- Props (input variables) with types and validation
- Slots (content areas) for flexible content injection
- Library dependencies
- Schema for validation

**Example:**
```yaml
name: Event Card
description: 'Displays an event in card format.'
props:
  type: object
  properties:
    title:
      type: string
      title: 'Event Title'
library: myeventlane_radix/event-card
schema:
  type: object
```

---

## Using SDCs in Templates

### Method 1: `include_component()` Function

```twig
{{ include_component('event-card', {
  title: node.title.value,
  url: node.toUrl().toString(),
  image: { src: image_url, alt: node.title.value },
  date: date_formatted,
  location: location_string,
  price: { formatted: price_formatted }
}) }}
```

### Method 2: Preprocess Hook

In `myeventlane_radix.theme`:

```php
function myeventlane_radix_preprocess_node(&$variables) {
  if ($variables['node']->bundle() === 'event') {
    $variables['event_card'] = [
      '#type' => 'component',
      '#component' => 'event-card',
      '#props' => [
        'title' => $variables['node']->getTitle(),
        'url' => $variables['node']->toUrl()->toString(),
        // ... other props
      ],
    ];
  }
}
```

Then in template:
```twig
{{ event_card }}
```

---

## Library Dependencies

Each SDC component should define its library in `myeventlane_radix.libraries.yml`:

```yaml
event-card:
  css:
    component:
      components/event-card/event-card.scss: {}
  dependencies:
    - core/drupal
    - myeventlane_radix/global
```

---

## Incremental Adoption Strategy

### Phase 1: Event Cards (Low Risk)
- Replace existing event card templates with SDC
- Test on event listing pages
- Verify responsive behavior

### Phase 2: Price Display (Low Risk)
- Replace price formatting across site
- Test on event pages, checkout, cart
- Verify currency formatting

### Phase 3: Ticket Rows (Medium Risk)
- Replace ticket table rows in checkout
- Test quantity selectors and AJAX
- Verify Commerce integration

### Phase 4: Wizard Step Headers (Medium Risk)
- Replace wizard step headers in Event Wizard
- Test step navigation and AJAX
- Verify form state preservation

---

## Benefits of SDCs

1. **Co-location:** Template, CSS, JS, and metadata in one place
2. **Reusability:** Use same component across different contexts
3. **Type Safety:** Props are validated via schema
4. **Documentation:** Component metadata serves as documentation
5. **Maintainability:** Easier to find and update component code

---

## Migration Notes

### From Existing Templates

1. **Extract component logic:** Identify reusable patterns
2. **Define props:** List all input variables
3. **Create SDC directory:** Follow structure above
4. **Update templates:** Replace template code with `include_component()`
5. **Test thoroughly:** Verify all props and slots work correctly

### Preserving Functionality

- **Form IDs:** Preserve all form element IDs for AJAX/validation
- **CSS Classes:** Maintain existing class names for JavaScript hooks
- **Accessibility:** Ensure ARIA labels and semantic HTML are preserved
- **Commerce Integration:** Test Commerce-specific functionality (checkout, cart)

---

## Testing Checklist

For each SDC component:

- [ ] Component renders correctly with all props
- [ ] Default values work when props are omitted
- [ ] CSS styles apply correctly
- [ ] JavaScript (if any) functions correctly
- [ ] Responsive behavior works on mobile/tablet/desktop
- [ ] Accessibility (ARIA labels, keyboard navigation)
- [ ] Integration with Drupal forms (if applicable)
- [ ] Integration with Commerce (if applicable)
- [ ] Performance (no unnecessary re-renders)

---

## References

- [Drupal SDC Documentation](https://www.drupal.org/docs/core-modules-and-themes/core-modules/sdc-module)
- [Component System Guide](https://www.drupal.org/docs/theming-drupal/using-single-directory-components)
- [SDC API Reference](https://api.drupal.org/api/drupal/core%21modules%21sdc%21sdc.module/group/sdc/11.0.x)

---

## Next Steps

1. **Enable SDC module:** `ddev drush en sdc`
2. **Clear cache:** `ddev drush cr`
3. **Test components:** Verify components render correctly
4. **Migrate incrementally:** Start with low-risk components
5. **Document usage:** Add component usage examples to templates
