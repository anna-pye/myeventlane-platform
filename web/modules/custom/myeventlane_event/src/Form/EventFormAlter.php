<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;

/**
 * Form alteration service for Event node forms.
 */
final class EventFormAlter {

  private EntityTypeManagerInterface $entityTypeManager;
  private AccountProxyInterface $currentUser;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
  }

  public function alterForm(array &$form, FormStateInterface $form_state): void {
    // Validate form is not null or empty - but don't return early as we modify by reference
    if (empty($form) || !is_array($form)) {
      \Drupal::logger('myeventlane_event')->error('EventFormAlter: Form is empty or not an array');
      // Don't return - ensure form has minimum structure
      $form = ['#type' => 'form', '#attributes' => []];
      return;
    }

    $node = $form_state->getFormObject()->getEntity();
    if (!$node) {
      \Drupal::logger('myeventlane_event')->error('EventFormAlter: Node entity is null');
      // Don't return - just skip node-specific logic
      return;
    }
    
    $is_new = $node->isNew();

    // Ensure form has attributes array before adding class
    // Critical: form must have #attributes for template_preprocess_form to work
    if (!isset($form['#attributes'])) {
      $form['#attributes'] = [];
    }
    if (!is_array($form['#attributes'])) {
      $form['#attributes'] = [];
    }
    if (!isset($form['#attributes']['class'])) {
      $form['#attributes']['class'] = [];
    }
    if (!is_array($form['#attributes']['class'])) {
      $form['#attributes']['class'] = [];
    }
    $form['#attributes']['class'][] = 'mel-event-form-vendor';

    $this->handleVendorStore($form, $form_state, $is_new);
    $this->buildEventBasics($form, $form_state);
    $this->buildDateTime($form, $form_state);
    
    // Debug: Log before building sections
    \Drupal::logger('myeventlane_event')->debug('EventFormAlter: Building location section');
    $this->buildLocation($form, $form_state);
    \Drupal::logger('myeventlane_event')->debug('EventFormAlter: Location section built. Exists: @exists', ['@exists' => isset($form['location']) ? 'yes' : 'no']);
    
    \Drupal::logger('myeventlane_event')->debug('EventFormAlter: Building booking_config section');
    $this->buildBookingConfig($form, $form_state);
    \Drupal::logger('myeventlane_event')->debug('EventFormAlter: Booking_config section built. Exists: @exists', ['@exists' => isset($form['booking_config']) ? 'yes' : 'no']);
    
    \Drupal::logger('myeventlane_event')->debug('EventFormAlter: Building visibility section');
    $this->buildVisibility($form, $form_state);
    \Drupal::logger('myeventlane_event')->debug('EventFormAlter: Visibility section built. Exists: @exists', ['@exists' => isset($form['visibility']) ? 'yes' : 'no']);
    
    $this->buildAttendeeQuestions($form, $form_state);
    $this->buildStickyFooter($form, $form_state, $node, $is_new);
    
    // Final debug: Log all top-level form keys
    $top_level_keys = array_keys($form);
    \Drupal::logger('myeventlane_event')->debug('EventFormAlter: Final form keys: @keys', ['@keys' => implode(', ', array_filter($top_level_keys, function($key) { return !str_starts_with($key, '#'); }))]);

    // Attach libraries in the same order as Gin admin theme for compatibility
    // 1. Core Drupal form libraries (required for #states API)
    // These MUST be attached first for #states to work
    if (!in_array('core/drupal.form', $form['#attached']['library'] ?? [])) {
      $form['#attached']['library'][] = 'core/drupal.form';
    }
    if (!in_array('core/drupal.states', $form['#attached']['library'] ?? [])) {
      $form['#attached']['library'][] = 'core/drupal.states';
    }
    
    // 2. Conditional Fields library (must come after drupal.states)
    // This provides enhanced conditional field behavior beyond basic #states
    if (\Drupal::moduleHandler()->moduleExists('conditional_fields')) {
      if (!in_array('conditional_fields/conditional_fields', $form['#attached']['library'] ?? [])) {
        $form['#attached']['library'][] = 'conditional_fields/conditional_fields';
      }
    }
    
    // 3. Location autocomplete library
    if (!in_array('myeventlane_location/address_autocomplete', $form['#attached']['library'] ?? [])) {
      $form['#attached']['library'][] = 'myeventlane_location/address_autocomplete';
    }
    
    // 4. Custom event form enhancements (last, so it can override if needed)
    if (!in_array('myeventlane_theme/event-form', $form['#attached']['library'] ?? [])) {
      $form['#attached']['library'][] = 'myeventlane_theme/event-form';
    }
    
    // Note: myeventlane_location module (runs alphabetically first) already sets
    // drupalSettings['myeventlaneLocation'] with properly generated Apple Maps tokens.
    // We should NOT overwrite this here as it would destroy the generated token.
    // The location module's hook_form_node_event_form_alter() handles all location
    // settings including token generation via MapKitTokenGenerator::generateToken().
    
    // Final validation: ensure form is still valid after all alterations
    // CRITICAL: Form must always be a valid array for template_preprocess_form
    if (empty($form) || !is_array($form)) {
      \Drupal::logger('myeventlane_event')->error('EventFormAlter: Form became invalid after alterations - restoring minimum structure');
      // Restore minimum form structure - this should never happen but prevents fatal errors
      $form = array_merge(['#type' => 'form', '#attributes' => []], is_array($form) ? $form : []);
    }
    
    // Ensure form has required properties for template preprocessing
    // This is critical - template_preprocess_form requires #attributes to exist
    if (!isset($form['#attributes'])) {
      $form['#attributes'] = [];
    }
    if (!is_array($form['#attributes'])) {
      $form['#attributes'] = [];
    }
  }

  private function handleVendorStore(array &$form, FormStateInterface $form_state, bool $is_new): void {
    if (isset($form['field_event_vendor'])) {
      $vendor_id = $this->getCurrentUserVendorId();
      if ($vendor_id && $is_new) {
        // Set the value in form state (uses integer ID)
        $form_state->setValue(['field_event_vendor', 0, 'target_id'], $vendor_id);
        // For hidden fields, we don't need to set #default_value on the widget
        // The form state value will be used when the form is submitted
      }
      // Hide the field completely - it's auto-populated
      $form['field_event_vendor']['#access'] = FALSE;
    }

    if (isset($form['field_event_store'])) {
      $store_id = $this->getCurrentUserStoreId();
      if ($store_id && $is_new) {
        // Set the value in form state (uses integer ID)
        $form_state->setValue(['field_event_store', 0, 'target_id'], $store_id);
        // For hidden fields, we don't need to set #default_value on the widget
        // The form state value will be used when the form is submitted
      }
      // Hide the field completely - it's auto-populated
      $form['field_event_store']['#access'] = FALSE;
    }
  }

  private function buildEventBasics(array &$form, FormStateInterface $form_state): void {
    // Ensure form is still valid
    if (empty($form) || !is_array($form)) {
      return;
    }

    $form['event_basics'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-form-card']],
      '#weight' => -10,
    ];
    $form['event_basics']['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-form-header']],
    ];
    $form['event_basics']['header']['title'] = [
      '#markup' => '<h2 class="mel-form-title">' . t('Event Basics') . '</h2>',
    ];
    $form['event_basics']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-form-content']],
    ];

    if (isset($form['title']) && is_array($form['title'])) {
      $form['event_basics']['content']['title'] = $form['title'];
      unset($form['title']);
    }
    if (isset($form['body']) && is_array($form['body'])) {
      // Safely add attributes to body widget if it exists
      if (isset($form['body']['widget']) && is_array($form['body']['widget'])) {
        // Handle both widget[0] structure and direct widget structure
        if (isset($form['body']['widget'][0]) && is_array($form['body']['widget'][0])) {
          if (!isset($form['body']['widget'][0]['#attributes'])) {
            $form['body']['widget'][0]['#attributes'] = [];
          }
          if (!isset($form['body']['widget'][0]['#attributes']['class'])) {
            $form['body']['widget'][0]['#attributes']['class'] = [];
          }
          $form['body']['widget'][0]['#attributes']['class'][] = 'mel-hide-format-help';
        }
      }
      $form['event_basics']['content']['body'] = $form['body'];
      unset($form['body']);
    }
    if (isset($form['field_event_image']) && is_array($form['field_event_image'])) {
      $form['event_basics']['content']['field_event_image'] = $form['field_event_image'];
      unset($form['field_event_image']);
    }
  }

  private function buildDateTime(array &$form, FormStateInterface $form_state): void {
    // Ensure form is still valid
    if (empty($form) || !is_array($form)) {
      return;
    }

    $form['date_time'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-form-card']],
      '#weight' => -9,
    ];
    $form['date_time']['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-form-header']],
    ];
    $form['date_time']['header']['title'] = [
      '#markup' => '<h2 class="mel-form-title">' . t('Date & Time') . '</h2>',
    ];
    $form['date_time']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-form-content']],
    ];

    if (isset($form['field_event_start']) && is_array($form['field_event_start'])) {
      $form['date_time']['content']['field_event_start'] = $form['field_event_start'];
      unset($form['field_event_start']);
    }
    if (isset($form['field_event_end']) && is_array($form['field_event_end'])) {
      $form['date_time']['content']['field_event_end'] = $form['field_event_end'];
      unset($form['field_event_end']);
    }
  }

  private function buildLocation(array &$form, FormStateInterface $form_state): void {
    // Ensure form is still valid
    if (empty($form) || !is_array($form)) {
      \Drupal::logger('myeventlane_event')->error('buildLocation: Form is empty or not an array');
      return;
    }

    try {
      $form['location'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-form-card']],
        '#weight' => -8,
      ];
      $form['location']['header'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-form-header']],
      ];
      $form['location']['header']['title'] = [
        '#markup' => '<h2 class="mel-form-title">' . t('Location') . '</h2>',
      ];
      $form['location']['content'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-form-content']],
      ];
    } catch (\Exception $e) {
      \Drupal::logger('myeventlane_event')->error('buildLocation: Exception creating container: @message', ['@message' => $e->getMessage()]);
      return;
    }

    // STEP 1: Venue name field first
    if (isset($form['field_venue_name']) && is_array($form['field_venue_name'])) {
      $form['location']['content']['field_venue_name'] = $form['field_venue_name'];
      $form['location']['content']['field_venue_name']['#weight'] = 1;
      unset($form['field_venue_name']);
    }
    
    // STEP 2: Address field with search and form fields
    if (isset($form['field_location']) && is_array($form['field_location'])) {
      // Set default country to Australia and hide it
      if (isset($form['field_location']['widget'][0]['address']['country_code']) && is_array($form['field_location']['widget'][0]['address']['country_code'])) {
        $form['field_location']['widget'][0]['address']['country_code']['#default_value'] = 'AU';
        $form['field_location']['widget'][0]['address']['country_code']['#access'] = FALSE;
      }
      // Ensure all address fields are enabled for manual entry
      if (isset($form['field_location']['widget'][0]['address']) && is_array($form['field_location']['widget'][0]['address'])) {
        foreach ($form['field_location']['widget'][0]['address'] as $key => &$element) {
          if (is_array($element) && isset($element['#type'])) {
            $element['#disabled'] = FALSE;
          }
        }
        unset($element); // Break reference
      }
      
      // The AddressAutocompleteWidget structure:
      // - widget[0]['address_search'] - the searchable address input (weight: -10)
      // - widget[0]['address'] - the address form fields (address_line1, locality/suburb, administrative_area/state, postal_code)
      // - widget[0]['map_preview'] - the map preview container (weight: 100)
      // - widget[0]['latitude'] and widget[0]['longitude'] - hidden coordinate fields
      
      // Ensure proper ordering within the widget:
      // The widget already has correct internal ordering:
      // 1. address_search (weight: -10) - appears first within widget
      // 2. address fields (weight: 0, default) - address_line1, locality, administrative_area, postal_code
      // 3. map_preview (weight: 100) - appears last
      
      // We just need to ensure the widget itself comes after venue name
      // The internal weights are already correct, so we don't need to change them
      
      // Move the entire field_location (including all widget elements) to the location container
      $form['location']['content']['field_location'] = $form['field_location'];
      $form['location']['content']['field_location']['#weight'] = 2;
      unset($form['field_location']);
    }
  }

  private function buildBookingConfig(array &$form, FormStateInterface $form_state): void {
    // Ensure form is still valid
    if (empty($form) || !is_array($form)) {
      return;
    }

    $form['booking_config'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-form-card', 'mel-form-card-highlight']],
      '#weight' => -7,
    ];
    $form['booking_config']['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-form-header']],
    ];
    $form['booking_config']['header']['title'] = [
      '#markup' => '<h2 class="mel-form-title">' . t('Booking Configuration') . '</h2>',
    ];
    $form['booking_config']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-form-content']],
    ];

    // STEP 1: Move field_event_type FIRST - before any other fields
    if (isset($form['field_event_type']) && is_array($form['field_event_type'])) {
      // Remove ALL existing states/conditional_fields
      if (isset($form['field_event_type']['#states'])) {
        unset($form['field_event_type']['#states']);
      }
      if (isset($form['field_event_type']['widget']) && is_array($form['field_event_type']['widget'])) {
        if (isset($form['field_event_type']['widget']['#states'])) {
          unset($form['field_event_type']['widget']['#states']);
        }
        // Also clear from widget[0] if it exists
        if (isset($form['field_event_type']['widget'][0]) && is_array($form['field_event_type']['widget'][0])) {
          if (isset($form['field_event_type']['widget'][0]['#states'])) {
            unset($form['field_event_type']['widget'][0]['#states']);
          }
        }
      }
      
      // Force it to be first
      $form['field_event_type']['#weight'] = -10000;
      if (isset($form['field_event_type']['widget']) && is_array($form['field_event_type']['widget'])) {
        $form['field_event_type']['widget']['#weight'] = -10000;
      }
      
      // Move it
      $form['booking_config']['content']['field_event_type'] = $form['field_event_type'];
      unset($form['field_event_type']);
    }

    // STEP 2: Build conditional containers with proper #states
    // Use the exact selector that options_select generates
    // Drupal generates select[name="field_event_type[0][value]"] for options_select widget
    // We need to ensure the selector works with both #states API and conditional_fields
    $selector = ':input[name="field_event_type[0][value]"]';
    
    // Also add data attribute to the select for easier JavaScript targeting
    if (isset($form['booking_config']['content']['field_event_type']['widget'][0]['value'])) {
      $form['booking_config']['content']['field_event_type']['widget'][0]['value']['#attributes']['data-booking-type-select'] = 'true';
    }

    // RSVP container
    if (isset($form['field_capacity']) || isset($form['field_rsvp_target'])) {
      $form['booking_config']['content']['rsvp_fields'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-booking-rsvp'],
          'data-booking-type' => 'rsvp',
        ],
        '#weight' => 1,
        '#states' => [
          'visible' => [
            'or' => [
              [$selector => ['value' => 'rsvp']],
              [$selector => ['value' => 'both']],
            ],
          ],
        ],
      ];
      
      if (isset($form['field_capacity']) && is_array($form['field_capacity'])) {
        if (isset($form['field_capacity']['#states'])) {
          unset($form['field_capacity']['#states']);
        }
        $form['field_capacity']['#required'] = FALSE;
        $form['field_capacity']['#weight'] = 1;
        $form['booking_config']['content']['rsvp_fields']['field_capacity'] = $form['field_capacity'];
        unset($form['field_capacity']);
      }
      
      if (isset($form['field_rsvp_target']) && is_array($form['field_rsvp_target'])) {
        if (isset($form['field_rsvp_target']['#states'])) {
          unset($form['field_rsvp_target']['#states']);
        }
        $form['field_rsvp_target']['#required'] = FALSE;
        $form['field_rsvp_target']['#weight'] = 2;
        $form['booking_config']['content']['rsvp_fields']['field_rsvp_target'] = $form['field_rsvp_target'];
        unset($form['field_rsvp_target']);
      }
    }

    // Paid container
    if (isset($form['field_product_target']) || isset($form['field_ticket_types'])) {
      $form['booking_config']['content']['paid_fields'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-booking-paid'],
          'data-booking-type' => 'paid',
        ],
        '#weight' => 2,
        '#states' => [
          'visible' => [
            'or' => [
              [$selector => ['value' => 'paid']],
              [$selector => ['value' => 'both']],
            ],
          ],
        ],
      ];
      
      if (isset($form['field_product_target']) && is_array($form['field_product_target'])) {
        if (isset($form['field_product_target']['#states'])) {
          unset($form['field_product_target']['#states']);
        }
        $form['field_product_target']['#required'] = FALSE;
        $form['field_product_target']['#weight'] = 1;
        $form['booking_config']['content']['paid_fields']['field_product_target'] = $form['field_product_target'];
        unset($form['field_product_target']);
      }
      
      if (isset($form['field_ticket_types']) && is_array($form['field_ticket_types'])) {
        // Remove any existing #states from ticket_types - the parent container handles visibility
        if (isset($form['field_ticket_types']['#states'])) {
          unset($form['field_ticket_types']['#states']);
        }
        // Also check widget level
        if (isset($form['field_ticket_types']['widget']) && is_array($form['field_ticket_types']['widget'])) {
          if (isset($form['field_ticket_types']['widget']['#states'])) {
            unset($form['field_ticket_types']['widget']['#states']);
          }
        }
        $form['field_ticket_types']['#required'] = FALSE;
        $form['field_ticket_types']['#weight'] = 2;
        // Move ticket types into paid_fields container - visibility is controlled by container's #states
        $form['booking_config']['content']['paid_fields']['field_ticket_types'] = $form['field_ticket_types'];
        unset($form['field_ticket_types']);
      }
    }

    // External container
    if (isset($form['field_external_url']) && is_array($form['field_external_url'])) {
      if (isset($form['field_external_url']['#states'])) {
        unset($form['field_external_url']['#states']);
      }
      $form['booking_config']['content']['external_fields'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-booking-external'],
          'data-booking-type' => 'external',
        ],
        '#weight' => 3,
        '#states' => [
          'visible' => [
            [$selector => ['value' => 'external']],
          ],
        ],
        'field_external_url' => $form['field_external_url'],
      ];
      unset($form['field_external_url']);
    }
  }

  private function buildVisibility(array &$form, FormStateInterface $form_state): void {
    // Ensure form is still valid
    if (empty($form) || !is_array($form)) {
      return;
    }

    $form['visibility'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-form-card']],
      '#weight' => -6,
    ];
    $form['visibility']['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-form-header']],
    ];
    $form['visibility']['header']['title'] = [
      '#markup' => '<h2 class="mel-form-title">' . t('Visibility & Promotion') . '</h2>',
    ];
    $form['visibility']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-form-content']],
    ];

    $fields = ['field_category', 'field_event_categories', 'field_tags', 'field_accessibility', 'field_event_status', 'status'];
    foreach ($fields as $field_name) {
      if (isset($form[$field_name]) && is_array($form[$field_name])) {
        $form['visibility']['content'][$field_name] = $form[$field_name];
        unset($form[$field_name]);
      }
    }
  }

  private function buildAttendeeQuestions(array &$form, FormStateInterface $form_state): void {
    // Ensure form is still valid
    if (empty($form) || !is_array($form)) {
      return;
    }

    if (!isset($form['field_attendee_questions']) || !is_array($form['field_attendee_questions'])) {
      return;
    }

    $form['attendee_questions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-form-card']],
      '#weight' => -5,
    ];
    $form['attendee_questions']['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-form-header']],
    ];
    $form['attendee_questions']['header']['title'] = [
      '#markup' => '<h2 class="mel-form-title">' . t('Additional Questions') . '</h2>',
    ];
    $form['attendee_questions']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-form-content']],
    ];

    if (is_array($form['field_attendee_questions'])) {
      $form['field_attendee_questions']['#required'] = FALSE;
      $form['attendee_questions']['content']['field_attendee_questions'] = $form['field_attendee_questions'];
      unset($form['field_attendee_questions']);
    }
  }

  private function buildStickyFooter(array &$form, FormStateInterface $form_state, NodeInterface $node, bool $is_new): void {
    // Ensure form is still valid
    if (empty($form) || !is_array($form)) {
      return;
    }

    if (isset($form['actions']) && is_array($form['actions'])) {
      if (!isset($form['actions']['#attributes'])) {
        $form['actions']['#attributes'] = [];
      }
      if (!isset($form['actions']['#attributes']['class'])) {
        $form['actions']['#attributes']['class'] = [];
      }
      $form['actions']['#attributes']['class'][] = 'mel-form-footer';

      if (isset($form['actions']['submit']) && is_array($form['actions']['submit'])) {
        $form['actions']['submit']['#value'] = $is_new ? t('Publish event') : t('Save changes');
        if (!isset($form['actions']['submit']['#attributes'])) {
          $form['actions']['submit']['#attributes'] = [];
        }
        if (!isset($form['actions']['submit']['#attributes']['class'])) {
          $form['actions']['submit']['#attributes']['class'] = [];
        }
        $form['actions']['submit']['#attributes']['class'][] = 'mel-btn-primary';
      }

      $form['actions']['save_draft'] = [
        '#type' => 'submit',
        '#value' => t('Save draft'),
        '#weight' => -10,
        '#submit' => ['_myeventlane_event_save_draft'],
        '#attributes' => ['class' => ['mel-btn-secondary']],
        '#limit_validation_errors' => [],
      ];
    }
  }

  private function getCurrentUserVendorId(): ?int {
    $uid = (int) $this->currentUser->id();
    if ($uid === 0) {
      return NULL;
    }

    $vendor_ids = $this->entityTypeManager->getStorage('myeventlane_vendor')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->range(0, 1)
      ->execute();

    return !empty($vendor_ids) ? (int) reset($vendor_ids) : NULL;
  }

  private function getCurrentUserStoreId(): ?int {
    $vendor_id = $this->getCurrentUserVendorId();
    if (!$vendor_id) {
      return NULL;
    }

    $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load($vendor_id);
    if (!$vendor || !$vendor->hasField('field_vendor_store') || $vendor->get('field_vendor_store')->isEmpty()) {
      return NULL;
    }

    $store = $vendor->get('field_vendor_store')->entity;
    return $store ? (int) $store->id() : NULL;
  }

}
