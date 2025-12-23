<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Event Creation Wizard Form.
 * 
 * Multi-step wizard for creating/editing events at /vendor/events/wizard
 * 
 * Steps:
 * 1. Basics - title, category, tags, event type
 * 2. When & Where - start/end date, venue name, address, lat/lng, place_id
 * 3. Branding - hero image
 * 4. Tickets & Capacity - ticket mode, capacity, waitlist
 * 5. Content - body, optional video
 * 6. Policies & Accessibility - refund policy, accessibility fields
 * 7. Review & Publish
 */
final class EventWizardForm extends FormBase {

  private const STATE_KEY_CURRENT_STEP = 'wizard_current_step';
  private const STATE_KEY_LOCATION_MODE = 'location_mode';

  /**
   * Step definitions.
   */
  private const STEPS = [
    'basics' => ['label' => 'Basics', 'number' => 1],
    'when_where' => ['label' => 'When & Where', 'number' => 2],
    'branding' => ['label' => 'Branding', 'number' => 3],
    'tickets_capacity' => ['label' => 'Tickets & Capacity', 'number' => 4],
    'content' => ['label' => 'Content', 'number' => 5],
    'policies_accessibility' => ['label' => 'Policies & Accessibility', 'number' => 6],
    'review' => ['label' => 'Review & Publish', 'number' => 7],
  ];

  /**
   * Constructs EventWizardForm.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    RouteMatchInterface $routeMatch,
    private readonly AccountProxyInterface $currentUser,
  ) {
    $this->routeMatch = $routeMatch;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'event_wizard_form';
  }

  /**
   * Gets or creates the event node.
   */
  private function getEvent(FormStateInterface $form_state): NodeInterface {
    // Check if we have a node from route parameter.
    $node = $this->routeMatch->getParameter('node');
    if ($node instanceof NodeInterface) {
      return $node;
    }

    // Check form state for stored node.
    $stored = $form_state->get('event_node');
    if ($stored instanceof NodeInterface) {
      return $stored;
    }

    // Create new draft event.
    $storage = $this->entityTypeManager->getStorage('node');
    $event = $storage->create([
      'type' => 'event',
      'title' => 'New Event',
      'status' => 0, // Draft
      'uid' => $this->currentUser->id(),
    ]);
    $event->save();
    $form_state->set('event_node', $event);

    return $event;
  }

  /**
   * Gets current step from form state or defaults to 'basics'.
   */
  private function getCurrentStep(FormStateInterface $form_state): string {
    return $form_state->get(self::STATE_KEY_CURRENT_STEP) ?? 'basics';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $event = $this->getEvent($form_state);
    $current_step = $this->getCurrentStep($form_state);

    // Attach wizard library.
    $form['#attached']['library'][] = 'myeventlane_event/event_wizard';
    
    // Attach address autocomplete library and settings.
    $form['#attached']['library'][] = 'myeventlane_location/address_autocomplete';
    
    // Add drupalSettings for address autocomplete.
    $provider_manager = \Drupal::service('myeventlane_location.provider_manager');
    $settings = $provider_manager->getFrontendSettings();
    
    // For Apple Maps, generate token server-side.
    if ($settings['provider'] === 'apple_maps') {
      $token_generator = \Drupal::service('myeventlane_location.mapkit_token_generator');
      $token = $token_generator->generateToken();
      if (!empty($token)) {
        $settings['apple_maps_token'] = $token;
      }
    }
    
    if (!isset($form['#attached']['drupalSettings'])) {
      $form['#attached']['drupalSettings'] = [];
    }
    $form['#attached']['drupalSettings']['myeventlaneLocation'] = $settings;

    // Wizard container.
    $form['wizard'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-event-wizard'],
        'id' => 'event-wizard-wrapper',
      ],
    ];

    // Step navigation/stepper.
    $form['wizard']['navigation'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-wizard__navigation']],
    ];

    $form['wizard']['navigation']['title'] = [
      '#markup' => '<h2 class="mel-event-wizard__nav-title">Event setup</h2>',
    ];

    $form['wizard']['navigation']['steps'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-wizard__steps']],
    ];

    foreach (self::STEPS as $step_id => $step_info) {
      $is_active = ($step_id === $current_step);
      $is_completed = $this->isStepCompleted($event, $step_id);
      
      $form['wizard']['navigation']['steps'][$step_id] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => array_filter([
            'mel-event-wizard__step',
            $is_active ? 'is-active' : NULL,
            $is_completed ? 'is-completed' : NULL,
          ]),
        ],
        'number' => [
          '#markup' => '<span class="mel-event-wizard__step-number">' . $step_info['number'] . '</span>',
        ],
        'label' => [
          '#markup' => '<span class="mel-event-wizard__step-label">' . $step_info['label'] . '</span>',
        ],
      ];
    }

    $form['wizard']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-wizard__content']],
    ];

    $form['wizard']['content']['step_content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-wizard-step']],
    ];

    // Build step content based on current step.
    switch ($current_step) {
      case 'basics':
        $this->buildBasicsStep($form, $form_state, $event);
        break;

      case 'when_where':
        $this->buildWhenWhereStep($form, $form_state, $event);
        break;

      case 'branding':
        $this->buildBrandingStep($form, $form_state, $event);
        break;

      case 'tickets_capacity':
        $this->buildTicketsCapacityStep($form, $form_state, $event);
        break;

      case 'content':
        $this->buildContentStep($form, $form_state, $event);
        break;

      case 'policies_accessibility':
        $this->buildPoliciesAccessibilityStep($form, $form_state, $event);
        break;

      case 'review':
        $this->buildReviewStep($form, $form_state, $event);
        break;

      default:
        $form['wizard']['content']['step_content']['message'] = [
          '#markup' => '<p>Unknown step: ' . $current_step . '</p>',
        ];
    }

    // Navigation buttons.
    $form['wizard']['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-wizard__actions']],
    ];

    // Back button (not on first step).
    if ($current_step !== 'basics') {
      $form['wizard']['actions']['back'] = [
        '#type' => 'submit',
        '#value' => $this->t('Back'),
        '#name' => 'back',
        '#ajax' => [
          'callback' => '::ajaxCallback',
          'wrapper' => 'event-wizard-wrapper',
        ],
        '#limit_validation_errors' => [],
        '#submit' => ['::submitForm'],
      ];
    }

    // Next/Save button.
    $button_label = $current_step === 'review' ? $this->t('Publish Event') : $this->t('Continue');
    $form['wizard']['actions']['next'] = [
      '#type' => 'submit',
      '#value' => $button_label,
      '#name' => 'next',
      '#ajax' => [
        'callback' => '::ajaxCallback',
        'wrapper' => 'event-wizard-wrapper',
      ],
      '#submit' => ['::submitForm'],
    ];

    // Save draft button (all steps except review).
    if ($current_step !== 'review') {
      $form['wizard']['actions']['save_draft'] = [
        '#type' => 'submit',
        '#value' => $this->t('Save Draft'),
        '#name' => 'save_draft',
        '#ajax' => [
          'callback' => '::ajaxCallback',
          'wrapper' => 'event-wizard-wrapper',
        ],
        '#limit_validation_errors' => [],
        '#submit' => ['::submitForm'],
      ];
    }

    $form['#prefix'] = '<div id="event-wizard-wrapper">';
    $form['#suffix'] = '</div>';

    return $form;
  }

  /**
   * Checks if a step is completed (has data).
   */
  private function isStepCompleted(NodeInterface $event, string $step_id): bool {
    return match ($step_id) {
      'basics' => !$event->getTitle()->isEmpty() && $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty(),
      'when_where' => $event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty(),
      'branding' => $event->hasField('field_event_image') && !$event->get('field_event_image')->isEmpty(),
      'tickets_capacity' => TRUE, // Always show as available
      'content' => $event->hasField('body') && !$event->get('body')->isEmpty(),
      'policies_accessibility' => $event->hasField('field_refund_policy') && !$event->get('field_refund_policy')->isEmpty(),
      'review' => FALSE,
      default => FALSE,
    };
  }

  /**
   * Builds Basics step (Step 1).
   */
  private function buildBasicsStep(array &$form, FormStateInterface $form_state, NodeInterface $event): void {
    $form['wizard']['content']['step_content']['section'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-wizard-step-content']],
    ];

    $form['wizard']['content']['step_content']['section']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Event title'),
      '#required' => TRUE,
      '#default_value' => $event->getTitle(),
      '#attributes' => ['class' => ['mel-form-field']],
    ];

    // Event type.
    if ($event->hasField('field_event_type')) {
      $form['wizard']['content']['step_content']['section']['field_event_type'] = [
        '#type' => 'select',
        '#title' => $this->t('Event type'),
        '#options' => [
          'rsvp' => $this->t('RSVP (free)'),
          'paid' => $this->t('Paid (ticketed)'),
          'both' => $this->t('Both (paid and RSVP)'),
          'external' => $this->t('External link'),
        ],
        '#default_value' => $event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()
          ? $event->get('field_event_type')->value
          : 'rsvp',
        '#required' => TRUE,
        '#attributes' => ['class' => ['mel-form-field']],
      ];
    }

    // Category (required).
    if ($event->hasField('field_category')) {
      $default_categories = [];
      if (!$event->get('field_category')->isEmpty()) {
        foreach ($event->get('field_category')->referencedEntities() as $term) {
          if ($term instanceof TermInterface) {
            $default_categories[] = $term->id();
          }
        }
      }

      $form['wizard']['content']['step_content']['section']['field_category'] = [
        '#type' => 'entity_autocomplete',
        '#target_type' => 'taxonomy_term',
        '#selection_handler' => 'default',
        '#selection_settings' => [
          'target_bundles' => ['categories'],
        ],
        '#title' => $this->t('Category'),
        '#description' => $this->t('Select the primary category for this event.'),
        '#default_value' => !empty($default_categories) ? $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($default_categories) : NULL,
        '#required' => TRUE,
        '#tags' => FALSE,
        '#attributes' => ['class' => ['mel-form-field']],
      ];
    }

    // Tags (optional).
    if ($event->hasField('field_tags')) {
      $default_tags = [];
      if (!$event->get('field_tags')->isEmpty()) {
        foreach ($event->get('field_tags')->referencedEntities() as $term) {
          if ($term instanceof TermInterface) {
            $default_tags[] = $term->id();
          }
        }
      }

      $form['wizard']['content']['step_content']['section']['field_tags'] = [
        '#type' => 'entity_autocomplete',
        '#target_type' => 'taxonomy_term',
        '#selection_handler' => 'default',
        '#selection_settings' => [
          'target_bundles' => ['tags'],
        ],
        '#title' => $this->t('Tags'),
        '#description' => $this->t('Optional: Add tags to help people find your event.'),
        '#default_value' => !empty($default_tags) ? $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($default_tags) : NULL,
        '#required' => FALSE,
        '#tags' => TRUE,
        '#attributes' => ['class' => ['mel-form-field']],
      ];
    }
  }

  /**
   * Builds When & Where step (Step 2).
   */
  private function buildWhenWhereStep(array &$form, FormStateInterface $form_state, NodeInterface $event): void {
    $form['wizard']['content']['step_content']['section'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-wizard-step-content']],
    ];

    // Date & Time section.
    $form['wizard']['content']['step_content']['section']['date_time'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Date & Time'),
    ];

    // Start date.
    if ($event->hasField('field_event_start')) {
      $start_default = NULL;
      if (!$event->get('field_event_start')->isEmpty()) {
        $start_value = $event->get('field_event_start')->value;
        $start_default = $start_value ? date('Y-m-d\TH:i:s', strtotime($start_value)) : NULL;
      }

      $form['wizard']['content']['step_content']['section']['date_time']['field_event_start'] = [
        '#type' => 'datetime',
        '#title' => $this->t('Start date & time'),
        '#required' => TRUE,
        '#default_value' => $start_default,
        '#date_time_element' => 'datetime',
        '#attributes' => ['class' => ['mel-form-field']],
      ];
    }

    // End date (optional).
    if ($event->hasField('field_event_end')) {
      $end_default = NULL;
      if (!$event->get('field_event_end')->isEmpty()) {
        $end_value = $event->get('field_event_end')->value;
        $end_default = $end_value ? date('Y-m-d\TH:i:s', strtotime($end_value)) : NULL;
      }

      $form['wizard']['content']['step_content']['section']['date_time']['field_event_end'] = [
        '#type' => 'datetime',
        '#title' => $this->t('End date & time'),
        '#required' => FALSE,
        '#default_value' => $end_default,
        '#date_time_element' => 'datetime',
        '#attributes' => ['class' => ['mel-form-field']],
      ];
    }

    // Location section.
    $form['wizard']['content']['step_content']['section']['location'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Location'),
    ];

    // Location mode: in_person or online.
    $location_mode = $form_state->get(self::STATE_KEY_LOCATION_MODE) ?? 'in_person';
    if ($event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
      $location_mode = 'in_person';
    }
    elseif ($event->hasField('field_external_url') && !$event->get('field_external_url')->isEmpty()) {
      $location_mode = 'online';
    }

    $form['wizard']['content']['step_content']['section']['location']['location_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Event location type'),
      '#options' => [
        'in_person' => $this->t('In-person event'),
        'online' => $this->t('Online event'),
      ],
      '#default_value' => $location_mode,
      '#required' => TRUE,
    ];

    // Venue name.
    if ($event->hasField('field_venue_name')) {
      $form['wizard']['content']['step_content']['section']['location']['field_venue_name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Venue name'),
        '#description' => $this->t('Optional: The name of the venue or location.'),
        '#default_value' => $event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty()
          ? $event->get('field_venue_name')->value
          : '',
        '#states' => [
          'visible' => [
            ':input[name="location[location_mode]"]' => ['value' => 'in_person'],
          ],
        ],
        '#attributes' => ['class' => ['mel-form-field']],
      ];
    }

    // Address field.
    if ($event->hasField('field_location')) {
      $existing_address = [];
      if (!$event->get('field_location')->isEmpty()) {
        $raw_existing = $event->get('field_location')->first()->getValue();
        $existing_address = $this->normaliseAddressInput($raw_existing);
      }

      if ($existing_address === []) {
        $existing_address = ['country_code' => 'AU'];
      }

      // Address search field.
      $form['wizard']['content']['step_content']['section']['location']['field_location_address_search'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Search for address or venue'),
        '#description' => $this->t('Start typing to search for an address or venue. Select from the suggestions to automatically fill in the address details.'),
        '#attributes' => [
          'class' => ['myeventlane-location-address-search'],
          'autocomplete' => 'off',
          'placeholder' => $this->t('Type address or venue name...'),
          'data-address-search' => 'true',
        ],
        '#weight' => -1,
        '#states' => [
          'visible' => [
            ':input[name="location[location_mode]"]' => ['value' => 'in_person'],
          ],
        ],
      ];

      $form['wizard']['content']['step_content']['section']['location']['field_location'] = [
        '#type' => 'address',
        '#title' => $this->t('Full address'),
        '#description' => $this->t('Start typing in "Search for address" to auto-fill this for you.'),
        '#default_value' => $existing_address,
        '#available_countries' => ['AU'],
        '#required' => FALSE,
        '#states' => [
          'visible' => [
            ':input[name="location[location_mode]"]' => ['value' => 'in_person'],
          ],
          'required' => [
            ':input[name="location[location_mode]"]' => ['value' => 'in_person'],
          ],
        ],
        '#attributes' => [
          'class' => ['mel-form-field', 'myeventlane-location-address-widget'],
          'data-mel-address' => 'field_location',
        ],
        '#after_build' => [[static::class, 'processAddressElementHideFields']],
        '#attached' => [
          'library' => ['myeventlane_location/address_autocomplete'],
        ],
      ];
    }

    // Hidden fields for lat/lng/place_id.
    if ($event->hasField('field_location_latitude')) {
      $existing_lat = $event->hasField('field_location_latitude') && !$event->get('field_location_latitude')->isEmpty()
        ? $event->get('field_location_latitude')->value
        : NULL;
      
      $form['wizard']['content']['step_content']['section']['location']['field_location_latitude'] = [
        '#type' => 'hidden',
        '#default_value' => $existing_lat,
        '#attributes' => ['class' => ['myeventlane-location-latitude']],
      ];
    }

    if ($event->hasField('field_location_longitude')) {
      $existing_lng = $event->hasField('field_location_longitude') && !$event->get('field_location_longitude')->isEmpty()
        ? $event->get('field_location_longitude')->value
        : NULL;
      
      $form['wizard']['content']['step_content']['section']['location']['field_location_longitude'] = [
        '#type' => 'hidden',
        '#default_value' => $existing_lng,
        '#attributes' => ['class' => ['myeventlane-location-longitude']],
      ];
    }

    if ($event->hasField('field_location_place_id')) {
      $existing_place_id = $event->hasField('field_location_place_id') && !$event->get('field_location_place_id')->isEmpty()
        ? $event->get('field_location_place_id')->value
        : '';
      
      $form['wizard']['content']['step_content']['section']['location']['field_location_place_id'] = [
        '#type' => 'hidden',
        '#default_value' => $existing_place_id,
        '#attributes' => ['class' => ['mel-place-id']],
      ];
    }

    // Online URL.
    if ($event->hasField('field_external_url')) {
      $external_url = '';
      if (!$event->get('field_external_url')->isEmpty()) {
        $link_item = $event->get('field_external_url')->first();
        $external_url = $link_item ? $link_item->get('uri')->getString() : '';
      }

      $form['wizard']['content']['step_content']['section']['location']['field_external_url'] = [
        '#type' => 'url',
        '#title' => $this->t('Online event URL'),
        '#description' => $this->t('The link where people can join your online event (e.g., Zoom, YouTube, etc.).'),
        '#default_value' => $external_url,
        '#states' => [
          'visible' => [
            ':input[name="location[location_mode]"]' => ['value' => 'online'],
          ],
          'required' => [
            ':input[name="location[location_mode]"]' => ['value' => 'online'],
          ],
        ],
        '#attributes' => ['class' => ['mel-form-field']],
      ];
    }
  }

  /**
   * Builds Branding step (Step 3).
   */
  private function buildBrandingStep(array &$form, FormStateInterface $form_state, NodeInterface $event): void {
    $form['wizard']['content']['step_content']['section'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-wizard-step-content']],
    ];

    // Hero image.
    if ($event->hasField('field_event_image')) {
      $default_image = NULL;
      if (!$event->get('field_event_image')->isEmpty()) {
        $default_image = [$event->get('field_event_image')->target_id];
      }

      $form['wizard']['content']['step_content']['section']['field_event_image'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Hero image'),
        '#description' => $this->t('Upload a high-quality image for your event. This will be displayed prominently on your event page.'),
        '#upload_location' => 'public://event_images/',
        '#default_value' => $default_image,
        '#upload_validators' => [
          'file_validate_extensions' => ['png gif jpg jpeg webp'],
          'file_validate_size' => [5242880], // 5MB
        ],
        '#attributes' => ['class' => ['mel-form-field']],
      ];
    }
  }

  /**
   * Builds Tickets & Capacity step (Step 4).
   */
  private function buildTicketsCapacityStep(array &$form, FormStateInterface $form_state, NodeInterface $event): void {
    $form['wizard']['content']['step_content']['section'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-wizard-step-content']],
    ];

    // Capacity.
    if ($event->hasField('field_capacity')) {
      $form['wizard']['content']['step_content']['section']['field_capacity'] = [
        '#type' => 'number',
        '#title' => $this->t('Maximum capacity'),
        '#description' => $this->t('Enter the maximum number of attendees. Leave empty or set to 0 for unlimited capacity.'),
        '#default_value' => $event->hasField('field_capacity') && !$event->get('field_capacity')->isEmpty()
          ? $event->get('field_capacity')->value
          : NULL,
        '#min' => 0,
        '#step' => 1,
        '#attributes' => ['class' => ['mel-form-field']],
      ];
    }

    // Waitlist capacity.
    if ($event->hasField('field_waitlist_capacity')) {
      $form['wizard']['content']['step_content']['section']['field_waitlist_capacity'] = [
        '#type' => 'number',
        '#title' => $this->t('Waitlist capacity'),
        '#description' => $this->t('Optional: Maximum number of people who can join the waitlist. Leave empty for unlimited waitlist.'),
        '#default_value' => $event->hasField('field_waitlist_capacity') && !$event->get('field_waitlist_capacity')->isEmpty()
          ? $event->get('field_waitlist_capacity')->value
          : NULL,
        '#min' => 0,
        '#step' => 1,
        '#attributes' => ['class' => ['mel-form-field']],
      ];
    }
  }

  /**
   * Builds Content step (Step 5).
   */
  private function buildContentStep(array &$form, FormStateInterface $form_state, NodeInterface $event): void {
    $form['wizard']['content']['step_content']['section'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-wizard-step-content']],
    ];

    // Body (description).
    if ($event->hasField('body')) {
      $form['wizard']['content']['step_content']['section']['body'] = [
        '#type' => 'text_format',
        '#title' => $this->t('About this event'),
        '#description' => $this->t('Describe your event. What will attendees experience? What should they know?'),
        '#default_value' => $event->hasField('body') && !$event->get('body')->isEmpty()
          ? $event->get('body')->value
          : '',
        '#format' => $event->hasField('body') && !$event->get('body')->isEmpty()
          ? $event->get('body')->format
          : 'basic_html',
        '#required' => FALSE,
        '#attributes' => ['class' => ['mel-form-field']],
      ];
    }

    // Optional video field (if exists).
    if ($event->hasField('field_event_video')) {
      $video_url = '';
      if (!$event->get('field_event_video')->isEmpty()) {
        $video_item = $event->get('field_event_video')->first();
        $video_url = $video_item ? $video_item->get('uri')->getString() : '';
      }

      $form['wizard']['content']['step_content']['section']['field_event_video'] = [
        '#type' => 'url',
        '#title' => $this->t('Video URL'),
        '#description' => $this->t('Optional: Add a video URL (YouTube, Vimeo, etc.) to showcase your event.'),
        '#default_value' => $video_url,
        '#attributes' => ['class' => ['mel-form-field']],
      ];
    }
  }

  /**
   * Builds Policies & Accessibility step (Step 6).
   */
  private function buildPoliciesAccessibilityStep(array &$form, FormStateInterface $form_state, NodeInterface $event): void {
    $form['wizard']['content']['step_content']['section'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-wizard-step-content']],
    ];

    // Refund policy.
    if ($event->hasField('field_refund_policy')) {
      $default_policy = '7_days';
      if (!$event->get('field_refund_policy')->isEmpty()) {
        $default_policy = $event->get('field_refund_policy')->value;
      }

      $form['wizard']['content']['step_content']['section']['field_refund_policy'] = [
        '#type' => 'select',
        '#title' => $this->t('Refund policy'),
        '#options' => [
          'none_specified' => $this->t('No specified policy on refunds'),
          '1_day' => $this->t('Refunds available up to 1 day prior'),
          '7_days' => $this->t('Refunds available up to 7 days prior'),
          '14_days' => $this->t('Refunds available up to 14 days prior'),
          '30_days' => $this->t('Refunds available up to 30 days prior'),
          'no_refunds' => $this->t('No refunds'),
        ],
        '#default_value' => $default_policy,
        '#required' => FALSE,
        '#attributes' => ['class' => ['mel-form-field']],
      ];
    }

    // Accessibility fields.
    if ($event->hasField('field_accessibility')) {
      $default_accessibility = [];
      if (!$event->get('field_accessibility')->isEmpty()) {
        foreach ($event->get('field_accessibility')->referencedEntities() as $term) {
          if ($term instanceof TermInterface) {
            $default_accessibility[] = $term->id();
          }
        }
      }

      $form['wizard']['content']['step_content']['section']['field_accessibility'] = [
        '#type' => 'entity_autocomplete',
        '#target_type' => 'taxonomy_term',
        '#selection_handler' => 'default',
        '#selection_settings' => [
          'target_bundles' => ['accessibility'],
        ],
        '#title' => $this->t('Accessibility features'),
        '#description' => $this->t('Select accessibility features available at your event.'),
        '#default_value' => !empty($default_accessibility) ? $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($default_accessibility) : NULL,
        '#required' => FALSE,
        '#tags' => TRUE,
        '#attributes' => ['class' => ['mel-form-field']],
      ];
    }
  }

  /**
   * Builds Review & Publish step (Step 7).
   */
  private function buildReviewStep(array &$form, FormStateInterface $form_state, NodeInterface $event): void {
    $form['wizard']['content']['step_content']['section'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-wizard-step-content', 'mel-wizard-review']],
    ];

    $form['wizard']['content']['step_content']['section']['title'] = [
      '#markup' => '<h3>' . $this->t('Review your event') . '</h3>',
    ];

    // Summary of all entered data.
    $summary = [];

    // Basics.
    $summary['basics'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Basics'),
    ];
    $summary['basics']['title'] = [
      '#markup' => '<p><strong>' . $this->t('Title:') . '</strong> ' . ($event->getTitle() ?: $this->t('Not set')) . '</p>',
    ];
    if ($event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()) {
      $event_type = $event->get('field_event_type')->value;
      $type_labels = [
        'rsvp' => $this->t('RSVP (free)'),
        'paid' => $this->t('Paid (ticketed)'),
        'both' => $this->t('Both (paid and RSVP)'),
        'external' => $this->t('External link'),
      ];
      $summary['basics']['type'] = [
        '#markup' => '<p><strong>' . $this->t('Event type:') . '</strong> ' . ($type_labels[$event_type] ?? $event_type) . '</p>',
      ];
    }

    // When & Where.
    $summary['when_where'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('When & Where'),
    ];
    if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      $start_date = \Drupal::service('date.formatter')->format(strtotime($event->get('field_event_start')->value), 'custom', 'l j F Y g:ia');
      $summary['when_where']['start'] = [
        '#markup' => '<p><strong>' . $this->t('Start:') . '</strong> ' . $start_date . '</p>',
      ];
    }
    if ($event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty()) {
      $summary['when_where']['venue'] = [
        '#markup' => '<p><strong>' . $this->t('Venue:') . '</strong> ' . $event->get('field_venue_name')->value . '</p>',
      ];
    }

    // Publish status.
    $form['wizard']['content']['step_content']['section']['publish_status'] = [
      '#type' => 'radios',
      '#title' => $this->t('Publish status'),
      '#options' => [
        '0' => $this->t('Save as draft'),
        '1' => $this->t('Publish immediately'),
      ],
      '#default_value' => $event->isPublished() ? '1' : '0',
      '#required' => TRUE,
    ];

    $form['wizard']['content']['step_content']['section']['summary'] = $summary;
  }

  /**
   * Normalise address input from the wizard form.
   */
  private function normaliseAddressInput(mixed $raw): array {
    if (!is_array($raw) || $raw === []) {
      return [];
    }

    // If delta-based, take first item.
    if (isset($raw[0]) && is_array($raw[0])) {
      $raw = $raw[0];
    }

    // If nested under 'address', unwrap.
    if (isset($raw['address']) && is_array($raw['address'])) {
      $raw = $raw['address'];
    }

    $allowed = [
      'country_code',
      'address_line1',
      'address_line2',
      'locality',
      'administrative_area',
      'postal_code',
      'dependent_locality',
      'sorting_code',
      'organization',
      'given_name',
      'additional_name',
      'family_name',
    ];

    $out = [];
    foreach ($allowed as $key) {
      if (!array_key_exists($key, $raw)) {
        continue;
      }
      $v = $raw[$key];

      // Unwrap common shapes.
      if (is_array($v)) {
        if (array_key_exists('value', $v)) {
          $v = $v['value'];
        }
        elseif (isset($v[0])) {
          $v = $v[0];
        }
      }

      if ($v === NULL) {
        continue;
      }

      $v = trim((string) $v);
      if ($v === '') {
        continue;
      }

      $out[$key] = $v;
    }

    // Default AU if missing.
    if (!isset($out['country_code'])) {
      $out['country_code'] = 'AU';
    }

    return $out;
  }

  /**
   * After-build callback to add CSS classes to address component fields.
   */
  public static function processAddressElementHideFields(array $element, FormStateInterface $form_state): array {
    $components = [
      'address_line1',
      'address_line2',
      'locality',
      'administrative_area',
      'postal_code',
      'country_code',
    ];

    foreach ($components as $component) {
      if (isset($element[$component])) {
        if (!isset($element[$component]['#attributes'])) {
          $element[$component]['#attributes'] = [];
        }
        if (!isset($element[$component]['#attributes']['class'])) {
          $element[$component]['#attributes']['class'] = [];
        }
        $element[$component]['#attributes']['class'][] = 'mel-address-component';
        $element[$component]['#attributes']['data-mel-address-component'] = $component;
      }
      
      if (isset($element['widget']) && is_array($element['widget'])) {
        foreach ($element['widget'] as $delta => &$widget_element) {
          if (is_numeric($delta) && isset($widget_element['address'][$component])) {
            if (!isset($widget_element['address'][$component]['#attributes'])) {
              $widget_element['address'][$component]['#attributes'] = [];
            }
            if (!isset($widget_element['address'][$component]['#attributes']['class'])) {
              $widget_element['address'][$component]['#attributes']['class'] = [];
            }
            $widget_element['address'][$component]['#attributes']['class'][] = 'mel-address-component';
            $widget_element['address'][$component]['#attributes']['data-mel-address-component'] = $component;
          }
        }
      }
    }

    return $element;
  }

  /**
   * Validates form based on current step.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $event = $this->getEvent($form_state);
    $current_step = $this->getCurrentStep($form_state);
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'] ?? '';

    // Skip validation for "Back" and "Save Draft" buttons.
    if (in_array($button_name, ['back', 'save_draft'], TRUE)) {
      return;
    }

    switch ($current_step) {
      case 'basics':
        $this->validateBasicsStep($form, $form_state, $event);
        break;

      case 'when_where':
        $this->validateWhenWhereStep($form, $form_state, $event);
        break;

      case 'tickets_capacity':
        $this->validateTicketsCapacityStep($form, $form_state, $event);
        break;
    }
  }

  /**
   * Validates Basics step.
   */
  private function validateBasicsStep(array &$form, FormStateInterface $form_state, NodeInterface $event): void {
    $values = $form_state->getValues();
    
    if (empty($values['title'])) {
      $form_state->setErrorByName('title', $this->t('Event title is required.'));
    }
  }

  /**
   * Validates When & Where step.
   */
  private function validateWhenWhereStep(array &$form, FormStateInterface $form_state, NodeInterface $event): void {
    $values = $form_state->getValues();
    $location_mode = $values['location']['location_mode'] ?? 'in_person';

    // Validate start date.
    if (empty($values['date_time']['field_event_start'])) {
      $form_state->setErrorByName('date_time][field_event_start', $this->t('Start date & time is required.'));
    }

    // Validate end date is after start date.
    if (!empty($values['date_time']['field_event_start']) && !empty($values['date_time']['field_event_end'])) {
      $start = strtotime($values['date_time']['field_event_start']);
      $end = strtotime($values['date_time']['field_event_end']);
      if ($end <= $start) {
        $form_state->setErrorByName('date_time][field_event_end', $this->t('End date must be after start date.'));
      }
    }

    // Validate location based on mode.
    if ($location_mode === 'in_person') {
      $raw = $values['location']['field_location'] ?? [];
      $address = $this->normaliseAddressInput($raw);

      $has_line1 = !empty($address['address_line1']);
      $has_locality = !empty($address['locality']);
      $has_state = !empty($address['administrative_area']);
      $has_postcode = !empty($address['postal_code']);

      if (!$has_line1 || !$has_locality || !$has_state || !$has_postcode) {
        $form_state->setErrorByName(
          'location][field_location',
          $this->t('Please select a full address (street, suburb, state, and postcode).')
        );
      }
    }
    elseif ($location_mode === 'online') {
      $url = $values['location']['field_external_url'] ?? '';
      if (empty($url)) {
        $form_state->setErrorByName('location][field_external_url', $this->t('Online event URL is required for online events.'));
      }
    }
  }

  /**
   * Validates Tickets & Capacity step.
   */
  private function validateTicketsCapacityStep(array &$form, FormStateInterface $form_state, NodeInterface $event): void {
    $values = $form_state->getValues();
    
    // Validate capacity is positive if set.
    if (isset($values['field_capacity']) && $values['field_capacity'] !== '' && (int) $values['field_capacity'] < 0) {
      $form_state->setErrorByName('field_capacity', $this->t('Capacity must be a positive number or zero.'));
    }

    // Validate waitlist capacity is positive if set.
    if (isset($values['field_waitlist_capacity']) && $values['field_waitlist_capacity'] !== '' && (int) $values['field_waitlist_capacity'] < 0) {
      $form_state->setErrorByName('field_waitlist_capacity', $this->t('Waitlist capacity must be a positive number or zero.'));
    }
  }

  /**
   * Saves step data to the event entity.
   */
  private function saveStepData(NodeInterface $event, string $step, array $values, FormStateInterface $form_state): void {
    switch ($step) {
      case 'basics':
        $this->saveBasicsData($event, $values, $form_state);
        break;

      case 'when_where':
        $this->saveWhenWhereData($event, $values, $form_state);
        break;

      case 'branding':
        $this->saveBrandingData($event, $values, $form_state);
        break;

      case 'tickets_capacity':
        $this->saveTicketsCapacityData($event, $values, $form_state);
        break;

      case 'content':
        $this->saveContentData($event, $values, $form_state);
        break;

      case 'policies_accessibility':
        $this->savePoliciesAccessibilityData($event, $values, $form_state);
        break;

      case 'review':
        $this->saveReviewData($event, $values, $form_state);
        break;
    }
  }

  /**
   * Saves Basics step data.
   */
  private function saveBasicsData(NodeInterface $event, array $values, FormStateInterface $form_state): void {
    if (isset($values['title'])) {
      $event->setTitle($values['title']);
    }

    if (isset($values['field_event_type']) && $event->hasField('field_event_type')) {
      $event->set('field_event_type', $values['field_event_type']);
    }

    if (isset($values['field_category']) && $event->hasField('field_category')) {
      $category_value = $values['field_category'];
      // Entity autocomplete can return entity objects or IDs.
      if (is_object($category_value)) {
        $category_ids = [$category_value->id()];
      }
      elseif (is_array($category_value)) {
        $category_ids = array_map(function ($item) {
          return is_object($item) ? $item->id() : $item;
        }, $category_value);
      }
      else {
        $category_ids = [$category_value];
      }
      $category_ids = array_filter($category_ids);
      $event->set('field_category', $category_ids);
    }

    if (isset($values['field_tags']) && $event->hasField('field_tags')) {
      $tag_value = $values['field_tags'];
      // Entity autocomplete can return entity objects or IDs.
      if (is_object($tag_value)) {
        $tag_ids = [$tag_value->id()];
      }
      elseif (is_array($tag_value)) {
        $tag_ids = array_map(function ($item) {
          return is_object($item) ? $item->id() : $item;
        }, $tag_value);
      }
      else {
        $tag_ids = [$tag_value];
      }
      $tag_ids = array_filter($tag_ids);
      $event->set('field_tags', $tag_ids);
    }
  }

  /**
   * Saves When & Where step data.
   */
  private function saveWhenWhereData(NodeInterface $event, array $values, FormStateInterface $form_state): void {
    // Save dates.
    if (isset($values['date_time']['field_event_start']) && $event->hasField('field_event_start')) {
      $start_value = $values['date_time']['field_event_start'];
      // Handle datetime widget format.
      if (is_array($start_value)) {
        if (isset($start_value['date']) && isset($start_value['time'])) {
          $start_value = $start_value['date'] . 'T' . $start_value['time'] . ':00';
        }
        elseif (isset($start_value['value'])) {
          $start_value = $start_value['value'];
        }
      }
      // Handle string format (Y-m-d\TH:i:s).
      if (is_string($start_value) && !empty($start_value)) {
        $event->set('field_event_start', $start_value);
      }
    }

    if (isset($values['date_time']['field_event_end']) && $event->hasField('field_event_end')) {
      $end_value = $values['date_time']['field_event_end'];
      // Handle datetime widget format.
      if (is_array($end_value)) {
        if (isset($end_value['date']) && isset($end_value['time'])) {
          $end_value = $end_value['date'] . 'T' . $end_value['time'] . ':00';
        }
        elseif (isset($end_value['value'])) {
          $end_value = $end_value['value'];
        }
      }
      // Handle string format (Y-m-d\TH:i:s).
      if (is_string($end_value) && !empty($end_value)) {
        $event->set('field_event_end', $end_value);
      }
      else {
        // Clear end date if empty.
        $event->set('field_event_end', NULL);
      }
    }

    // Save location data.
    $location_mode = $values['location']['location_mode'] ?? 'in_person';
    $form_state->set(self::STATE_KEY_LOCATION_MODE, $location_mode);

    if ($location_mode === 'in_person') {
      // Venue name.
      if (isset($values['location']['field_venue_name']) && $event->hasField('field_venue_name')) {
        $event->set('field_venue_name', $values['location']['field_venue_name']);
      }

      // Address.
      if (isset($values['location']['field_location']) && $event->hasField('field_location')) {
        $raw = $values['location']['field_location'];
        $clean_address = $this->normaliseAddressInput($raw);

        if (!empty($clean_address['address_line1']) || !empty($clean_address['locality'])) {
          $event->set('field_location', $clean_address);
        }
        else {
          $event->set('field_location', NULL);
        }
      }

      // Latitude/longitude.
      if (isset($values['location']['field_location_latitude']) && $event->hasField('field_location_latitude')) {
        $lat = $values['location']['field_location_latitude'];
        if (!empty($lat)) {
          $event->set('field_location_latitude', $lat);
        }
      }
      if (isset($values['location']['field_location_longitude']) && $event->hasField('field_location_longitude')) {
        $lng = $values['location']['field_location_longitude'];
        if (!empty($lng)) {
          $event->set('field_location_longitude', $lng);
        }
      }

      // Place ID.
      if (isset($values['location']['field_location_place_id']) && $event->hasField('field_location_place_id')) {
        $place_id = trim($values['location']['field_location_place_id'] ?? '');
        $event->set('field_location_place_id', $place_id);
      }

      // Clear online URL.
      if ($event->hasField('field_external_url')) {
        $event->set('field_external_url', NULL);
      }
    }
    else {
      // Online mode.
      if (isset($values['location']['field_external_url']) && $event->hasField('field_external_url')) {
        $event->set('field_external_url', [
          'uri' => $values['location']['field_external_url'],
          'title' => '',
        ]);
      }

      // Clear address fields.
      if ($event->hasField('field_location')) {
        $event->set('field_location', NULL);
      }
      if ($event->hasField('field_venue_name')) {
        $event->set('field_venue_name', NULL);
      }
      if ($event->hasField('field_location_place_id')) {
        $event->set('field_location_place_id', NULL);
      }
    }
  }

  /**
   * Saves Branding step data.
   */
  private function saveBrandingData(NodeInterface $event, array $values, FormStateInterface $form_state): void {
    if (isset($values['field_event_image']) && $event->hasField('field_event_image')) {
      $fids = is_array($values['field_event_image']) ? $values['field_event_image'] : [$values['field_event_image']];
      $fids = array_filter($fids);
      
      if (!empty($fids)) {
        // Mark files as permanent.
        foreach ($fids as $fid) {
          $file = \Drupal::entityTypeManager()->getStorage('file')->load($fid);
          if ($file) {
            $file->setPermanent();
            $file->save();
          }
        }
        $event->set('field_event_image', $fids);
      }
      else {
        $event->set('field_event_image', NULL);
      }
    }
  }

  /**
   * Saves Tickets & Capacity step data.
   */
  private function saveTicketsCapacityData(NodeInterface $event, array $values, FormStateInterface $form_state): void {
    if (isset($values['field_capacity']) && $event->hasField('field_capacity')) {
      $capacity = $values['field_capacity'] === '' ? NULL : (int) $values['field_capacity'];
      $event->set('field_capacity', $capacity);
    }

    if (isset($values['field_waitlist_capacity']) && $event->hasField('field_waitlist_capacity')) {
      $waitlist = $values['field_waitlist_capacity'] === '' ? NULL : (int) $values['field_waitlist_capacity'];
      $event->set('field_waitlist_capacity', $waitlist);
    }
  }

  /**
   * Saves Content step data.
   */
  private function saveContentData(NodeInterface $event, array $values, FormStateInterface $form_state): void {
    if (isset($values['body']) && $event->hasField('body')) {
      $body_value = is_array($values['body']) ? ($values['body']['value'] ?? '') : $values['body'];
      $body_format = is_array($values['body']) ? ($values['body']['format'] ?? 'basic_html') : 'basic_html';
      $event->set('body', [
        'value' => $body_value,
        'format' => $body_format,
      ]);
    }

    if (isset($values['field_event_video']) && $event->hasField('field_event_video')) {
      $video_url = $values['field_event_video'];
      if (!empty($video_url)) {
        $event->set('field_event_video', [
          'uri' => $video_url,
          'title' => '',
        ]);
      }
      else {
        $event->set('field_event_video', NULL);
      }
    }
  }

  /**
   * Saves Policies & Accessibility step data.
   */
  private function savePoliciesAccessibilityData(NodeInterface $event, array $values, FormStateInterface $form_state): void {
    if (isset($values['field_refund_policy']) && $event->hasField('field_refund_policy')) {
      $event->set('field_refund_policy', $values['field_refund_policy']);
    }

    if (isset($values['field_accessibility']) && $event->hasField('field_accessibility')) {
      $accessibility_value = $values['field_accessibility'];
      // Entity autocomplete can return entity objects or IDs.
      if (is_object($accessibility_value)) {
        $accessibility_ids = [$accessibility_value->id()];
      }
      elseif (is_array($accessibility_value)) {
        $accessibility_ids = array_map(function ($item) {
          return is_object($item) ? $item->id() : $item;
        }, $accessibility_value);
      }
      else {
        $accessibility_ids = [$accessibility_value];
      }
      $accessibility_ids = array_filter($accessibility_ids);
      $event->set('field_accessibility', $accessibility_ids);
    }
  }

  /**
   * Saves Review step data (publish status).
   */
  private function saveReviewData(NodeInterface $event, array $values, FormStateInterface $form_state): void {
    if (isset($values['publish_status'])) {
      $event->setPublished((bool) $values['publish_status']);
    }
  }

  /**
   * AJAX callback for step navigation.
   */
  public function ajaxCallback(array &$form, FormStateInterface $form_state): array {
    if (isset($form['wizard'])) {
      return $form['wizard'];
    }
    
    \Drupal::logger('myeventlane_event')->error('AJAX callback: wizard container not found, returning full form');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event = $this->getEvent($form_state);
    $current_step = $this->getCurrentStep($form_state);
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'] ?? '';
    $values = $form_state->getValues();

    // Determine action.
    if ($button_name === 'back') {
      // Go to previous step without saving.
      $next_step = $this->getPreviousStep($current_step);
      $form_state->set(self::STATE_KEY_CURRENT_STEP, $next_step);
      $form_state->setRebuild(TRUE);
      return;
    }

    if ($button_name === 'save_draft') {
      // Save current step data and stay on same step.
      $this->saveStepData($event, $current_step, $values, $form_state);
      $event->save();
      $form_state->setRebuild(TRUE);
      \Drupal::messenger()->addMessage($this->t('Draft saved.'));
      return;
    }

    // "Next" or "Publish" button clicked.
    // CRITICAL: Save step data before moving forward.
    $this->saveStepData($event, $current_step, $values, $form_state);
    $event->save();

    // Determine next step.
    if ($current_step === 'review') {
      // Publish event and redirect.
      $event->setPublished(TRUE);
      $event->save();
      
      \Drupal::messenger()->addMessage($this->t('Event published successfully.'));
      $form_state->setRedirect('entity.node.canonical', ['node' => $event->id()]);
      return;
    }

    $next_step = $this->getNextStep($current_step);
    $form_state->set(self::STATE_KEY_CURRENT_STEP, $next_step);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Gets next step.
   */
  private function getNextStep(string $current_step): string {
    $step_order = array_keys(self::STEPS);
    $current_index = array_search($current_step, $step_order, TRUE);
    
    if ($current_index === FALSE || $current_index === count($step_order) - 1) {
      return $current_step;
    }

    return $step_order[$current_index + 1];
  }

  /**
   * Gets previous step.
   */
  private function getPreviousStep(string $current_step): string {
    $step_order = array_keys(self::STEPS);
    $current_index = array_search($current_step, $step_order, TRUE);
    
    if ($current_index === FALSE || $current_index === 0) {
      return 'basics';
    }

    return $step_order[$current_index - 1];
  }

}
