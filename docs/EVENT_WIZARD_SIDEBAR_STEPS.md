# Event Wizard Sidebar Steps

Wizard step navigation has been moved from the inline main content area to the left sidebar. Steps appear when on any Event Wizard route (create/edit flow).

## Implementation

### 1. Preprocess (`myeventlane_vendor_theme.theme`)

- `page.wizard_steps` — Built when on wizard routes and `event`/`node` param exists
- `_myeventlane_vendor_theme_build_wizard_steps()` — Builds 6 steps with URLs and active state

### 2. Sidebar (`includes/sidebar.html.twig`)

- Renders `page.wizard_steps` in a "Create event" section
- Each link has `.mel-sidebar__link.mel-sidebar__link--wizard.is-wizard-step`
- Active step has `.is-active`

### 3. Inline Stepper Removed

- **event-wizard-step-prefix.html.twig** — `<nav class="mel-event-wizard__nav">` and `<ol class="mel-wizard-steps">` removed
- **event-wizard-review.html.twig** — Same stepper block removed

### 4. SCSS (`.is-wizard-step`)

- Orange (#f5a04c) active indicator (left border)
- Bold/semibold font
- Matches Gin-style highlight (background + left accent)

## Routes (for reference)

| Step       | Route Name                          | Path                               |
|------------|-------------------------------------|------------------------------------|
| Basics     | myeventlane_event.wizard.basics     | /vendor/events/{event}/build/basics |
| When & Where | myeventlane_event.wizard.when_where | /vendor/events/{event}/build/when-where |
| Tickets    | myeventlane_event.wizard.tickets    | /vendor/events/{event}/build/tickets |
| Details    | myeventlane_event.wizard.details    | /vendor/events/{event}/build/details |
| Review     | myeventlane_event.wizard.review     | /vendor/events/{event}/build/review |
| Publish    | myeventlane_event.wizard.publish    | /vendor/events/{event}/build/publish |

## Why Not links.menu.yml?

Wizard steps require the dynamic `{event}` route parameter. Drupal menu links cannot pass entity IDs from the current route context. The theme preprocess builds steps with the current event's ID when on a wizard page.
