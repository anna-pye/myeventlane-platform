<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Form;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Vendor Event Creation Wizard (Drupal 11 safe).
 *
 * Fixes:
 * - Removes readonly redeclare of FormBase::$routeMatch (fatal in PHP 8.2+).
 * - Stops calling ->widget() on field items (not a valid API in FormBase).
 * - Uses entity_form_display for widget rendering (correct Drupal way).
 * - Persists values safely by submitting/validating only current step.
 * - AJAX-safe rebuild with a stable wrapper.
 * - Uses static service calls instead of constructor injection for AJAX compatibility.
 */
final class EventWizardForm extends FormBase {

  private const STATE_STEP = 'wizard_step';

  private const STATE_EVENT_ID = 'wizard_event_id';

  private const TEMPSTORE_KEY = 'myeventlane_event_wizard_progress';

  private const STEPS = [
    'basics',
    'when_where',
    'branding',
    'tickets_capacity',
    'content',
    'policies_accessibility',
    'review',
  ];

  /**
   *
   */
  public function getFormId(): string {
    return 'myeventlane_event_wizard';
  }

  /**
   * Load existing draft event from form state or create a new one.
   *
   * IMPORTANT:
   * - Draft nodes MUST have a title before first save.
   * - Wizard replaces title later when user enters it.
   * - When editing, loads node from route parameter.
   */
  private function getEvent(FormStateInterface $form_state): NodeInterface {
    $entity_type_manager = \Drupal::entityTypeManager();
    $current_user = \Drupal::currentUser();
    $route_match = \Drupal::routeMatch();

    // 1) Check if node is provided in route (edit mode).
    $node = $route_match->getParameter('node');
    if ($node instanceof NodeInterface && $node->bundle() === 'event') {
      // Verify vendor ownership.
      $this->assertEventOwnership($node);
      // Store the event ID in form state for consistency.
      $form_state->set(self::STATE_EVENT_ID, (int) $node->id());
      return $node;
    }

    // 2) Load existing draft by stored ID (wizard continuation).
    $stored_id = $form_state->get(self::STATE_EVENT_ID);
    if ($stored_id) {
      $loaded = $entity_type_manager
        ->getStorage('node')
        ->load((int) $stored_id);

      if ($loaded instanceof NodeInterface && $loaded->bundle() === 'event') {
        return $loaded;
      }
    }

    // 3) Create a new draft with a TEMP title (required by Drupal).
    /** @var \Drupal\node\NodeInterface $event */
    $event = $entity_type_manager
      ->getStorage('node')
      ->create([
        'type' => 'event',
        'uid' => (int) $current_user->id(),
        'status' => 0,
        'title' => $this->t('Untitled event (draft)')->render(),
      ]);

    // MUST save now so wizard has a persistent entity.
    $event->save();

    $form_state->set(self::STATE_EVENT_ID, (int) $event->id());

    return $event;
  }

  /**
   * Checks if we're in edit mode (existing event) vs create mode (new event).
   */
  private function isEditMode(FormStateInterface $form_state): bool {
    $route_match = \Drupal::routeMatch();
    $node = $route_match->getParameter('node');

    if ($node instanceof NodeInterface && $node->bundle() === 'event') {
      return TRUE;
    }

    // Also check if we have a stored event ID that's not a new draft.
    $stored_id = $form_state->get(self::STATE_EVENT_ID);
    if ($stored_id) {
      $entity_type_manager = \Drupal::entityTypeManager();
      $loaded = $entity_type_manager
        ->getStorage('node')
        ->load((int) $stored_id);

      if ($loaded instanceof NodeInterface && $loaded->bundle() === 'event') {
        // If event exists and was created before this session, it's edit mode.
        // We can't perfectly detect this, but if it's published or has a real title,
        // it's likely an edit.
        if ($loaded->isPublished() ||
            (strpos($loaded->label(), 'Untitled event (draft)') === FALSE)) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * Asserts that the current user owns the event.
   */
  private function assertEventOwnership(NodeInterface $event): void {
    $current_user = \Drupal::currentUser();

    // Admins can edit any event.
    if ($current_user->hasPermission('administer nodes')) {
      return;
    }

    // Check if user owns the event.
    if ((int) $event->getOwnerId() !== (int) $current_user->id()) {
      throw new AccessDeniedHttpException(
        $this->t('You do not have permission to edit this event.')
      );
    }
  }

  /**
   *
   */
  private function currentStep(FormStateInterface $form_state): string {
    // If editing, default to review step unless explicitly set.
    if ($this->isEditMode($form_state)) {
      $step = (string) ($form_state->get(self::STATE_STEP) ?? 'review');
      return in_array($step, self::STEPS, TRUE) ? $step : 'review';
    }

    // For new events, start at basics.
    $step = (string) ($form_state->get(self::STATE_STEP) ?? 'basics');
    return in_array($step, self::STEPS, TRUE) ? $step : 'basics';
  }

  /**
   *
   */
  private function stepIndex(string $step): int {
    $idx = array_search($step, self::STEPS, TRUE);
    return $idx === FALSE ? 0 : (int) $idx;
  }

  /**
   *
   */
  private function nextStep(string $step): string {
    $idx = $this->stepIndex($step);
    return self::STEPS[$idx + 1] ?? 'review';
  }

  /**
   *
   */
  private function prevStep(string $step): string {
    $idx = $this->stepIndex($step);
    return self::STEPS[max(0, $idx - 1)];
  }

  /**
   * Helper: render a subset of node fields using the correct widget system.
   */
  private function buildEntityWidgets(NodeInterface $event, array $field_names, array &$form, FormStateInterface $form_state): void {
    $entity_type_manager = \Drupal::entityTypeManager();
    $logger = \Drupal::logger('myeventlane_event');

    // Use the default node form display for "event". If you have a dedicated
    // form mode for the wizard, replace 'default' with that form mode ID.
    $display = $entity_type_manager
      ->getStorage('entity_form_display')
      ->load('node.event.default');

    if (!$display) {
      // Fallback: build at least basic fields manually if display missing.
      $logger->error('Missing entity_form_display: node.event.default');
      return;
    }

    // Build the widgets into a temporary container, then copy only the fields we want.
    $temp = [];
    $display->buildForm($event, $temp, $form_state);

    // Fields that are optional and may not be on form display - don't log warnings for these.
    $optional_fields = ['field_venue', 'field_sales_start', 'field_sales_end'];

    // CRITICAL: Ensure wizard.content exists before adding fields.
    if (!isset($form['wizard']['content'])) {
      $form['wizard']['content'] = [
        '#type' => 'container',
        '#tree' => TRUE,
      ];
    }

    foreach ($field_names as $name) {
      if (isset($temp[$name])) {
        // Put fields in wizard.content temporarily - they'll be moved to card.body later.
        $form['wizard']['content'][$name] = $temp[$name];
        // Ensure field is accessible and has proper weight.
        if (!isset($form['wizard']['content'][$name]['#weight'])) {
          $form['wizard']['content'][$name]['#weight'] = 0;
        }

        // Store event reference for later use in widget conversion.
        $form['wizard']['content'][$name]['#wizard_event'] = $event;
      }
      else {
        // Only log if field is not in optional list - these are expected to be missing sometimes.
        if (!in_array($name, $optional_fields, TRUE)) {
          // Helpful dev log (not noisy unless you enable it at watchdog).
          $logger->error('Wizard field not found on form display: @field. Available fields: @available', [
            '@field' => $name,
            '@available' => implode(', ', array_filter(array_keys($temp), fn($k) => !str_starts_with($k, '#'))),
          ]);
        }
      }
    }
  }

  /**
   *
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $event = $this->getEvent($form_state);
    $step = $this->currentStep($form_state);

    // Get wizard progress for JavaScript.
    $wizard_progress = $this->getWizardProgress($event);
    $highest_completed_index = $wizard_progress['highest_completed_step'] !== NULL
      ? $this->stepIndex($wizard_progress['highest_completed_step'])
      : -1;

    // Attach vendor theme's event-form library (includes compiled SCSS with wizard styles).
    $form['#attached']['library'][] = 'myeventlane_vendor_theme/event-form';
    // Also attach module's JS for wizard functionality.
    $form['#attached']['library'][] = 'myeventlane_event/event_wizard';

    // Attach wizard progress data for JavaScript.
    if (!isset($form['#attached']['drupalSettings'])) {
      $form['#attached']['drupalSettings'] = [];
    }
    $form['#attached']['drupalSettings']['myeventlaneEventWizard'] = [
      'wizard_started' => $wizard_progress['wizard_started'],
      'highest_completed_step' => $wizard_progress['highest_completed_step'],
      'highest_completed_index' => $highest_completed_index,
      'steps' => self::STEPS,
    ];

    // Attach address autocomplete library and settings for when_where step.
    if ($step === 'when_where') {
      $form['#attached']['library'][] = 'myeventlane_location/address_autocomplete';

      // Get provider settings for JavaScript.
      /** @var \Drupal\myeventlane_location\Service\LocationProviderManager $provider_manager */
      $provider_manager = \Drupal::service('myeventlane_location.provider_manager');
      $settings = $provider_manager->getFrontendSettings();

      // For Apple Maps, we need to generate a token server-side.
      if ($settings['provider'] === 'apple_maps') {
        /** @var \Drupal\myeventlane_location\Service\MapKitTokenGenerator $token_generator */
        $token_generator = \Drupal::service('myeventlane_location.mapkit_token_generator');
        $token = $token_generator->generateToken();
        if (!empty($token)) {
          $settings['apple_maps_token'] = $token;
        }
      }

      // Attach drupalSettings for JavaScript.
      if (!isset($form['#attached']['drupalSettings'])) {
        $form['#attached']['drupalSettings'] = [];
      }
      $form['#attached']['drupalSettings']['myeventlaneLocation'] = $settings;
    }

    // IMPORTANT: wrapper ID must match ajax callback wrapper.
    $form['#prefix'] = '<div id="event-wizard-wrapper">';
    $form['#suffix'] = '</div>';

    // CRITICAL: Make wizard renderable by ensuring it's not hidden and is explicitly rendered.
    // Wizard structure is already created above, just ensure it's accessible.
    if (!isset($form['wizard'])) {
      $form['wizard'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-wizard', 'mel-wizard--event', 'mel-vendor-wizard'],
          'style' => 'display: block !important; visibility: visible !important;',
        ],
        '#access' => TRUE,
        '#printed' => FALSE,
        '#weight' => 0,
      ];
    }

    // Layout container for sidebar + content.
    $form['wizard']['layout'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-wizard__layout', 'mel-vendor-wizard__layout']],
    ];

    // Left sidebar navigation.
    $form['wizard']['layout']['sidebar'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-wizard__sidebar', 'mel-wizard__nav', 'mel-vendor-wizard__rail']],
    ];

    $form['wizard']['layout']['sidebar']['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t('Event setup'),
      '#attributes' => ['class' => ['mel-event-form__wizard-nav-title']],
    ];

    $form['wizard']['layout']['sidebar']['nav_list'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-wizard__nav-list', 'mel-vendor-wizard__steps']],
    ];

    // Build step navigation buttons.
    $step_number = 1;
    foreach (self::STEPS as $step_id) {
      $is_active = ($step_id === $step);
      $step_label = $this->getStepLabel($step_id);

      // Build button with number and label inside.
      // Use a container styled as button, with hidden submit button for form submission.
      $form['wizard']['layout']['sidebar']['nav_list'][$step_id] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => array_values(array_filter([
            'mel-wizard__step-row',
            'mel-vendor-wizard__step-row',
            'mel-wizard__step',
            'mel-vendor-wizard__step',
            'js-mel-stepper-button',
            $is_active ? 'is-active' : NULL,
          ])),
          'data-step-target' => $step_id,
          'data-wizard-step' => $step_id,
          'role' => 'button',
          'tabindex' => '0',
        ],
        'inner' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-event-form__step-inner']],
          'number' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => (string) $step_number,
            '#attributes' => ['class' => ['mel-event-form__step-number']],
          ],
          'label' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $step_label,
            '#attributes' => ['class' => ['mel-event-form__step-label']],
          ],
        ],
        // Hidden submit button for actual form submission.
        'submit' => [
          '#type' => 'submit',
          '#value' => '',
          '#submit' => ['::gotoStep'],
          '#limit_validation_errors' => [],
          '#ajax' => [
            'callback' => '::ajaxRefresh',
            'wrapper' => 'event-wizard-wrapper',
          ],
          '#attributes' => [
            'class' => ['js-mel-step-submit'],
            'data-step-target' => $step_id,
            'style' => 'display: none;',
          ],
        ],
      ];
      $step_number++;
    }

    // Right content area.
    $form['wizard']['layout']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-wizard__content', 'mel-vendor-wizard__content']],
    ];

    // Card wrapper for step content.
    $form['wizard']['layout']['content']['card'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-card', 'mel-card--wizard-section', 'mel-vendor-wizard__card']],
    ];

    // Card header with title and help text.
    $form['wizard']['layout']['content']['card']['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-card__header', 'mel-vendor-wizard__title']],
    ];

    $form['wizard']['layout']['content']['card']['header']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->stepHeading($step),
      '#attributes' => [
        'class' => ['mel-card__title', 'mel-vendor-wizard__title'],
      ],
    ];

    $form['wizard']['layout']['content']['card']['header']['help'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->getStepHelpText($step),
      '#attributes' => [
        'class' => ['mel-card__help', 'mel-vendor-wizard__hint'],
      ],
    ];

    // Card body for form fields.
    $form['wizard']['layout']['content']['card']['body'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-card__body']],
    // Ensure it renders after header.
      '#weight' => 10,
    // CRITICAL: Ensure container is accessible.
      '#access' => TRUE,
    // Ensure it renders.
      '#printed' => FALSE,
    ];

    // CRITICAL: Initialize wizard.content container BEFORE building fields.
    // buildEntityWidgets() expects this container to exist.
    if (!isset($form['wizard']['content'])) {
      $form['wizard']['content'] = [
        '#type' => 'container',
        '#tree' => TRUE,
      ];
    }

    // Build step fields into wizard.content (they'll be moved to card.body after).
    switch ($step) {
      case 'basics':
        $this->buildBasics($form, $form_state, $event);
        break;

      case 'when_where':
        $this->buildWhenWhere($form, $form_state, $event);
        break;

      case 'branding':
        $this->buildBranding($form, $form_state, $event);
        break;

      case 'tickets_capacity':
        $this->buildTicketsCapacity($form, $form_state, $event);
        break;

      case 'content':
        $this->buildContent($form, $form_state, $event);
        break;

      case 'policies_accessibility':
        $this->buildPolicies($form, $form_state, $event);
        break;

      case 'review':
        $this->buildReview($form, $form_state, $event);
        break;
    }

    // Move all fields from wizard.content to wizard.layout.content.card.body.
    // This happens after build methods put fields in wizard.content.
    if (isset($form['wizard']['content'])) {
      foreach ($form['wizard']['content'] as $key => $element) {
        // Skip metadata keys and move actual form elements.
        if ($key !== '_step_title' && !str_starts_with($key, '#')) {
          // Ensure field is accessible.
          $form['wizard']['layout']['content']['card']['body'][$key] = $element;
          $form['wizard']['layout']['content']['card']['body'][$key]['#access'] = TRUE;
          // Ensure it's not hidden.
          if (isset($form['wizard']['layout']['content']['card']['body'][$key]['#type'])) {
            // Field has a type, ensure it renders.
            $form['wizard']['layout']['content']['card']['body'][$key]['#printed'] = FALSE;
          }
          unset($form['wizard']['content'][$key]);
        }
      }
      // Remove the old content container if empty.
      if (empty($form['wizard']['content']) || (count($form['wizard']['content']) === 1 && isset($form['wizard']['content']['_step_title']))) {
        unset($form['wizard']['content']);
      }
    }

    // Ensure card body is always accessible for rendering.
    if (!isset($form['wizard']['layout']['content']['card']['body'])) {
      $form['wizard']['layout']['content']['card']['body'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-card__body']],
      ];
    }

    // Ensure wizard container itself is accessible and rendered.
    $form['wizard']['#access'] = TRUE;
    $form['wizard']['#printed'] = FALSE;

    // Actions container (outside card, at bottom of content area).
    $form['wizard']['layout']['content']['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['mel-wizard__actions', 'mel-vendor-wizard__actions']],
    ];

    $is_edit_mode = $this->isEditMode($form_state);
    $is_published = $event->isPublished();

    // Back button: show if not on first step.
    if ($step !== 'basics') {
      $form['wizard']['layout']['content']['actions']['back'] = [
        '#type' => 'submit',
        '#value' => $this->t('Back'),
        '#submit' => ['::backStep'],
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => '::ajaxRefresh',
          'wrapper' => 'event-wizard-wrapper',
        ],
      ];
    }

    // Primary action button: context-aware labels and behavior.
    if ($step === 'review') {
      if ($is_edit_mode) {
        // Edit mode on review: show Save Changes (for published) or Save Draft (for drafts).
        $form['wizard']['layout']['content']['actions']['save'] = [
          '#type' => 'submit',
          '#value' => $is_published ? $this->t('Save Changes') : $this->t('Save Draft'),
          '#submit' => ['::saveEvent'],
          '#ajax' => [
            'callback' => '::ajaxRefresh',
            'wrapper' => 'event-wizard-wrapper',
          ],
        ];

        // Also show Publish button if event is draft.
        if (!$is_published) {
          $form['wizard']['layout']['content']['actions']['publish'] = [
            '#type' => 'submit',
            '#value' => $this->t('Publish'),
            '#submit' => ['::publishEvent'],
            '#ajax' => [
              'callback' => '::ajaxRefresh',
              'wrapper' => 'event-wizard-wrapper',
            ],
            '#attributes' => ['class' => ['mel-btn--primary']],
          ];
        }
      }
      else {
        // Create mode on review: show Publish button.
        $form['wizard']['layout']['content']['actions']['publish'] = [
          '#type' => 'submit',
          '#value' => $this->t('Publish'),
          '#submit' => ['::publishEvent'],
          '#ajax' => [
            'callback' => '::ajaxRefresh',
            'wrapper' => 'event-wizard-wrapper',
          ],
        ];
      }
    }
    else {
      // Not on review step: show Continue button.
      $form['wizard']['layout']['content']['actions']['next'] = [
        '#type' => 'submit',
        '#value' => $this->t('Continue'),
        '#submit' => ['::nextStepSubmit'],
        '#ajax' => [
          'callback' => '::ajaxRefresh',
          'wrapper' => 'event-wizard-wrapper',
        ],
      ];

      // Show Save Changes / Save Draft button on ALL steps (not just review).
      if ($is_edit_mode) {
        // Edit mode: show Save Changes (for published) or Save Draft (for drafts).
        $form['wizard']['layout']['content']['actions']['save'] = [
          '#type' => 'submit',
          '#value' => $is_published ? $this->t('Save Changes') : $this->t('Save Draft'),
          '#submit' => ['::saveEvent'],
          '#limit_validation_errors' => [],
          '#ajax' => [
            'callback' => '::ajaxRefresh',
            'wrapper' => 'event-wizard-wrapper',
          ],
          '#attributes' => ['class' => ['mel-btn--secondary']],
        ];
      }
      else {
        // Create mode: show Save Draft button on all steps.
        $form['wizard']['layout']['content']['actions']['save_draft'] = [
          '#type' => 'submit',
          '#value' => $this->t('Save Draft'),
          '#submit' => ['::saveDraft'],
          '#limit_validation_errors' => [],
          '#ajax' => [
            'callback' => '::ajaxRefresh',
            'wrapper' => 'event-wizard-wrapper',
          ],
          '#attributes' => ['class' => ['mel-btn--secondary']],
        ];
      }
    }

    // Hidden bookkeeping.
    $form['wizard_step'] = [
      '#type' => 'hidden',
      '#value' => $step,
    ];
    $form['wizard_event_id'] = [
      '#type' => 'hidden',
      '#value' => (int) $event->id(),
    ];

    return $form;
  }

  /**
   *
   */
  private function stepHeading(string $step): string {
    return match ($step) {
      'basics' => (string) $this->t('Basics'),
      'when_where' => (string) $this->t('When & where'),
      'branding' => (string) $this->t('Branding'),
      'tickets_capacity' => (string) $this->t('Tickets & RSVP'),
      'content' => (string) $this->t('Event details'),
      'policies_accessibility' => (string) $this->t('Policies & accessibility'),
      'review' => (string) $this->t('Review & publish'),
      default => (string) $this->t('Event wizard'),
    };
  }

  /**
   *
   */
  private function getStepLabel(string $step): string {
    return match ($step) {
      'basics' => (string) $this->t('Basics'),
      'when_where' => (string) $this->t('When & Where'),
      'branding' => (string) $this->t('Branding'),
      'tickets_capacity' => (string) $this->t('Tickets & Capacity'),
      'content' => (string) $this->t('Content'),
      'policies_accessibility' => (string) $this->t('Policies & Accessibility'),
      'review' => (string) $this->t('Review'),
      default => (string) $this->t('Step'),
    };
  }

  /**
   *
   */
  private function getStepHelpText(string $step): string {
    return match ($step) {
      'basics' => (string) $this->t('Tell us about your event. Give it a clear name and description so people know what to expect. You can change these details later if needed.'),
      'when_where' => (string) $this->t('When does your event start and finish? Add the date and times. If your event runs over multiple days, you can add recurring dates later.'),
      'branding' => (string) $this->t('Make your event stand out. Add a banner image and visual details to help people find your event. These details help create an inclusive experience for everyone.'),
      'tickets_capacity' => (string) $this->t('How do people attend your event? Choose RSVP for free events, paid tickets, or both. You can also link to an external booking system. Don\'t worry—you can adjust ticket settings later.'),
      'content' => (string) $this->t('Add the main description and any key information about your event. This helps attendees understand what to expect. You can always edit this later.'),
      'policies_accessibility' => (string) $this->t('Set your refund policy, accessibility information, and anything else attendees should know. These details help create a clear and inclusive experience.'),
      'review' => (string) $this->t('Review all your event details before publishing. Check that everything looks correct. You can go back to any step to make changes.'),
      default => '',
    };
  }

  /**
   * ------------------------------------------------------------------------
   * STEP BUILDERS
   * ---------------------------------------------------------------------
   */
  private function buildBasics(array &$form, FormStateInterface $form_state, NodeInterface $event): void {
    // Render with the node form display widgets.
    // NOTE: Only title is in basics step now.
    // field_event_type has been moved to tickets_capacity step.
    $this->buildEntityWidgets($event, ['title', 'field_category'], $form, $form_state);

    // Force field_category to use select widget (multiple select) instead of autocomplete.
    if (isset($form['wizard']['content']['field_category'])) {
      $this->convertTaxonomyToSelect($form['wizard']['content']['field_category'], TRUE);
    }

    // Debug: Check if title field was created.
    if (!isset($form['wizard']['content']['title'])) {
      $available = isset($form['wizard']['content']) ? implode(', ', array_keys($form['wizard']['content'])) : 'wizard.content does not exist';
      $logger->error('FAILED: Title field NOT created in wizard.content. Available keys: @keys', [
        '@keys' => $available,
      ]);

    }
  }

  /**
   *
   */
  private function buildWhenWhere(array &$form, FormStateInterface $form_state, NodeInterface $event): void {
    // Build date fields.
    $this->buildEntityWidgets($event, [
      'field_event_start',
      'field_event_end',
      'field_venue_address',
    ], $form, $form_state);

    // Hide field_venue_name if it was built (we use custom venue_name field instead).
    if (isset($form['wizard']['content']['field_venue_name'])) {
      $form['wizard']['content']['field_venue_name']['#access'] = FALSE;
    }

    // Set field weights for proper ordering:
    // 1. Event Start (weight: 0)
    if (isset($form['wizard']['content']['field_event_start'])) {
      $form['wizard']['content']['field_event_start']['#weight'] = 0;
    }

    // 2. Event End (weight: 1)
    if (isset($form['wizard']['content']['field_event_end'])) {
      $form['wizard']['content']['field_event_end']['#weight'] = 1;
    }

    // Build field_location widget (for backward compatibility, but will be hidden).
    $this->buildEntityWidgets($event, [
      'field_location',
    ], $form, $form_state);

    // Build venue field (entity reference).
    $this->buildEntityWidgets($event, [
      'field_venue',
    ], $form, $form_state);

    // Create venue selection wrapper container.
    $form['wizard']['content']['_venue_wrapper'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['myeventlane-venue-selection-widget'],
        'data-venue-autocomplete-url' => '/venue/autocomplete',
      ],
      '#weight' => 2,
    ];

    // Add "Search for address or venue" field at the top (ONLY search field).
    $form['wizard']['content']['_venue_wrapper']['address_search'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search for address or venue'),
      '#description' => $this->t('Search your saved venues or use Google/Apple Maps to find a new location.'),
      '#attributes' => [
        'class' => ['myeventlane-location-address-search'],
        'autocomplete' => 'off',
        'placeholder' => $this->t('Type address or venue name...'),
      ],
      '#weight' => -10,
    ];

    // Add venue name field (below search, auto-populated but editable).
    $venue_name_value = '';
    if ($event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty()) {
      $venue_name_value = $event->get('field_venue_name')->value;
    }
    // Also check if venue entity is selected.
    if (empty($venue_name_value) && $event->hasField('field_venue') && !$event->get('field_venue')->isEmpty()) {
      $venue_entity = $event->get('field_venue')->entity;
      if ($venue_entity && $venue_entity->getEntityTypeId() === 'myeventlane_venue') {
        $venue_name_value = $venue_entity->getName();
      }
    }

    $form['wizard']['content']['_venue_wrapper']['venue_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Venue name'),
      '#description' => $this->t('Name for this venue (auto-populated but editable).'),
      '#default_value' => $venue_name_value,
      '#attributes' => [
        'class' => ['myeventlane-venue-name-field'],
      ],
      '#weight' => -5,
      // Set explicit #parents so validation can find the value even if field is moved.
      '#parents' => ['_venue_wrapper', 'venue_name'],
    ];

    // PHASE 5: Move field_venue_address into wrapper (PRIMARY address field).
    if (isset($form['wizard']['content']['field_venue_address'])) {
      $form['wizard']['content']['_venue_wrapper']['field_venue_address'] = $form['wizard']['content']['field_venue_address'];
      $form['wizard']['content']['_venue_wrapper']['field_venue_address']['#weight'] = 0;
      // Ensure it's visible and accessible.
      $form['wizard']['content']['_venue_wrapper']['field_venue_address']['#access'] = TRUE;

      // Remove any search field from the widget (we have our own at the top).
      if (isset($form['wizard']['content']['_venue_wrapper']['field_venue_address']['widget'][0]['address_search'])) {
        $form['wizard']['content']['_venue_wrapper']['field_venue_address']['widget'][0]['address_search']['#access'] = FALSE;
      }

      // Hide unused address subfields: organization, given_name, family_name.
      // Check multiple possible paths in the widget structure.
      $unused_fields = ['organization', 'given_name', 'family_name'];

      // Path 1: Standard widget structure.
      if (isset($form['wizard']['content']['_venue_wrapper']['field_venue_address']['widget'][0]['address'])) {
        $address_widget = &$form['wizard']['content']['_venue_wrapper']['field_venue_address']['widget'][0]['address'];
        foreach ($unused_fields as $field_name) {
          if (isset($address_widget[$field_name])) {
            $address_widget[$field_name]['#access'] = FALSE;
            $address_widget[$field_name]['#required'] = FALSE;
            // Also try unsetting to ensure it's completely removed.
            unset($address_widget[$field_name]);
          }
        }
      }

      // Path 2: Check if fields are at widget root level.
      if (isset($form['wizard']['content']['_venue_wrapper']['field_venue_address']['widget'][0])) {
        $widget = &$form['wizard']['content']['_venue_wrapper']['field_venue_address']['widget'][0];
        foreach ($unused_fields as $field_name) {
          if (isset($widget[$field_name])) {
            $widget[$field_name]['#access'] = FALSE;
            $widget[$field_name]['#required'] = FALSE;
            unset($widget[$field_name]);
          }
        }
      }

      // Path 3: Recursively search and hide.
      $this->hideUnusedAddressFieldsRecursive($form['wizard']['content']['_venue_wrapper']['field_venue_address']);

      // Set default country to AU (for when JS populates it).
      if (isset($form['wizard']['content']['_venue_wrapper']['field_venue_address']['widget'][0]['address']['country_code'])) {
        $country_element = &$form['wizard']['content']['_venue_wrapper']['field_venue_address']['widget'][0]['address']['country_code'];
        if (isset($country_element['#options']) && isset($country_element['#options']['AU'])) {
          if (!isset($country_element['#default_value']) || empty($country_element['#default_value'])) {
            $country_element['#default_value'] = 'AU';
          }
        }
      }

      // Add data attribute to help JS find it.
      if (!isset($form['wizard']['content']['_venue_wrapper']['field_venue_address']['#attributes'])) {
        $form['wizard']['content']['_venue_wrapper']['field_venue_address']['#attributes'] = [];
      }
      if (!isset($form['wizard']['content']['_venue_wrapper']['field_venue_address']['#attributes']['data-mel-address'])) {
        $form['wizard']['content']['_venue_wrapper']['field_venue_address']['#attributes']['data-mel-address'] = 'field_venue_address';
      }

      unset($form['wizard']['content']['field_venue_address']);
    }

    // Move field_location widget into wrapper but HIDE IT COMPLETELY.
    // It's kept for backward compatibility and will be populated by JS, but not visible.
    if (isset($form['wizard']['content']['field_location'])) {
      $form['wizard']['content']['_venue_wrapper']['field_location'] = $form['wizard']['content']['field_location'];
      $form['wizard']['content']['_venue_wrapper']['field_location']['#weight'] = 1;
      // HIDE THE ENTIRE field_location widget - it's only for data storage.
      $form['wizard']['content']['_venue_wrapper']['field_location']['#access'] = FALSE;

      // Remove any search field from the widget (we have our own at the top).
      if (isset($form['wizard']['content']['_venue_wrapper']['field_location']['widget'][0]['address_search'])) {
        $form['wizard']['content']['_venue_wrapper']['field_location']['widget'][0]['address_search']['#access'] = FALSE;
      }

      // Hide unused address subfields: organization, given_name, family_name.
      // Check multiple possible paths in the widget structure.
      $unused_fields = ['organization', 'given_name', 'family_name'];

      // Path 1: Standard widget structure.
      if (isset($form['wizard']['content']['_venue_wrapper']['field_location']['widget'][0]['address'])) {
        $address_widget = &$form['wizard']['content']['_venue_wrapper']['field_location']['widget'][0]['address'];
        foreach ($unused_fields as $field_name) {
          if (isset($address_widget[$field_name])) {
            $address_widget[$field_name]['#access'] = FALSE;
            $address_widget[$field_name]['#required'] = FALSE;
            // Also try unsetting to ensure it's completely removed.
            unset($address_widget[$field_name]);
          }
        }
      }

      // Path 2: Check if fields are at widget root level.
      if (isset($form['wizard']['content']['_venue_wrapper']['field_location']['widget'][0])) {
        $widget = &$form['wizard']['content']['_venue_wrapper']['field_location']['widget'][0];
        foreach ($unused_fields as $field_name) {
          if (isset($widget[$field_name])) {
            $widget[$field_name]['#access'] = FALSE;
            $widget[$field_name]['#required'] = FALSE;
            unset($widget[$field_name]);
          }
        }
      }

      // Path 3: Recursively search and hide.
      $this->hideUnusedAddressFieldsRecursive($form['wizard']['content']['_venue_wrapper']['field_location']);

      // Set default country to AU (for when JS populates it).
      if (isset($form['wizard']['content']['_venue_wrapper']['field_location']['widget'][0]['address']['country_code'])) {
        $country_element = &$form['wizard']['content']['_venue_wrapper']['field_location']['widget'][0]['address']['country_code'];
        if (isset($country_element['#options']) && isset($country_element['#options']['AU'])) {
          if (!isset($country_element['#default_value']) || empty($country_element['#default_value'])) {
            $country_element['#default_value'] = 'AU';
          }
        }
      }

      // Hide lat/lng fields if they exist.
      if (isset($form['wizard']['content']['_venue_wrapper']['field_location']['widget'][0]['latitude'])) {
        $form['wizard']['content']['_venue_wrapper']['field_location']['widget'][0]['latitude']['#access'] = FALSE;
      }
      if (isset($form['wizard']['content']['_venue_wrapper']['field_location']['widget'][0]['longitude'])) {
        $form['wizard']['content']['_venue_wrapper']['field_location']['widget'][0]['longitude']['#access'] = FALSE;
      }

      unset($form['wizard']['content']['field_location']);
    }

    // Move field_venue widget into wrapper (for selecting existing venues).
    if (isset($form['wizard']['content']['field_venue'])) {
      $form['wizard']['content']['_venue_wrapper']['field_venue'] = $form['wizard']['content']['field_venue'];
      $form['wizard']['content']['_venue_wrapper']['field_venue']['#weight'] = 1;
      // Hide the field_venue widget's label/description if it's too verbose.
      if (isset($form['wizard']['content']['_venue_wrapper']['field_venue']['#title'])) {
        $form['wizard']['content']['_venue_wrapper']['field_venue']['#title'] = $this->t('Select existing venue (optional)');
      }
      unset($form['wizard']['content']['field_venue']);
    }

    // Hide latitude/longitude fields if they exist at root level.
    if (isset($form['wizard']['content']['field_location_latitude'])) {
      $form['wizard']['content']['field_location_latitude']['#access'] = FALSE;
    }
    if (isset($form['wizard']['content']['field_location_longitude'])) {
      $form['wizard']['content']['field_location_longitude']['#access'] = FALSE;
    }

    // Attach venue selection JavaScript library.
    $form['#attached']['library'][] = 'myeventlane_venue/venue_selection';
  }

  /**
   *
   */
  private function buildBranding(array &$form, FormStateInterface $form_state, NodeInterface $event): void {
    // Ensure field_event_image exists and is on form display.
    $this->buildEntityWidgets($event, ['field_event_image'], $form, $form_state);
  }

  /**
   *
   */
  private function buildTicketsCapacity(array &$form, FormStateInterface $form_state, NodeInterface $event): void {
    // Build event type field first (this determines what other fields to show).
    $this->buildEntityWidgets($event, ['field_event_type'], $form, $form_state);

    // If field_event_type is missing from display, fallback to a safe manual select.
    if (!isset($form['wizard']['content']['field_event_type'])) {
      $form['wizard']['content']['field_event_type'] = [
        '#type' => 'select',
        '#title' => $this->t('Event Type'),
        '#description' => $this->t('Choose how attendees will register for this event.'),
        '#options' => [
          'rsvp' => $this->t('RSVP (Free)'),
          'paid' => $this->t('Paid (Ticketed)'),
          'both' => $this->t('Both (Free + Paid)'),
          'external' => $this->t('External Link'),
        ],
        '#default_value' => $event->get('field_event_type')->value ?? 'rsvp',
        '#required' => TRUE,
        '#weight' => -10,
        '#ajax' => [
          'callback' => '::ajaxRefresh',
          'wrapper' => 'event-wizard-wrapper',
          'event' => 'change',
        ],
      ];
    }
    else {
      // Ensure it has proper weight and AJAX.
      $form['wizard']['content']['field_event_type']['#weight'] = -10;

      // Add AJAX to the actual select element (could be in widget[0][value] or widget[0]).
      if (isset($form['wizard']['content']['field_event_type']['widget'][0]['value'])) {
        $form['wizard']['content']['field_event_type']['widget'][0]['value']['#ajax'] = [
          'callback' => '::ajaxRefresh',
          'wrapper' => 'event-wizard-wrapper',
          'event' => 'change',
        ];
      }
      elseif (isset($form['wizard']['content']['field_event_type']['widget'][0])) {
        $form['wizard']['content']['field_event_type']['widget'][0]['#ajax'] = [
          'callback' => '::ajaxRefresh',
          'wrapper' => 'event-wizard-wrapper',
          'event' => 'change',
        ];
      }
      elseif (!isset($form['wizard']['content']['field_event_type']['#ajax'])) {
        $form['wizard']['content']['field_event_type']['#ajax'] = [
          'callback' => '::ajaxRefresh',
          'wrapper' => 'event-wizard-wrapper',
          'event' => 'change',
        ];
      }
    }

    // Determine field_event_type form element path for states AFTER building the field.
    // Check widget structure first (standard Drupal field widget).
    $event_type_selector = 'field_event_type';
    if (isset($form['wizard']['content']['field_event_type']['widget'][0]['value'])) {
      // Standard widget structure with value nested.
      $event_type_selector = 'field_event_type[0][value]';
    }
    elseif (isset($form['wizard']['content']['field_event_type']['widget'][0])) {
      // Widget structure but might be different format - try widget[0] directly.
      $event_type_selector = 'field_event_type[0][value]';
    }
    elseif (isset($form['wizard']['content']['field_event_type']['#type']) && $form['wizard']['content']['field_event_type']['#type'] === 'select') {
      // Manual select field (fallback).
      $event_type_selector = 'field_event_type';
    }

    // Get current event type (from form state if changed, otherwise from entity).
    $event_type = $form_state->getValue(['field_event_type', 0, 'value'])
      ?? $form_state->getValue('field_event_type')
      ?? $event->get('field_event_type')->value
      ?? 'rsvp';

    // Handle both string and array values.
    if (is_array($event_type)) {
      $event_type = $event_type[0]['value'] ?? $event_type['value'] ?? 'rsvp';
    }

    // Build capacity fields (always shown, but conditionally visible).
    $capacity_fields = ['field_capacity'];
    if ($event->hasField('field_waitlist_capacity')) {
      $capacity_fields[] = 'field_waitlist_capacity';
    }
    $this->buildEntityWidgets($event, $capacity_fields, $form, $form_state);

    // Fallback for capacity if not in form display.
    if (!isset($form['wizard']['content']['field_capacity'])) {
      $form['wizard']['content']['field_capacity'] = [
        '#type' => 'number',
        '#title' => $this->t('Capacity'),
        '#description' => $this->t('Maximum number of attendees. Leave empty or set to 0 for unlimited.'),
        '#default_value' => $event->get('field_capacity')->value ?? '',
        '#min' => 0,
        '#weight' => 0,
      ];
    }

    // Fallback for waitlist capacity if not in form display.
    if (!isset($form['wizard']['content']['field_waitlist_capacity']) && $event->hasField('field_waitlist_capacity')) {
      $form['wizard']['content']['field_waitlist_capacity'] = [
        '#type' => 'number',
        '#title' => $this->t('Waitlist Capacity'),
        '#description' => $this->t('Maximum number of people on the waitlist. Leave empty or set to 0 for no waitlist.'),
        '#default_value' => $event->get('field_waitlist_capacity')->value ?? '',
        '#min' => 0,
        '#weight' => 1,
      ];
    }

    // For external events, show external URL field.
    $this->buildEntityWidgets($event, ['field_external_url'], $form, $form_state);
    if (isset($form['wizard']['content']['field_external_url'])) {
      $form['wizard']['content']['field_external_url']['#weight'] = 2;
      // Show only for external events.
      $form['wizard']['content']['field_external_url']['#states'] = [
        'visible' => [
          ':input[name="' . $event_type_selector . '"]' => ['value' => 'external'],
        ],
      ];
    }

    // Build sales start/end fields if they exist (always build, conditionally show).
    $sales_fields = [];
    if ($event->hasField('field_sales_start')) {
      $sales_fields[] = 'field_sales_start';
    }
    if ($event->hasField('field_sales_end')) {
      $sales_fields[] = 'field_sales_end';
    }

    if (!empty($sales_fields)) {
      $this->buildEntityWidgets($event, $sales_fields, $form, $form_state);
      // Set weights and conditional visibility for sales fields.
      if (isset($form['wizard']['content']['field_sales_start'])) {
        $form['wizard']['content']['field_sales_start']['#weight'] = 3;
        $form['wizard']['content']['field_sales_start']['#required'] = FALSE;
        $form['wizard']['content']['field_sales_start']['#description'] =
          $form['wizard']['content']['field_sales_start']['#description'] ??
          $this->t('When ticket sales begin. Leave empty to start immediately.');
        // Show only for paid/both events.
        $form['wizard']['content']['field_sales_start']['#states'] = [
          'visible' => [
            ':input[name="' . $event_type_selector . '"]' => [
              ['value' => 'paid'],
              ['value' => 'both'],
            ],
          ],
        ];
        // Also set required to FALSE on the widget if it exists.
        if (isset($form['wizard']['content']['field_sales_start']['widget'][0]['value'])) {
          $form['wizard']['content']['field_sales_start']['widget'][0]['value']['#required'] = FALSE;
        }
      }
      if (isset($form['wizard']['content']['field_sales_end'])) {
        $form['wizard']['content']['field_sales_end']['#weight'] = 4;
        $form['wizard']['content']['field_sales_end']['#required'] = FALSE;
        $form['wizard']['content']['field_sales_end']['#description'] =
          $form['wizard']['content']['field_sales_end']['#description'] ??
          $this->t('When ticket sales end. Leave empty to sell until event starts.');
        // Show only for paid/both events.
        $form['wizard']['content']['field_sales_end']['#states'] = [
          'visible' => [
            ':input[name="' . $event_type_selector . '"]' => [
              ['value' => 'paid'],
              ['value' => 'both'],
            ],
          ],
        ];
        // Also set required to FALSE on the widget if it exists.
        if (isset($form['wizard']['content']['field_sales_end']['widget'][0]['value'])) {
          $form['wizard']['content']['field_sales_end']['widget'][0]['value']['#required'] = FALSE;
        }
      }
    }

    // Build collect_per_ticket field if it exists (always build, conditionally show).
    if ($event->hasField('field_collect_per_ticket')) {
      $this->buildEntityWidgets($event, ['field_collect_per_ticket'], $form, $form_state);
      if (isset($form['wizard']['content']['field_collect_per_ticket'])) {
        $form['wizard']['content']['field_collect_per_ticket']['#weight'] = 5;
        // Show only for paid/both events.
        $form['wizard']['content']['field_collect_per_ticket']['#states'] = [
          'visible' => [
            ':input[name="' . $event_type_selector . '"]' => [
              ['value' => 'paid'],
              ['value' => 'both'],
            ],
          ],
        ];
      }
    }

    // Build ticket types paragraph field (for paid/both events).
    // This is the main ticket configuration field with all ticket type details.
    if ($event->hasField('field_ticket_types')) {
      $this->buildEntityWidgets($event, ['field_ticket_types'], $form, $form_state);
      if (isset($form['wizard']['content']['field_ticket_types'])) {
        $form['wizard']['content']['field_ticket_types']['#weight'] = 6;
        // Show only for paid/both events.
        $form['wizard']['content']['field_ticket_types']['#states'] = [
          'visible' => [
            ':input[name="' . $event_type_selector . '"]' => [
              ['value' => 'paid'],
              ['value' => 'both'],
            ],
          ],
        ];
        // Add description to help users understand this field.
        if (!isset($form['wizard']['content']['field_ticket_types']['#description'])) {
          $form['wizard']['content']['field_ticket_types']['#description'] =
            $this->t('Define ticket types for this event. Each ticket type will create a separate Commerce Product Variation. Only shown when Event Type is "Paid" or "Both".');
        }
      }
    }

    // Add info about ticket types management (conditionally shown).
    $tickets_url = NULL;
    try {
      $tickets_url = Url::fromRoute('myeventlane_vendor.console.event_tickets', ['event' => $event->id()]);
    }
    catch (\Exception) {
      // Route may not exist.
    }

    $form['wizard']['content']['_ticket_types_info'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-vendor-wizard__info-box']],
      '#weight' => 10,
      '#states' => [
        'visible' => [
          ':input[name="' . $event_type_selector . '"]' => [
            ['value' => 'paid'],
            ['value' => 'both'],
          ],
        ],
      ],
    ];

    if ($tickets_url && !$event->isNew()) {
      $form['wizard']['content']['_ticket_types_info']['message'] = [
        '#markup' => '<p>' . $this->t('Configure ticket types, pricing, and availability on the <a href="@url">Tickets page</a>.', [
          '@url' => $tickets_url->toString(),
        ]) . '</p>',
      ];
    }
    else {
      $form['wizard']['content']['_ticket_types_info']['message'] = [
        '#markup' => '<p>' . $this->t('After publishing, you can configure ticket types, pricing, and availability on the Tickets page.') . '</p>',
      ];
    }

    // For RSVP events, show RSVP-specific info.
    $form['wizard']['content']['_rsvp_info'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-vendor-wizard__info-box']],
      '#weight' => 10,
      '#states' => [
        'visible' => [
          ':input[name="' . $event_type_selector . '"]' => ['value' => 'rsvp'],
        ],
      ],
    ];
    $form['wizard']['content']['_rsvp_info']['message'] = [
      '#markup' => '<p>' . $this->t('This is a free RSVP event. Attendees can register without payment.') . '</p>',
    ];

    // Build collect_per_ticket field if it exists (for paid events).
    if ($event->hasField('field_collect_per_ticket')) {
      $this->buildEntityWidgets($event, ['field_collect_per_ticket'], $form, $form_state);
      if (isset($form['wizard']['content']['field_collect_per_ticket'])) {
        $form['wizard']['content']['field_collect_per_ticket']['#weight'] = 5;
        // Show only for paid/both events.
        $form['wizard']['content']['field_collect_per_ticket']['#states'] = [
          'visible' => [
            ':input[name="' . $event_type_selector . '"]' => [
              ['value' => 'paid'],
              ['value' => 'both'],
            ],
          ],
        ];
      }
    }
  }

  /**
   *
   */
  private function buildContent(array &$form, FormStateInterface $form_state, NodeInterface $event): void {
    // Ensure body is on form display.
    $this->buildEntityWidgets($event, [
      'body',
      'field_event_intro',
      'field_event_highlights',
    ], $form, $form_state);
  }

  /**
   *
   */
  private function buildPolicies(array &$form, FormStateInterface $form_state, NodeInterface $event): void {
    // Build policy and accessibility fields.
    $policy_fields = [
      'field_refund_policy',
      'field_accessibility',
      'field_age_restriction',
      'field_contact_email',
      'field_contact_phone',
      'field_tags',
    ];

    // Add cancellation policy if field exists.
    if ($event->hasField('field_cancellation_policy')) {
      $policy_fields[] = 'field_cancellation_policy';
    }

    $this->buildEntityWidgets($event, $policy_fields, $form, $form_state);

    // Force field_accessibility to use select widget (multiple select) instead of autocomplete.
    if (isset($form['wizard']['content']['field_accessibility'])) {
      $this->convertTaxonomyToSelect($form['wizard']['content']['field_accessibility'], TRUE);
    }
  }

  /**
   *
   */
  private function buildReview(array &$form, FormStateInterface $form_state, NodeInterface $event): void {
    $url = Url::fromRoute('entity.node.canonical', ['node' => $event->id()]);
    $link = Link::fromTextAndUrl($this->t('View draft preview'), $url)->toString();

    $form['wizard']['content']['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-vendor-wizard__review']],
      'heading' => ['#markup' => '<h3>' . $this->t('Review & publish your event') . '</h3>'],
      'intro' => ['#markup' => '<p>' . $this->t('Check the details below. You can go back to make changes any time.') . '</p>'],
      'preview' => ['#markup' => '<p class="mel-vendor-wizard__preview-link">' . $link . '</p>'],
    ];

    // Lightweight summary (safe even if fields missing).
    $title = $event->label();
    $type = $event->get('field_event_type')->value ?? 'rsvp';
    $start = $event->get('field_event_start')->value ?? '';
    $date_formatter = \Drupal::service('date.formatter');
    $start_fmt = $start ? $date_formatter->format(strtotime($start), 'medium') : $this->t('Not set');

    $form['wizard']['content']['summary']['list'] = [
      '#theme' => 'item_list',
      '#items' => [
        $this->t('Title: @t', ['@t' => $title ?: $this->t('(Untitled)')]),
        $this->t('Type: @t', ['@t' => $type]),
        $this->t('Starts: @d', ['@d' => (string) $start_fmt]),
      ],
      '#attributes' => ['class' => ['mel-vendor-wizard__review-list']],
    ];
  }

  /**
   * ------------------------------------------------------------------------
   * VALIDATION + SUBMIT
   * ---------------------------------------------------------------------
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Validate only current step.
    $step = $this->currentStep($form_state);

    if ($step === 'basics') {
      $title_value = $form_state->getValue('title');
      // Handle both string and array values (field might return array for some widgets).
      $title = is_array($title_value) ? ($title_value[0]['value'] ?? '') : (string) $title_value;
      if (trim($title) === '') {
        $form_state->setErrorByName('title', $this->t('Title is required.'));
      }
    }

    // PHASE 3: When & Where step validation.
    if ($step === 'when_where') {
      // field_event_start REQUIRED.
      $start_value = $form_state->getValue(['field_event_start', 0, 'value']);
      if (empty($start_value)) {
        $form_state->setErrorByName('field_event_start', $this->t('Start time is required.'));
      }

      // field_event_end REQUIRED.
      $end_value = $form_state->getValue(['field_event_end', 0, 'value']);
      if (empty($end_value)) {
        $form_state->setErrorByName('field_event_end', $this->t('End time is required.'));
      }

      // field_venue_name REQUIRED (check wizard and top-level paths).
      $venue_name_value = $form_state->getValue(['_venue_wrapper', 'venue_name'])
        ?? $form_state->getValue(['wizard', 'content', '_venue_wrapper', 'venue_name'])
        ?? $form_state->getValue(['field_venue_name', 0, 'value']);
      if (is_string($venue_name_value)) {
        $venue_name_value = trim($venue_name_value);
      }
      if (empty($venue_name_value)) {
        $form_state->setErrorByName('venue_name', $this->t('Venue name is required.'));
      }

      // Venue address REQUIRED (from primary field_venue_address or field_location).
      $address = $this->getAddressFromFormState($form_state);
      $has_address = FALSE;
      if (!empty($address) && is_array($address)) {
        $line1 = $address['address_line1'] ?? '';
        $locality = $address['locality'] ?? '';
        $admin_area = $address['administrative_area'] ?? '';
        $country = $address['country_code'] ?? '';
        if (!empty(trim($line1))) {
          $has_address = TRUE;
        }
        elseif ((!empty(trim($locality)) || !empty(trim($admin_area))) && !empty(trim($country))) {
          $has_address = TRUE;
        }
      }
      if (!$has_address) {
        $form_state->setErrorByName('field_venue_address', $this->t('Venue address is required. Please provide at least a street address or suburb and state.'));
      }
    }

    // PHASE 3: Content step validation.
    if ($step === 'content') {
      // field_event_intro REQUIRED.
      $intro_value = $form_state->getValue(['field_event_intro', 0, 'value']);
      if (empty(trim($intro_value ?? ''))) {
        $form_state->setErrorByName('field_event_intro', $this->t('Event introduction is required.'));
      }

      // Body REQUIRED.
      $body_value = $form_state->getValue(['body', 0, 'value']);
      if (empty(trim($body_value ?? ''))) {
        $form_state->setErrorByName('body', $this->t('Event description is required.'));
      }
    }

    // PHASE 3: Policies & Accessibility step validation.
    if ($step === 'policies_accessibility') {
      // field_age_restriction REQUIRED.
      $age_value = $form_state->getValue(['field_age_restriction', 0, 'value']);
      if (empty($age_value)) {
        $form_state->setErrorByName('field_age_restriction', $this->t('Age restriction is required.'));
      }

      // field_accessibility OPTIONAL (no validation).
      // field_contact_email REQUIRED for paid events only.
      $event = $this->getEvent($form_state);
      $event_type = NULL;
      if ($event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()) {
        $event_type = $event->get('field_event_type')->value;
      }
      // Also check form state in case it was just changed.
      $event_type_value = $form_state->getValue(['field_event_type', 0, 'value']);
      if (!empty($event_type_value)) {
        $event_type = $event_type_value;
      }
      if (in_array($event_type, ['paid', 'both'], TRUE)) {
        $email_value = $form_state->getValue(['field_contact_email', 0, 'value']);
        if (empty(trim($email_value ?? ''))) {
          $form_state->setErrorByName('field_contact_email', $this->t('Contact email is required for paid events.'));
        }
      }
    }

    // Tickets & RSVP: validate event type is selected.
    if ($step === 'tickets_capacity') {
      $event_type_value = $form_state->getValue('field_event_type');
      $event_type = is_array($event_type_value)
        ? ($event_type_value[0]['value'] ?? $event_type_value['value'] ?? '')
        : (string) $event_type_value;

      if (empty($event_type) || !in_array($event_type, ['rsvp', 'paid', 'both', 'external'], TRUE)) {
        $form_state->setErrorByName('field_event_type', $this->t('Please select an event type.'));
      }
    }
  }

  /**
   * Default submit handler is not used (we use nextStepSubmit/backStep).
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * Save current step, then advance or publish.
   */
  public function nextStepSubmit(array &$form, FormStateInterface $form_state): void {
    $event = $this->getEvent($form_state);
    $step = $this->currentStep($form_state);

    // IMPORTANT:
    // Let Drupal's entity form display submit handlers write widget values onto the entity.
    // buildEntityWidgets() used entity_form_display->buildForm(), so its widgets know how
    // to submit into $event via the normal Form API process.
    //
    // Ensure we actually run the entity form display submit for the event bundle.
    $entity_type_manager = \Drupal::entityTypeManager();
    $display = $entity_type_manager
      ->getStorage('entity_form_display')
      ->load('node.event.default');

    if ($display) {
      // This will copy submitted widget values onto the entity object.
      $display->extractFormValues($event, $form, $form_state);
    }
    else {
      // Minimal fallback: title.
      if ($form_state->hasValue('title')) {
        $title_value = $form_state->getValue('title');
        // Handle both string and array values.
        $title = is_array($title_value) ? ($title_value[0]['value'] ?? '') : (string) $title_value;
        $event->setTitle($title);
      }
      // Minimal fallback for scalar fields.
      foreach ($form_state->getValues() as $k => $v) {
        if ($event->hasField($k) && !is_array($v)) {
          $event->set($k, $v);
        }
      }
    }

    // Save custom venue_name field if present (it's not part of entity widget).
    $this->saveVenueName($event, $form_state);

    // Save location address if present (populated by JavaScript).
    $this->saveLocationAddress($event, $form_state);

    // Preserve existing coordinates before extractFormValues.
    $existing_lat = NULL;
    $existing_lng = NULL;
    if ($event->hasField('field_location_latitude') && !$event->get('field_location_latitude')->isEmpty()) {
      $existing_lat = $event->get('field_location_latitude')->value;
    }
    if ($event->hasField('field_location_longitude') && !$event->get('field_location_longitude')->isEmpty()) {
      $existing_lng = $event->get('field_location_longitude')->value;
    }

    // Save location coordinates if present (populated by JavaScript).
    $this->saveLocationCoordinates($event, $form_state, $existing_lat, $existing_lng);

    // Save draft every step to ensure nothing gets lost.
    $event->save();

    // Track wizard progress in tempstore after first successful save.
    $this->trackWizardProgress($event, $step);

    // Advance to next step.
    $form_state->set(self::STATE_STEP, $this->nextStep($step));
    $form_state->setRebuild(TRUE);
  }

  /**
   * Save event without publishing (for edit mode).
   */
  public function saveEvent(array &$form, FormStateInterface $form_state): void {
    $event = $this->getEvent($form_state);

    // Preserve existing address and coordinates before extractFormValues
    // (which might clear them if the widget is hidden).
    $existing_address = NULL;
    if (!$event->get('field_location')->isEmpty()) {
      $existing_address = $event->get('field_location')->first()->getValue();
    }
    $existing_lat = NULL;
    $existing_lng = NULL;
    if ($event->hasField('field_location_latitude') && !$event->get('field_location_latitude')->isEmpty()) {
      $existing_lat = $event->get('field_location_latitude')->value;
    }
    if ($event->hasField('field_location_longitude') && !$event->get('field_location_longitude')->isEmpty()) {
      $existing_lng = $event->get('field_location_longitude')->value;
    }

    // Extract form values.
    $entity_type_manager = \Drupal::entityTypeManager();
    $display = $entity_type_manager
      ->getStorage('entity_form_display')
      ->load('node.event.default');

    if ($display) {
      $display->extractFormValues($event, $form, $form_state);
    }

    // Save custom venue_name field if present.
    $this->saveVenueName($event, $form_state);

    // Save location address if present (or restore existing if not changed).
    $this->saveLocationAddress($event, $form_state, $existing_address);

    // Save location coordinates if present (or restore existing if not changed).
    $this->saveLocationCoordinates($event, $form_state, $existing_lat, $existing_lng);

    // Save without changing publish status.
    $event->save();

    // Track wizard progress in tempstore after successful save.
    $step = $this->currentStep($form_state);
    $this->trackWizardProgress($event, $step);

    // Ensure ticket products exist for paid/both events.
    $this->ensureTicketProducts($event);

    $this->messenger()->addStatus($this->t('Event "@title" has been saved.', [
      '@title' => $event->label(),
    ]));

    // Redirect to vendor dashboard.
    $form_state->setRedirect('myeventlane_vendor.console.dashboard');
  }

  /**
   * Save as draft (for all steps, both create and edit mode).
   */
  public function saveDraft(array &$form, FormStateInterface $form_state): void {
    $event = $this->getEvent($form_state);

    // Preserve existing address and coordinates before extractFormValues
    // (which might clear them if the widget is hidden).
    $existing_address = NULL;
    if ($event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
      $existing_address = $event->get('field_location')->first()->getValue();
    }
    $existing_lat = NULL;
    $existing_lng = NULL;
    if ($event->hasField('field_location_latitude') && !$event->get('field_location_latitude')->isEmpty()) {
      $existing_lat = $event->get('field_location_latitude')->value;
    }
    if ($event->hasField('field_location_longitude') && !$event->get('field_location_longitude')->isEmpty()) {
      $existing_lng = $event->get('field_location_longitude')->value;
    }

    // Extract form values.
    $entity_type_manager = \Drupal::entityTypeManager();
    $display = $entity_type_manager
      ->getStorage('entity_form_display')
      ->load('node.event.default');

    if ($display) {
      $display->extractFormValues($event, $form, $form_state);
    }

    // Save custom venue_name field if present.
    $this->saveVenueName($event, $form_state);

    // Save location address if present (or restore existing if not changed).
    $this->saveLocationAddress($event, $form_state, $existing_address);

    // Save location coordinates if present (or restore existing if not changed).
    $this->saveLocationCoordinates($event, $form_state, $existing_lat, $existing_lng);

    // Ensure it's saved as draft.
    $event->setUnpublished();
    $event->save();

    // Track wizard progress in tempstore after successful save.
    $step = $this->currentStep($form_state);
    $this->trackWizardProgress($event, $step);

    // Ensure ticket products exist for paid/both events (even for drafts).
    $this->ensureTicketProducts($event);

    $this->messenger()->addStatus($this->t('Draft saved.'));

    // Stay on current step.
    $form_state->setRebuild(TRUE);
  }

  /**
   * Publish event (for both create and edit modes).
   */
  public function publishEvent(array &$form, FormStateInterface $form_state): void {
    $event = $this->getEvent($form_state);

    // Extract form values.
    $entity_type_manager = \Drupal::entityTypeManager();
    $display = $entity_type_manager
      ->getStorage('entity_form_display')
      ->load('node.event.default');

    if ($display) {
      $display->extractFormValues($event, $form, $form_state);
    }

    // Save custom venue_name field if present.
    $this->saveVenueName($event, $form_state);

    // Preserve existing address and coordinates.
    $existing_address = NULL;
    if (!$event->get('field_location')->isEmpty()) {
      $existing_address = $event->get('field_location')->first()->getValue();
    }
    $existing_lat = NULL;
    $existing_lng = NULL;
    if ($event->hasField('field_location_latitude') && !$event->get('field_location_latitude')->isEmpty()) {
      $existing_lat = $event->get('field_location_latitude')->value;
    }
    if ($event->hasField('field_location_longitude') && !$event->get('field_location_longitude')->isEmpty()) {
      $existing_lng = $event->get('field_location_longitude')->value;
    }

    // Save location address if present.
    $this->saveLocationAddress($event, $form_state, $existing_address);

    // Save location coordinates if present.
    $this->saveLocationCoordinates($event, $form_state, $existing_lat, $existing_lng);

    // Publish the event.
    $event->setPublished(TRUE);
    $event->save();

    // Ensure ticket products exist for paid/both events.
    $this->ensureTicketProducts($event);

    $this->messenger()->addStatus($this->t('Event "@title" has been published!', [
      '@title' => $event->label(),
    ]));

    // Redirect to vendor dashboard.
    $form_state->setRedirect('myeventlane_vendor.console.dashboard');
  }

  /**
   *
   */
  public function backStep(array &$form, FormStateInterface $form_state): void {
    $step = $this->currentStep($form_state);
    $form_state->set(self::STATE_STEP, $this->prevStep($step));
    $form_state->setRebuild(TRUE);
  }

  /**
   * Go to a specific step (for stepper button clicks).
   */
  public function gotoStep(array &$form, FormStateInterface $form_state): void {
    // Get target step from the clicked button's data attribute via form state trigger.
    $triggering_element = $form_state->getTriggeringElement();
    $target_step = NULL;

    if ($triggering_element && isset($triggering_element['#attributes']['data-step-target'])) {
      $target_step = $triggering_element['#attributes']['data-step-target'];
    }
    elseif ($triggering_element && isset($triggering_element['#attributes']['data-wizard-step'])) {
      $target_step = $triggering_element['#attributes']['data-wizard-step'];
    }

    // Fallback: check form values.
    if (!$target_step) {
      $target_step = (string) ($form_state->getValue('wizard_step') ?? '');
    }

    if ($target_step !== '' && in_array($target_step, self::STEPS, TRUE)) {
      $form_state->set(self::STATE_STEP, $target_step);
    }
    $form_state->setRebuild(TRUE);
  }

  /**
   * AJAX refresh: return wrapper content.
   */
  public function ajaxRefresh(array &$form, FormStateInterface $form_state): array {
    return $form;
  }

  /**
   * Converts taxonomy autocomplete widget to select widget.
   *
   * @param array &$field_element
   *   The field form element (passed by reference).
   * @param bool $multiple
   *   Whether to allow multiple selections.
   */
  private function convertTaxonomyToSelect(array &$field_element, bool $multiple = TRUE): void {
    // Check if this is a taxonomy entity reference field.
    if (!isset($field_element['widget'])) {
      return;
    }

    // Get field name and event.
    $field_name = $field_element['#field_name'] ?? NULL;
    $event = $field_element['#wizard_event'] ?? NULL;

    if (!$field_name || !$event) {
      return;
    }

    // Load field config to get vocabulary.
    $field_config = \Drupal::entityTypeManager()
      ->getStorage('field_config')
      ->load('node.event.' . $field_name);

    if (!$field_config) {
      return;
    }

    $handler_settings = $field_config->getSetting('handler_settings');
    $target_bundles = $handler_settings['target_bundles'] ?? [];

    if (empty($target_bundles)) {
      return;
    }

    // Get terms from the vocabulary, sorted by weight.
    $vocabulary = reset($target_bundles);
    $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $terms = $term_storage->loadTree($vocabulary, 0, NULL, TRUE);

    $options = [];
    foreach ($terms as $term) {
      $options[$term->id()] = $term->getName();
    }

    // Get current value from event.
    $default_value = [];
    if ($event->hasField($field_name) && !$event->get($field_name)->isEmpty()) {
      foreach ($event->get($field_name) as $item) {
        if ($item->entity) {
          $default_value[] = $item->entity->id();
        }
      }
    }

    // Replace widget with select.
    $field_element['widget'] = [
      '#type' => 'select',
      '#title' => $field_element['#title'] ?? $this->t('Select'),
      '#description' => $field_element['#description'] ?? NULL,
      '#options' => $options,
      '#multiple' => $multiple,
      '#default_value' => $multiple ? $default_value : ($default_value[0] ?? NULL),
      '#required' => $field_element['#required'] ?? FALSE,
      '#empty_option' => $multiple ? NULL : $this->t('- Select -'),
      '#empty_value' => $multiple ? NULL : '',
    ];
  }

  /**
   * Gets address array from form state (wizard and step forms).
   *
   * Handles widget-wrapped paths (field_*[widget][0][address]) and primary
   * field_venue_address in the wizard.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array|null
   *   Address component array or NULL if not found.
   */
  private function getAddressFromFormState(FormStateInterface $form_state): ?array {
    $paths = [
      // Wizard: primary address field (what user edits).
      ['wizard', 'content', '_venue_wrapper', 'field_venue_address', 'widget', 0, 'address'],
      ['wizard', 'content', '_venue_wrapper', 'field_location', 'widget', 0, 'address'],
      ['wizard', 'content', '_venue_wrapper', 'field_location', 0, 'address'],
      ['wizard', 'content', 'field_location', 0, 'address'],
      // Top-level (step form or default).
      ['field_venue_address', 0, 'address'],
      ['field_location', 'widget', 0, 'address'],
      ['field_location', 0, 'address'],
    ];
    foreach ($paths as $path) {
      if ($form_state->hasValue($path)) {
        $value = $form_state->getValue($path);
        if (is_array($value)) {
          return $value;
        }
      }
    }
    return NULL;
  }

  /**
   * Saves location address from hidden form widget to event entity.
   *
   * The address is populated by JavaScript into the hidden field_location widget
   * or the primary field_venue_address. We explicitly extract it because
   * extractFormValues() may skip hidden widgets or use widget-wrapped paths.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array|null $existing_address
   *   Existing address to restore if form state has none.
   */
  private function saveLocationAddress(NodeInterface $event, FormStateInterface $form_state, ?array $existing_address = NULL): void {
    // Check if field_location exists on the event.
    if (!$event->hasField('field_location')) {
      return;
    }

    $address_value = $this->getAddressFromFormState($form_state);
    $user_input = $form_state->getUserInput();

    // Fallback: user input (raw POST) if form state didn't have it.
    if ($address_value === NULL && isset($user_input['field_location'][0]['address']) && is_array($user_input['field_location'][0]['address'])) {
      $address_value = $user_input['field_location'][0]['address'];
    }
    if ($address_value === NULL && isset($user_input['field_location']['widget'][0]['address']) && is_array($user_input['field_location']['widget'][0]['address'])) {
      $address_value = $user_input['field_location']['widget'][0]['address'];
    }
    if ($address_value === NULL && isset($user_input['wizard']['content']['_venue_wrapper']['field_location']['widget'][0]['address']) && is_array($user_input['wizard']['content']['_venue_wrapper']['field_location']['widget'][0]['address'])) {
      $address_value = $user_input['wizard']['content']['_venue_wrapper']['field_location']['widget'][0]['address'];
    }
    if ($address_value === NULL && isset($user_input['wizard']['content']['_venue_wrapper']['field_venue_address']['widget'][0]['address']) && is_array($user_input['wizard']['content']['_venue_wrapper']['field_venue_address']['widget'][0]['address'])) {
      $address_value = $user_input['wizard']['content']['_venue_wrapper']['field_venue_address']['widget'][0]['address'];
    }
    if ($address_value === NULL && isset($user_input['wizard']['content']['_venue_wrapper']['field_location'][0]['address']) && is_array($user_input['wizard']['content']['_venue_wrapper']['field_location'][0]['address'])) {
      $address_value = $user_input['wizard']['content']['_venue_wrapper']['field_location'][0]['address'];
    }

    // If we have address data, save it to the entity.
    if ($address_value && is_array($address_value)) {
      // Filter out empty values and ensure we have at least one component.
      $filtered = array_filter($address_value, function ($value) {
        return !empty($value) && $value !== '';
      });

      if (!empty($filtered)) {
        // Set the address value on the entity.
        $event->set('field_location', [$address_value]);
      }
    }
    // If no address in form state but we have existing address, restore it.
    // This handles cases where extractFormValues cleared it because widget is hidden.
    elseif ($existing_address && is_array($existing_address)) {
      $filtered = array_filter($existing_address, function ($value) {
        return !empty($value) && $value !== '';
      });
      if (!empty($filtered)) {
        $event->set('field_location', [$existing_address]);
      }
    }
  }

  /**
   * Saves location coordinates from hidden form fields to event entity.
   *
   * Coordinates are populated by JavaScript (venue selection widget) into
   * hidden fields added by myeventlane_location module form_alter.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  private function saveLocationCoordinates(NodeInterface $event, FormStateInterface $form_state, $existing_lat = NULL, $existing_lng = NULL): void {
    // Check if coordinate fields exist on the event.
    if (!$event->hasField('field_location_latitude') || !$event->hasField('field_location_longitude')) {
      return;
    }

    $lat = NULL;
    $lng = NULL;

    // Get coordinates from hidden fields added by myeventlane_location module.
    // These fields are named 'myeventlane_location_latitude' and 'myeventlane_location_longitude'.
    $user_input = $form_state->getUserInput();

    // Check custom hidden fields first (from JavaScript population).
    if (isset($user_input['myeventlane_location_latitude']) && !empty($user_input['myeventlane_location_latitude']) && is_numeric($user_input['myeventlane_location_latitude'])) {
      $lat = (float) $user_input['myeventlane_location_latitude'];
    }
    elseif ($form_state->hasValue('myeventlane_location_latitude')) {
      $lat_value = $form_state->getValue('myeventlane_location_latitude');
      if (!empty($lat_value) && is_numeric($lat_value)) {
        $lat = (float) $lat_value;
      }
    }

    if (isset($user_input['myeventlane_location_longitude']) && !empty($user_input['myeventlane_location_longitude']) && is_numeric($user_input['myeventlane_location_longitude'])) {
      $lng = (float) $user_input['myeventlane_location_longitude'];
    }
    elseif ($form_state->hasValue('myeventlane_location_longitude')) {
      $lng_value = $form_state->getValue('myeventlane_location_longitude');
      if (!empty($lng_value) && is_numeric($lng_value)) {
        $lng = (float) $lng_value;
      }
    }

    // Also check the actual entity fields in case they were set directly.
    if ($lat === NULL && isset($user_input['field_location_latitude']) && !empty($user_input['field_location_latitude']) && is_numeric($user_input['field_location_latitude'])) {
      $lat = (float) $user_input['field_location_latitude'];
    }
    if ($lng === NULL && isset($user_input['field_location_longitude']) && !empty($user_input['field_location_longitude']) && is_numeric($user_input['field_location_longitude'])) {
      $lng = (float) $user_input['field_location_longitude'];
    }

    // Check for dedicated coordinate fields in form state.
    if ($lat === NULL && $form_state->hasValue(['field_location_latitude', 0, 'value'])) {
      $lat_value = $form_state->getValue(['field_location_latitude', 0, 'value']);
      if (!empty($lat_value) && is_numeric($lat_value)) {
        $lat = (float) $lat_value;
      }
    }

    if ($lng === NULL && $form_state->hasValue(['field_location_longitude', 0, 'value'])) {
      $lng_value = $form_state->getValue(['field_location_longitude', 0, 'value']);
      if (!empty($lng_value) && is_numeric($lng_value)) {
        $lng = (float) $lng_value;
      }
    }

    // Save coordinates to entity fields if we have valid values.
    if ($lat !== NULL && is_numeric($lat) && $lat >= -90 && $lat <= 90) {
      $event->set('field_location_latitude', $lat);
    }
    // If no new coordinates but we have existing ones, restore them.
    elseif ($existing_lat !== NULL && is_numeric($existing_lat)) {
      $event->set('field_location_latitude', $existing_lat);
    }

    if ($lng !== NULL && is_numeric($lng) && $lng >= -180 && $lng <= 180) {
      $event->set('field_location_longitude', $lng);
    }
    // If no new coordinates but we have existing ones, restore them.
    elseif ($existing_lng !== NULL && is_numeric($existing_lng)) {
      $event->set('field_location_longitude', $existing_lng);
    }
  }

  /**
   * Saves venue_name from custom form field to event entity.
   *
   * The venue_name field is a custom form field (not part of entity widget),
   * so it needs to be manually saved from form state.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  private function saveVenueName(NodeInterface $event, FormStateInterface $form_state): void {
    // Check if venue_name field exists on the event.
    if (!$event->hasField('field_venue_name')) {
      return;
    }

    // Get venue_name from form state.
    // The field is in wizard.content._venue_wrapper.venue_name, but after form processing
    // it may be in different locations. Check multiple possible paths.
    $venue_name_value = NULL;

    // Try the most likely path first (nested in _venue_wrapper).
    $value = $form_state->getValue(['_venue_wrapper', 'venue_name']);
    if ($value !== NULL && $value !== '') {
      $venue_name_value = is_array($value) ? ($value[0] ?? $value) : (string) $value;
    }

    // Try alternative paths.
    if ($venue_name_value === NULL) {
      $all_values = $form_state->getValues();

      // Check nested structure.
      if (isset($all_values['_venue_wrapper']['venue_name'])) {
        $value = $all_values['_venue_wrapper']['venue_name'];
        $venue_name_value = is_array($value) ? ($value[0] ?? $value) : (string) $value;
      }
      // Check direct access.
      elseif (isset($all_values['venue_name'])) {
        $value = $all_values['venue_name'];
        $venue_name_value = is_array($value) ? ($value[0] ?? $value) : (string) $value;
      }
      // Check wizard.content path.
      elseif (isset($all_values['wizard']['content']['_venue_wrapper']['venue_name'])) {
        $value = $all_values['wizard']['content']['_venue_wrapper']['venue_name'];
        $venue_name_value = is_array($value) ? ($value[0] ?? $value) : (string) $value;
      }
    }

    // Save venue_name if we have a value.
    if ($venue_name_value !== NULL && $venue_name_value !== '') {
      $event->set('field_venue_name', trim($venue_name_value));
    }
  }

  /**
   * Recursively hide unused address subfields in form element.
   *
   * @param array &$element
   *   Form element to process (passed by reference).
   */
  private function hideUnusedAddressFieldsRecursive(array &$element): void {
    if (!is_array($element)) {
      return;
    }

    $unused_fields = ['organization', 'given_name', 'family_name'];

    foreach ($element as $key => &$value) {
      // Skip metadata keys (those starting with #) - handle both string and integer keys.
      if (is_string($key) && str_starts_with($key, '#')) {
        continue;
      }

      // If this key is one of the unused fields, hide it.
      if (in_array($key, $unused_fields, TRUE)) {
        if (is_array($value)) {
          $value['#access'] = FALSE;
          $value['#required'] = FALSE;
        }
        // Optionally unset it completely
        // unset($element[$key]);.
      }

      // Recursively process nested arrays.
      if (is_array($value)) {
        $this->hideUnusedAddressFieldsRecursive($value);
      }
    }
  }

  /**
   * Ensures ticket products exist for paid/both events.
   *
   * This method creates or updates Commerce products based on the event's
   * ticket type configuration. It is called at wizard completion to ensure
   * products exist immediately, even for draft events.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   */
  private function ensureTicketProducts(NodeInterface $event): void {
    // Only process saved events.
    if ($event->isNew()) {
      return;
    }

    // Get event type, handling both array and scalar values.
    $event_type_value = $event->get('field_event_type')->value ?? NULL;
    $type = is_array($event_type_value)
      ? ($event_type_value[0]['value'] ?? NULL)
      : $event_type_value;

    // Only create products for paid or both event types.
    if (!in_array($type, ['paid', 'both'], TRUE)) {
      return;
    }

    // Use TicketTypeManager to sync ticket types to Commerce variations.
    // This service handles product creation and variation sync idempotently.
    if (\Drupal::hasService('myeventlane_event.ticket_type_manager')) {
      /** @var \Drupal\myeventlane_event\Service\TicketTypeManager $ticket_manager */
      $ticket_manager = \Drupal::service('myeventlane_event.ticket_type_manager');
      $ticket_manager->syncTicketTypesToVariations($event);
    }
  }

  /**
   * Tracks wizard progress in tempstore after successful save.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param string $completed_step
   *   The step that was just completed.
   */
  private function trackWizardProgress(NodeInterface $event, string $completed_step): void {
    if ($event->isNew()) {
      return;
    }

    $tempstore = \Drupal::service('tempstore.private')->get(self::TEMPSTORE_KEY);
    $event_id = (int) $event->id();

    // Get current progress or initialize.
    $progress = $tempstore->get((string) $event_id) ?? [
      'wizard_started' => FALSE,
      'highest_completed_step' => NULL,
    ];

    // Mark wizard as started on first save.
    if (!$progress['wizard_started']) {
      $progress['wizard_started'] = TRUE;
    }

    // Update highest completed step.
    $completed_index = $this->stepIndex($completed_step);
    $current_highest_index = $progress['highest_completed_step'] !== NULL
      ? $this->stepIndex($progress['highest_completed_step'])
      : -1;

    if ($completed_index > $current_highest_index) {
      $progress['highest_completed_step'] = $completed_step;
    }

    $tempstore->set((string) $event_id, $progress);
  }

  /**
   * Gets wizard progress from tempstore.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Array with 'wizard_started' (bool) and 'highest_completed_step' (string|null).
   */
  public function getWizardProgress(NodeInterface $event): array {
    if ($event->isNew()) {
      return [
        'wizard_started' => FALSE,
        'highest_completed_step' => NULL,
      ];
    }

    $tempstore = \Drupal::service('tempstore.private')->get(self::TEMPSTORE_KEY);
    $event_id = (string) $event->id();

    $progress = $tempstore->get($event_id);

    if ($progress === NULL) {
      // If no progress in tempstore, check if event has been saved before.
      // If event exists and is not new, wizard has started.
      $progress = [
        'wizard_started' => !$event->isNew(),
        'highest_completed_step' => NULL,
      ];
    }

    return $progress;
  }

}
