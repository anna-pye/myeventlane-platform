#!/bin/bash

set -e

echo "=================================================="
echo " MyEventLane — Event Content Type Auto Installer"
echo "=================================================="
echo

# ----------------------------------------
# 1. Create vocabularies if missing
# ----------------------------------------

echo "Checking vocabularies..."

ddev drush eval "
use Drupal\taxonomy\Entity\Vocabulary;

\$vocabs = [
  'categories' => 'Categories',
  'tags' => 'Tags',
  'accessibility' => 'Accessibility'
];


foreach (\$vocabs as \$id => \$label) {
  if (!Vocabulary::load(\$id)) {
    echo \"Creating vocabulary: \$label\n\";
    Vocabulary::create(['vid' => \$id, 'name' => \$label])->save();
  }
}
"

# ----------------------------------------
# 2. Create Event content type if missing
# ----------------------------------------

echo "Checking Event content type..."

ddev drush eval "
use Drupal\node\Entity\NodeType;

if (!NodeType::load('event')) {
  echo \"Creating Event content type...\n\";
  \$t = NodeType::create([
    'type' => 'event',
    'name' => 'Event',
    'description' => 'MyEventLane Event type',
  ]);
  \$t->save();
}
"

# ----------------------------------------
# 3. Field creation helper
# ----------------------------------------

create_field () {
  local BUNDLE="event"
  local FIELD=$1
  local TYPE=$2
  local LABEL=$3
  shift 3
  local EXTRA=$@

  echo "Creating field: $FIELD ($LABEL)"

  # Create storage if missing
  ddev drush eval "
    if (!\Drupal::entityTypeManager()->getStorage('field_storage_config')->load('node.$FIELD')) {
      echo \" - Creating storage\n\";
      \Drupal\field\Entity\FieldStorageConfig::create([
        'field_name' => '$FIELD',
        'entity_type' => 'node',
        'type' => '$TYPE',
        'settings' => [],
      ])->save();
    }
  "

  # Create field instance if missing
  ddev drush eval "
    if (!\Drupal::entityTypeManager()->getStorage('field_config')->load('node.$BUNDLE.$FIELD')) {
      echo \" - Attaching to bundle\n\";
      \Drupal\field\Entity\FieldConfig::create([
        'field_name' => '$FIELD',
        'entity_type' => 'node',
        'bundle' => '$BUNDLE',
        'label' => '$LABEL',
        'settings' => [],
      ])->save();
    }
  "
}

# ----------------------------------------
# 4. Create all Event fields
# ----------------------------------------

echo
echo "Creating all Event fields..."

create_field field_event_image        image         "Event Image"
create_field field_event_start        datetime      "Event Start"
create_field field_event_end          datetime      "Event End"
create_field field_event_location     string        "Location"
create_field field_venue_name         string        "Venue Name"
create_field field_capacity           integer       "Capacity"
create_field field_featured           boolean       "Featured"
create_field field_external_url       link          "External Link"

create_field field_category           entity_reference "Category"
ddev drush eval "
  \$f = Drupal\field\Entity\FieldConfig::load('node.event.field_category');
  \$f->setSetting('handler_settings', ['target_bundles' => ['categories' => 'categories']]);
  \$f->save();
"

create_field field_tags               entity_reference "Tags"
ddev drush eval "
  \$f = Drupal\field\Entity\FieldConfig::load('node.event.field_tags');
  \$f->setSetting('handler_settings', ['target_bundles' => ['tags' => 'tags']]);
  \$f->save();
"

create_field field_accessibility       entity_reference "Accessibility"
ddev drush eval "
  \$f = Drupal\field\Entity\FieldConfig::load('node.event.field_accessibility');
  \$f->setSetting('handler_settings', ['target_bundles' => ['accessibility' => 'accessibility']]);
  \$f->save();
"

create_field field_organizer          entity_reference "Organizer"
ddev drush eval "
  \$f = Drupal\field\Entity\FieldConfig::load('node.event.field_organizer');
  \$f->setSetting('handler_settings', ['target_type' => 'user']);
  \$f->save();
"

create_field field_event_type         list_string "Event Type"
ddev drush eval "
  \$f = Drupal\field\Entity\FieldConfig::load('node.event.field_event_type');
  \$f->setSetting('allowed_values', [
    ['value' => 'rsvp', 'label' => 'RSVP Only'],
    ['value' => 'paid', 'label' => 'Paid Event'],
  ]);
  \$f->save();
"

create_field field_rsvp_target        entity_reference "RSVP Target"
ddev drush eval "
  \$f = Drupal\field\Entity\FieldConfig::load('node.event.field_rsvp_target');
  \$f->setSetting('handler_settings', ['target_type' => 'node']);
  \$f->save();
"

create_field field_product_target     entity_reference "Product Target"
ddev drush eval "
  \$f = Drupal\field\Entity\FieldConfig::load('node.event.field_product_target');
  \$f->setSetting('handler_settings', ['target_type' => 'commerce_product']);
  \$f->save();
"

echo "All fields created."

# ----------------------------------------
# 5. Build form display
# ----------------------------------------

echo
echo "Building form display..."

ddev drush eval "
use Drupal\Core\Entity\Entity\EntityFormDisplay;

\$fd = EntityFormDisplay::load('node.event.default');
if (!\$fd) {
  \$fd = EntityFormDisplay::create([
    'targetEntityType' => 'node',
    'bundle' => 'event',
    'mode' => 'default',
    'status' => TRUE,
  ]);
}

\$fd->setComponent('title', ['type' => 'string_textfield']);
\$fd->setComponent('field_event_image', ['type' => 'image_image']);
\$fd->setComponent('field_event_start', ['type' => 'datetime_default']);
\$fd->setComponent('field_event_end', ['type' => 'datetime_default']);
\$fd->setComponent('field_event_location', ['type' => 'string_textfield']);
\$fd->setComponent('field_venue_name', ['type' => 'string_textfield']);
\$fd->setComponent('field_category', ['type' => 'entity_reference_autocomplete', 'weight' => 10]);
\$fd->setComponent('field_tags', ['type' => 'entity_reference_autocomplete', 'weight' => 11]);
\$fd->setComponent('field_organizer', ['type' => 'entity_reference_autocomplete', 'weight' => 12]);
\$fd->setComponent('field_event_type', ['type' => 'options_select']);
\$fd->setComponent('field_rsvp_target', ['type' => 'entity_reference_autocomplete']);
\$fd->setComponent('field_product_target', ['type' => 'entity_reference_autocomplete']);
\$fd->setComponent('field_capacity', ['type' => 'number']);
\$fd->setComponent('field_accessibility', ['type' => 'entity_reference_autocomplete']);
\$fd->setComponent('field_external_url', ['type' => 'link_default']);
\$fd->setComponent('field_featured', ['type' => 'boolean_checkbox']);

\$fd->save();
"

# ----------------------------------------
# 6. View displays
# ----------------------------------------

echo
echo "Building view displays..."

ddev drush eval "
use Drupal\Core\Entity\Entity\EntityViewDisplay;

# Full display
\$vd = EntityViewDisplay::load('node.event.default');
if (!\$vd) {
  \$vd = EntityViewDisplay::create([
    'targetEntityType' => 'node',
    'bundle' => 'event',
    'mode' => 'default',
    'status' => TRUE,
  ]);
}

\$vd->setComponent('field_event_image', ['type' => 'image', 'label' => 'hidden']);
\$vd->setComponent('field_event_start', ['type' => 'datetime_default']);
\$vd->setComponent('field_event_end', ['type' => 'datetime_default']);
\$vd->setComponent('field_category', ['type' => 'entity_reference_label']);
\$vd->setComponent('body', ['type' => 'text_default']);

\$vd->save();

# Teaser display
\$teaser = EntityViewDisplay::load('node.event.teaser');
if (!\$teaser) {
  \$teaser = EntityViewDisplay::create([
    'targetEntityType' => 'node',
    'bundle' => 'event',
    'mode' => 'teaser',
    'status' => TRUE,
  ]);
}

\$teaser->setComponent('field_event_image', ['type' => 'image', 'label' => 'hidden']);
\$teaser->setComponent('field_event_start', ['type' => 'datetime_default']);
\$teaser->setComponent('field_category', ['type' => 'entity_reference_label']);
\$teaser->save();
"

echo
echo "Clearing caches..."
ddev drush cr

echo
echo "========================================"
echo " EVENT CONTENT TYPE INSTALLED SUCCESSFULLY"
echo "========================================"
echo