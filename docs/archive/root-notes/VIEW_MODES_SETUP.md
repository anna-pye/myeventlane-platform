# View Modes Setup for MEL v2

## Required View Modes

To use the new card components with real Event content, you need to create two view modes for the Event content type:

1. **`event_card_poster`** - For Sticker Wall and featured grids
2. **`event_card_compact`** - For dense rows (Tonight, Free & under $20, Near you)

## How to Create View Modes

### Option 1: Via Drupal UI

1. Go to **Structure > Content types > Event > Manage display**
2. Click **"Custom display settings"** at the bottom
3. Check **"event_card_poster"** and **"event_card_compact"**
4. Click **"Save"**
5. Configure each view mode:
   - **event_card_poster**: Enable only fields needed for the poster card template
   - **event_card_compact**: Enable only fields needed for the compact card template

### Option 2: Via Configuration Export/Import

Create YAML files in `config/sync/`:

**`core.entity_view_display.node.event.event_card_poster.yml`**
```yaml
uuid: [generate-new]
langcode: en
status: true
dependencies:
  config:
    - field.field.node.event.field_event_image
    - field.field.node.event.field_event_start
    - field.field.node.event.field_venue_name
    - field.field.node.event.field_location
    - field.field.node.event.field_product_target
    - field.field.node.event.field_event_type
    - field.field.node.event.field_category
    - field.field.node.event.field_tags
    - node.type.event
  module:
    - datetime
    - image
    - node
    - user
id: node.event.event_card_poster
targetEntityType: node
bundle: event
mode: event_card_poster
content:
  field_event_image:
    type: image
    label: hidden
    settings:
      image_style: large
      image_link: ''
    third_party_settings: {  }
    weight: 0
    region: content
  field_event_start:
    type: datetime_default
    label: hidden
    settings:
      timezone_override: ''
    third_party_settings: {  }
    weight: 1
    region: content
  field_venue_name:
    type: string
    label: hidden
    settings:
      link_to_entity: false
    third_party_settings: {  }
    weight: 2
    region: content
  field_location:
    type: address_default
    label: hidden
    settings:
      format_handlers:
        - address
    third_party_settings: {  }
    weight: 3
    region: content
  field_product_target:
    type: entity_reference_label
    label: hidden
    settings:
      link: false
    third_party_settings: {  }
    weight: 4
    region: content
  field_event_type:
    type: list_default
    label: hidden
    settings: {  }
    third_party_settings: {  }
    weight: 5
    region: content
  field_category:
    type: entity_reference_label
    label: hidden
    settings:
      link: false
    third_party_settings: {  }
    weight: 6
    region: content
hidden:
  body: true
  field_tags: true
```

**`core.entity_view_display.node.event.event_card_compact.yml`**
```yaml
uuid: [generate-new]
langcode: en
status: true
dependencies:
  config:
    - field.field.node.event.field_event_image
    - field.field.node.event.field_event_start
    - field.field.node.event.field_product_target
    - field.field.node.event.field_event_type
    - node.type.event
  module:
    - datetime
    - image
    - node
    - user
id: node.event.event_card_compact
targetEntityType: node
bundle: event
mode: event_card_compact
content:
  field_event_image:
    type: image
    label: hidden
    settings:
      image_style: thumbnail
      image_link: ''
    third_party_settings: {  }
    weight: 0
    region: content
  field_event_start:
    type: datetime_default
    label: hidden
    settings:
      timezone_override: ''
    third_party_settings: {  }
    weight: 1
    region: content
  field_product_target:
    type: entity_reference_label
    label: hidden
    settings:
      link: false
    third_party_settings: {  }
    weight: 2
    region: content
hidden:
  body: true
  field_venue_name: true
  field_location: true
  field_category: true
  field_tags: true
  field_event_type: true
```

Then import:
```bash
ddev drush config:import
```

## Configure Views to Use View Modes

After creating the view modes:

1. **For Sticker Wall (Discover events)**:
   - Edit the View (e.g., `upcoming_events`)
   - Add a new display or edit existing
   - Set **Format > Show** to "Content"
   - Set **Format > View mode** to "event_card_poster"
   - Set **Items per page** to 9-12

2. **For Secondary Rows (Tonight, Free & under $20, Near you)**:
   - Create new View displays or edit existing
   - Set **Format > Show** to "Content"
   - Set **Format > View mode** to "event_card_compact"
   - Set **Items per page** to 6-8
   - Add filters for:
     - **Tonight**: `field_event_start` = today
     - **Free & under $20**: Price filter (requires custom filter)
     - **Near you**: Location filter (requires geolocation)

## Notes

- The node templates (`node--event--event-card-poster.html.twig` and `node--event--event-card-compact.html.twig`) will automatically be used when these view modes are active
- View modes can be configured to show/hide specific fields, but the templates handle the actual rendering
- Price calculation is simplified in the templates - can be enhanced with a helper function for min/max price ranges
