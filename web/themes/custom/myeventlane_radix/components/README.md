# Single Directory Components (SDCs)

This directory is prepared for Drupal 11's Single Directory Components feature.

## Structure

When implementing SDCs, create component directories here:

```
components/
  event-card/
    event-card.component.yml
    event-card.twig
    event-card.scss
    event-card.js (optional)
  checkout-summary/
    checkout-summary.component.yml
    checkout-summary.twig
    checkout-summary.scss
```

## Component Definition

Each component needs a `.component.yml` file:

```yaml
name: Event Card
description: Displays an event in a card format
props:
  title:
    type: string
    required: true
  url:
    type: string
    required: true
  image:
    type: string
    required: false
  date:
    type: string
    required: false
  location:
    type: string
    required: false
```

## Usage in Twig

```twig
{{ include_component('event-card', {
  title: 'My Event',
  url: '/event/123',
  image: '/path/to/image.jpg',
  date: '2025-01-01',
  location: 'New York, NY'
}) }}
```

## Styling

Component SCSS can be:
1. Co-located with component files (recommended for SDCs)
2. Kept in `src/scss/components/` (current approach)

For SDCs, prefer co-located styles for better encapsulation.

## Documentation

See: https://www.drupal.org/docs/core-modules-and-themes/core-modules/sdc-module
