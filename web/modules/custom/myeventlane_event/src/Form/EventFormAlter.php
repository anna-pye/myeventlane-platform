<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;

/**
 * Form alteration service for Event node forms.
 *
 * Implements a 7-step wizard for event creation/editing:
 * 1. Event Basics (title, description, image)
 * 2. Schedule (start/end dates)
 * 3. Location (venue, address)
 * 4. Attendance Type (RSVP/Paid/External)
 * 5. Attendance Details (conditional based on type)
 * 6. Extras (category, accessibility, questions)
 * 7. Review & Publish.
 */
final class EventFormAlter {

  private EntityTypeManagerInterface $entityTypeManager;
  private AccountProxyInterface $currentUser;
  private RouteMatchInterface $routeMatch;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    RouteMatchInterface $route_match,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->routeMatch = $route_match;
  }

  /**
   * Alters the Event node form into a wizard.
   */
  public function alterForm(array &$form, FormStateInterface $form_state): void {
    // Only alter event forms (vendor and admin).
    if (!$this->isEventForm()) {
      return;
    }

    // Defensive checks - ensure form is valid.
    if (empty($form) || !is_array($form)) {
      \Drupal::logger('myeventlane_event')->error('EventFormAlter: Form is empty or not an array');
      $form = ['#type' => 'form', '#attributes' => []];
      return;
    }

    $node = $form_state->getFormObject()->getEntity();
    if (!$node instanceof NodeInterface) {
      \Drupal::logger('myeventlane_event')->error('EventFormAlter: Node entity is null or invalid');
      return;
    }

    $is_new = $node->isNew();

    // CRITICAL: Hide booking_status BEFORE building steps, as it's added in myeventlane_event.module
    // This must run early to prevent it from being moved into step containers.
    if (isset($form['booking_status'])) {
      $form['booking_status']['#access'] = FALSE;
      $form['booking_status']['#printed'] = TRUE;
      unset($form['booking_status']);
    }

    // Also hide status fieldset if it exists.
    if (isset($form['status'])) {
      $form['status']['#access'] = FALSE;
      unset($form['status']);
    }

    // Ensure form has attributes array.
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
    $form['#attributes']['class'][] = 'mel-event-wizard';

    // Initialize wizard state.
    $this->initializeWizard($form, $form_state, $node);

    // Handle vendor/store fields (hidden, auto-populated).
    $this->handleVendorStore($form, $form_state, $is_new);

    // Build wizard steps first.
    $this->buildStepBasics($form, $form_state);
    $this->buildStepSchedule($form, $form_state);
    $this->buildStepLocation($form, $form_state);
    $this->buildStepAttendanceType($form, $form_state);
    $this->buildStepAttendanceDetails($form, $form_state);
    $this->buildStepExtras($form, $form_state);
    $this->buildStepReview($form, $form_state, $node);

    // Build left vertical stepper.
    $this->buildLeftStepper($form, $form_state);

    // Build sticky action bar.
    $this->buildStickyActionBar($form, $form_state, $node, $is_new);

    // Remove/hide legacy tabbed UI if it exists (after building steps).
    $this->removeLegacyTabs($form);

    // Hide admin-only fields from vendors.
    $this->hideAdminFields($form);

    // Hide booking status messages (RSVP mode active, etc.) - vendors don't need this.
    // This must be done early, before fields are moved to step containers.
    if (isset($form['booking_status'])) {
      $form['booking_status']['#access'] = FALSE;
      $form['booking_status']['#printed'] = TRUE;
      // Remove it completely from render array.
      unset($form['booking_status']);
    }

    // Also hide any status messages that might appear.
    if (isset($form['status_messages'])) {
      $form['status_messages']['#access'] = FALSE;
      unset($form['status_messages']);
    }

    // Hide any messages containers.
    if (isset($form['messages'])) {
      $form['messages']['#access'] = FALSE;
    }

    // Hide the "Status" fieldset that shows "RSVP mode active" - this is added in myeventlane_event.module
    // We need to hide it after all form alters run, so we'll use CSS as backup.
    // But also try to hide it here if it exists.
    if (isset($form['status'])) {
      $form['status']['#access'] = FALSE;
    }

    // Ensure only current step is visible initially.
    $this->hideNonActiveSteps($form, $form_state);

    // Wrap wizard components in a single container for Twig to render.
    // This must be done AFTER hideNonActiveSteps so it can operate on root-level steps.
    $this->wrapWizardComponents($form, $form_state);

    // Attach libraries.
    $this->attachLibraries($form);

    // Add hidden field for current step.
    $current_step = $form_state->get('wizard_current_step') ?? 'basics';
    $form['wizard_current_step'] = [
      '#type' => 'hidden',
      '#value' => $current_step,
    ];

    // Add hidden field for auto-save trigger.
    $form['wizard_auto_save'] = [
      '#type' => 'hidden',
      '#value' => '0',
    ];
  }

  /**
   * Hide admin-only fields that vendors shouldn't see.
   */
  private function hideAdminFields(array &$form): void {
    // Admin-only fields to hide from vendors.
    $admin_fields = [
    // Published checkbox.
      'status',
    // Revision information.
      'revision',
    // Alternative revision field name.
      'revision_information',
    // Revision log message.
      'revision_log',
    // Menu settings.
      'menu',
    // Menu link settings.
      'menu_link',
    // URL path settings (sometimes admin-only)
      'path',
    // Promote to front page.
      'promote',
    // Sticky at top of lists.
      'sticky',
    // Author (user ID)
      'uid',
    // Created date.
      'created',
    // Changed date.
      'changed',
    // Advanced options container.
      'advanced',
    // Booking status messages (RSVP mode active, etc.)
      'booking_status',
    ];

    foreach ($admin_fields as $field_name) {
      if (isset($form[$field_name])) {
        $form[$field_name]['#access'] = FALSE;
      }
    }

    // Also hide any fields in the 'advanced' container if it exists.
    if (isset($form['advanced']) && is_array($form['advanced'])) {
      $form['advanced']['#access'] = FALSE;

      // Recursively hide all children.
      foreach ($form['advanced'] as $key => &$child) {
        if (is_string($key) && !str_starts_with($key, '#')) {
          if (is_array($child)) {
            $child['#access'] = FALSE;
          }
        }
      }
      unset($child);
    }
  }

  /**
   * Remove/hide legacy tabbed UI elements.
   */
  private function removeLegacyTabs(array &$form): void {
    // Hide legacy simple tabs navigation from myeventlane_vendor module.
    if (isset($form['simple_tabs_nav'])) {
      $form['simple_tabs_nav']['#access'] = FALSE;
      unset($form['simple_tabs_nav']);
    }

    // Remove tab pane wrappers that might interfere.
    // The wizard replaces tabs entirely, so we don't need tab panes.
    $tab_sections = ['event_basics', 'date_time', 'location', 'booking_config', 'visibility', 'attendee_questions'];
    foreach ($tab_sections as $section) {
      if (isset($form[$section]) && is_array($form[$section])) {
        // Remove tab pane classes that might hide/show content incorrectly.
        if (isset($form[$section]['#attributes']['class'])) {
          $form[$section]['#attributes']['class'] = array_filter(
            $form[$section]['#attributes']['class'],
            function ($class) {
              return !in_array($class, ['mel-tab-pane', 'mel-simple-tab-pane', 'is-active'], TRUE);
            }
          );
        }
      }
    }
  }

  /**
   * Hide all steps except the current one using #access.
   */
  private function hideNonActiveSteps(array &$form, FormStateInterface $form_state): void {
    $current_step = $form_state->get('wizard_current_step') ?? 'basics';
    $steps = $form_state->get('wizard_steps') ?? [];
    $step_keys = array_keys($steps);

    // Map step keys to form section keys.
    $step_section_map = [
      'basics' => 'event_basics',
      'schedule' => 'schedule',
      'location' => 'location',
      'attendance_type' => 'attendance_type',
      'attendance_details' => 'attendance_details',
      'extras' => 'extras',
      'review' => 'review',
    ];

    foreach ($step_keys as $step_key) {
      $section_key = $step_section_map[$step_key] ?? $step_key;

      // Try both the step key and section key.
      // Check root level first, then inside wrapper if it exists.
      $sections_to_process = [];
      if (isset($form[$step_key])) {
        $sections_to_process[$step_key] = &$form[$step_key];
      }
      if (isset($form[$section_key]) && $section_key !== $step_key) {
        $sections_to_process[$section_key] = &$form[$section_key];
      }
      // Also check inside wrapper if it exists (for form rebuilds).
      if (isset($form['mel_event_wizard']['steps'][$section_key])) {
        $sections_to_process['wrapped_' . $section_key] = &$form['mel_event_wizard']['steps'][$section_key];
      }

      foreach ($sections_to_process as $key => &$section) {
        if (!is_array($section)) {
          continue;
        }

        if ($step_key !== $current_step) {
          // Hide non-active steps using #access (most reliable method).
          $section['#access'] = FALSE;
        }
        else {
          // Show active step - explicitly set access.
          $section['#access'] = TRUE;

          // Ensure content container is accessible.
          if (isset($section['content'])) {
            $section['content']['#access'] = TRUE;
          }

          // Recursively ensure child elements are accessible.
          $this->showFormElementRecursive($section);
        }
      }
      unset($section);
    }

    // Also hide any fields that are not in any step container.
    // These are likely fields that weren't moved into step containers.
    $this->hideOrphanedFields($form, $form_state, $current_step);

    // Ensure booking_status is hidden (RSVP mode active message).
    if (isset($form['booking_status'])) {
      $form['booking_status']['#access'] = FALSE;
    }
  }

  /**
   * Recursively hide a form element and all its children.
   */
  private function hideFormElementRecursive(array &$element): void {
    $element['#access'] = FALSE;

    foreach ($element as $key => &$child) {
      if (is_array($child) && (is_string($key) && !str_starts_with($key, '#')) || is_int($key)) {
        $this->hideFormElementRecursive($child);
      }
    }
    unset($child);
  }

  /**
   * Recursively show a form element and all its children.
   */
  private function showFormElementRecursive(array &$element): void {
    // Only remove #access = FALSE if it exists.
    // Don't set #access = TRUE as that might override other access control.
    if (isset($element['#access']) && $element['#access'] === FALSE) {
      unset($element['#access']);
    }

    foreach ($element as $key => &$child) {
      if (is_array($child)) {
        // Only skip if key is a string starting with '#' (form properties).
        // Integer keys (array indices) should be processed.
        if (is_string($key) && str_starts_with($key, '#')) {
          continue;
        }
        $this->showFormElementRecursive($child);
      }
    }
    unset($child);
  }

  /**
   * Hide fields that are not in any step container (orphaned fields).
   */
  private function hideOrphanedFields(array &$form, FormStateInterface $form_state, string $current_step): void {
    // Define which fields belong to which step.
    $step_fields = [
      'basics' => ['title', 'body', 'field_event_image'],
      'schedule' => ['field_event_start', 'field_event_end'],
      'location' => ['field_location', 'field_venue_name'],
      'attendance_type' => ['field_event_type'],
      'attendance_details' => ['field_capacity', 'field_waitlist_capacity', 'field_ticket_types', 'field_external_url', 'rsvp_fields', 'paid_fields', 'external_fields'],
      'extras' => ['field_category', 'field_accessibility', 'field_attendee_questions'],
      'review' => [],
    ];

    // Fields that should always be accessible (form system fields).
    $always_allowed = [
      'wizard_stepper',
      'wizard_current_step',
      'wizard_auto_save',
      'actions',
      'form_build_id',
      'form_token',
      'form_id',
      'field_event_vendor',
      'field_event_store',
      'event_basics',
      'schedule',
      'location',
      'attendance_type',
      'attendance_details',
      'extras',
      'review',
    ];

    // Get fields that should be visible in current step.
    $current_step_fields = $step_fields[$current_step] ?? [];
    $allowed_fields = array_merge($current_step_fields, $always_allowed);

    // Hide any fields that are not in the allowed list and not in a step container.
    foreach ($form as $key => &$element) {
      if (str_starts_with($key, '#')) {
        continue;
      }

      // Skip if this is an allowed field or step container.
      if (in_array($key, $allowed_fields, TRUE)) {
        continue;
      }

      // Check if this field is nested inside a step container.
      $is_in_step_container = FALSE;
      foreach ($step_fields as $step => $fields) {
        if (in_array($key, $fields, TRUE)) {
          // This field should be in a step container - if it's at root level, hide it.
          $is_in_step_container = TRUE;
          break;
        }
      }

      // Hide fields that are at root level but should be in step containers.
      if ($is_in_step_container && is_array($element)) {
        $element['#access'] = FALSE;
      }

      // Also hide specific fields that shouldn't be visible in basics step.
      $hidden_in_basics = [
        'field_location_latitude',
        'field_location_longitude',
        'field_product_target',
        'field_accessibility_contact',
        'field_accessibility_directions',
        'field_accessibility_entry',
        'field_accessibility_parking',
        'field_organizer',
        'field_rsvp_target',
        'status',
        'revision',
        'revision_information',
        'revision_log',
        'menu',
        'menu_link',
        'promote',
        'sticky',
        'uid',
        'created',
        'changed',
        'advanced',
      ];

      if ($current_step === 'basics' && in_array($key, $hidden_in_basics, TRUE)) {
        $element['#access'] = FALSE;
      }
    }
    unset($element);
  }

  /**
   * Check if this is an event form (vendor or admin).
   */
  private function isEventForm(): bool {
    $route_name = $this->routeMatch->getRouteName();

    // Vendor routes.
    $vendor_routes = [
      'myeventlane_vendor.console.events_add',
      'myeventlane_vendor.manage_event.basics',
    ];
    if (in_array($route_name, $vendor_routes, TRUE)) {
      return TRUE;
    }

    // Admin routes - check if it's an event node form.
    if (in_array($route_name, ['node.add', 'entity.node.edit_form'], TRUE)) {
      $node = $this->routeMatch->getParameter('node');
      if ($node instanceof NodeInterface && $node->bundle() === 'event') {
        return TRUE;
      }
      // For add form, check bundle parameter.
      $bundle = $this->routeMatch->getParameter('node_type');
      if ($bundle && method_exists($bundle, 'id') && $bundle->id() === 'event') {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Initialize wizard state and step tracking.
   */
  private function initializeWizard(array &$form, FormStateInterface $form_state, NodeInterface $node): void {
    // Define wizard steps according to requirements.
    $steps = [
      'basics' => [
        'title' => t('Event basics'),
        'description' => t("What's your event?"),
        'section' => 'event_basics',
        'required_fields' => ['title'],
      ],
      'schedule' => [
        'title' => t('Schedule'),
        'description' => t('When does it happen?'),
        'section' => 'schedule',
        'required_fields' => ['field_event_start'],
      ],
      'location' => [
        'title' => t('Location'),
        'description' => t('Where will people go?'),
        'section' => 'location',
        'required_fields' => [],
      ],
      'attendance_type' => [
        'title' => t('Attendance type'),
        'description' => t('How people join'),
        'section' => 'attendance_type',
        'required_fields' => ['field_event_type'],
      ],
      'attendance_details' => [
        'title' => t('Attendance details'),
        'description' => t('Tickets, capacity, access'),
        'section' => 'attendance_details',
        'required_fields' => [],
        'conditional' => TRUE,
      ],
      'extras' => [
        'title' => t('Extras'),
        'description' => t('Optional additions'),
        'section' => 'extras',
        'required_fields' => [],
      ],
      'review' => [
        'title' => t('Review & publish'),
        'description' => t('Final check'),
        'section' => 'review',
        'required_fields' => [],
      ],
    ];

    // Get current step from form state, URL parameter, or default to first.
    $current_step = $form_state->get('wizard_current_step');
    if (empty($current_step)) {
      $request = \Drupal::request();
      $current_step = $request->query->get('step', 'basics');
    }
    if (!isset($steps[$current_step])) {
      $current_step = 'basics';
    }

    // Store wizard configuration in form state.
    $form_state->set('wizard_steps', $steps);
    $form_state->set('wizard_current_step', $current_step);

    // Store in form for JavaScript access.
    $form['#attached']['drupalSettings']['eventWizard'] = [
      'activeStep' => $current_step,
    // Keep for backward compatibility.
      'currentStep' => $current_step,
      'completedSteps' => [],
      'allowForward' => FALSE,
      'steps' => array_keys($steps),
      'stepConfig' => $steps,
    ];
  }

  /**
   * Build left vertical stepper navigation.
   */
  private function buildLeftStepper(array &$form, FormStateInterface $form_state): void {
    $steps = $form_state->get('wizard_steps') ?? [];
    $current_step = $form_state->get('wizard_current_step') ?? 'basics';

    if (empty($steps)) {
      return;
    }

    // Left stepper container.
    $form['wizard_stepper'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-wizard-stepper'],
        'role' => 'navigation',
        'aria-label' => t('Wizard steps'),
      ],
      '#weight' => -10000,
    ];

    $stepper_items = [];
    $step_index = 0;
    foreach ($steps as $step_key => $step_config) {
      $is_active = ($step_key === $current_step);
      $is_completed = $this->isStepCompleted($form, $form_state, $step_key, $steps);
      $is_accessible = $this->isStepAccessible($form, $form_state, $step_key, $steps, $current_step);

      $classes = ['mel-wizard-stepper__item'];
      if ($is_active) {
        $classes[] = 'is-active';
      }
      if ($is_completed) {
        $classes[] = 'is-completed';
      }
      if (!$is_accessible) {
        $classes[] = 'is-disabled';
      }

      $stepper_items[] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => $classes,
          'data-mel-step' => $step_key,
          'data-mel-step-target' => $step_key,
          'role' => 'button',
          'tabindex' => $is_accessible ? '0' : '-1',
          'aria-current' => $is_active ? 'step' : 'false',
        ],
        'number' => [
          '#markup' => '<span class="mel-wizard-stepper__number">' . ($step_index + 1) . '</span>',
        ],
        'content' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-wizard-stepper__content']],
          'title' => [
            '#markup' => '<span class="mel-wizard-stepper__title">' . $step_config['title'] . '</span>',
          ],
          'description' => [
            '#markup' => '<span class="mel-wizard-stepper__description">' . $step_config['description'] . '</span>',
          ],
        ],
      ];
      $step_index++;
    }

    $form['wizard_stepper']['items'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-wizard-stepper__items']],
      'list' => $stepper_items,
    ];
  }

  /**
   * Check if a step is accessible (can be navigated to).
   */
  private function isStepAccessible(array $form, FormStateInterface $form_state, string $step_key, array $steps, string $current_step): bool {
    // Current and previous steps are always accessible.
    $step_keys = array_keys($steps);
    $current_index = array_search($current_step, $step_keys, TRUE);
    $target_index = array_search($step_key, $step_keys, TRUE);

    if ($target_index === FALSE || $current_index === FALSE) {
      return FALSE;
    }

    // Can go back freely, can only go forward if current step is completed.
    if ($target_index <= $current_index) {
      return TRUE;
    }

    // For forward navigation, check if current step is completed.
    return $this->isStepCompleted($form, $form_state, $current_step, $steps);
  }

  /**
   * Check if a step is completed (has required fields filled).
   */
  private function isStepCompleted(array $form, FormStateInterface $form_state, string $step_key, array $steps): bool {
    if (!isset($steps[$step_key])) {
      return FALSE;
    }

    $step_config = $steps[$step_key];
    $required_fields = $step_config['required_fields'] ?? [];

    // If no required fields, step is only complete if user has visited it.
    // For now, we'll only mark steps as completed if they have required fields AND those are filled.
    if (empty($required_fields)) {
      // Optional steps are not automatically completed.
      // They're only completed if the user has moved past them.
      $current_step = $form_state->get('wizard_current_step') ?? 'basics';
      $step_keys = array_keys($steps);
      $current_index = array_search($current_step, $step_keys, TRUE);
      $step_index = array_search($step_key, $step_keys, TRUE);

      // Only mark as completed if it's a previous step (user has moved past it).
      return ($step_index !== FALSE && $current_index !== FALSE && $step_index < $current_index);
    }

    // Check required fields.
    foreach ($required_fields as $field_name) {
      $value = $form_state->getValue([$field_name]);
      if (empty($value)) {
        // Also check the node entity if it exists.
        $node = $form_state->getFormObject()->getEntity();
        if ($node instanceof NodeInterface && !$node->isNew()) {
          if ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
            continue;
          }
        }
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Handle vendor and store fields (hidden, auto-populated).
   */
  private function handleVendorStore(array &$form, FormStateInterface $form_state, bool $is_new): void {
    if (isset($form['field_event_vendor'])) {
      $vendor_id = $this->getCurrentUserVendorId();
      if ($vendor_id && $is_new) {
        $form_state->setValue(['field_event_vendor', 0, 'target_id'], $vendor_id);
      }
      $form['field_event_vendor']['#access'] = FALSE;
    }

    if (isset($form['field_event_store'])) {
      $store_id = $this->getCurrentUserStoreId();
      if ($store_id && $is_new) {
        $form_state->setValue(['field_event_store', 0, 'target_id'], $store_id);
      }
      $form['field_event_store']['#access'] = FALSE;
    }
  }

  /**
   * STEP 1: Build Event Basics section.
   */
  private function buildStepBasics(array &$form, FormStateInterface $form_state): void {
    if (empty($form) || !is_array($form)) {
      return;
    }

    $form['event_basics'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-wizard-step', 'mel-wizard-step--basics'],
        'data-wizard-step' => 'basics',
      ],
      '#weight' => 1,
      '#access' => TRUE,
    ];

    $form['event_basics']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-wizard-step__content']],
      '#access' => TRUE,
      '#tree' => FALSE,
      '#process' => [],
    ];

    // Title (required) - move to step container and refine labels.
    if (isset($form['title']) && is_array($form['title'])) {
      $form['title']['#required'] = TRUE;
      // Update label.
      $form['title']['widget'][0]['value']['#title'] = t('Event title');
      // Update placeholder.
      if (!isset($form['title']['widget'][0]['value']['#attributes'])) {
        $form['title']['widget'][0]['value']['#attributes'] = [];
      }
      $form['title']['widget'][0]['value']['#attributes']['placeholder'] = t('Give your event a clear, friendly name');
      // Hide Drupal help text.
      if (isset($form['title']['#description'])) {
        $form['title']['#description'] = '';
      }
      // Move (not copy) to step container - this preserves form structure.
      $form['event_basics']['content']['title'] = &$form['title'];
      // Explicitly ensure field is accessible.
      $form['event_basics']['content']['title']['#access'] = TRUE;
    }

    // Short description (body field) - move to step container and refine labels.
    if (isset($form['body']) && is_array($form['body'])) {
      if (isset($form['body']['widget']) && is_array($form['body']['widget'])) {
        if (isset($form['body']['widget'][0]) && is_array($form['body']['widget'][0])) {
          // Update label.
          $form['body']['widget'][0]['#title'] = t('About this event');
          // Update placeholder.
          if (!isset($form['body']['widget'][0]['value']['#attributes'])) {
            $form['body']['widget'][0]['value']['#attributes'] = [];
          }
          $form['body']['widget'][0]['value']['#attributes']['placeholder'] = t('Tell people what to expect. Keep it friendly and clear.');
          // Hide text format help completely.
          if (!isset($form['body']['widget'][0]['#attributes']['class'])) {
            $form['body']['widget'][0]['#attributes']['class'] = [];
          }
          $form['body']['widget'][0]['#attributes']['class'][] = 'mel-hide-format-help';
          // Hide format selector and help text.
          if (isset($form['body']['widget'][0]['format'])) {
            $form['body']['widget'][0]['format']['#access'] = FALSE;
            if (!isset($form['body']['widget'][0]['format']['#attributes']['class'])) {
              $form['body']['widget'][0]['format']['#attributes']['class'] = [];
            }
            $form['body']['widget'][0]['format']['#attributes']['class'][] = 'mel-hidden-drupal';
          }
          // Remove description that mentions formats.
          if (isset($form['body']['widget'][0]['#description'])) {
            $form['body']['widget'][0]['#description'] = '';
          }
        }
      }
      // Hide "About text formats" link.
      if (isset($form['body']['#description'])) {
        $form['body']['#description'] = '';
      }
      // Move (not copy) to step container.
      $form['event_basics']['content']['body'] = &$form['body'];
      // Explicitly ensure field is accessible.
      $form['event_basics']['content']['body']['#access'] = TRUE;
    }

    // Event image (optional) - move to step container and refine labels.
    if (isset($form['field_event_image']) && is_array($form['field_event_image'])) {
      // Update label if needed.
      if (isset($form['field_event_image']['widget'])) {
        // Update description to friendly text.
        $form['field_event_image']['widget'][0]['#description'] = t('This is the main image people will see when browsing your event.');
        // Hide technical file upload help text (size, dimensions, MIME types).
        // These are typically in sub-elements, we'll hide them via CSS too.
        if (isset($form['field_event_image']['widget'][0]['#upload_validators'])) {
          // Keep validators but hide their messages.
        }
      }
      // Move (not copy) to step container.
      $form['event_basics']['content']['field_event_image'] = &$form['field_event_image'];
      // Explicitly ensure field is accessible.
      $form['event_basics']['content']['field_event_image']['#access'] = TRUE;
    }
  }

  /**
   * STEP 2: Build Schedule section.
   */
  private function buildStepSchedule(array &$form, FormStateInterface $form_state): void {
    if (empty($form) || !is_array($form)) {
      return;
    }

    $form['schedule'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-wizard-step', 'mel-wizard-step--schedule'],
        'data-wizard-step' => 'schedule',
      ],
      '#weight' => 2,
      '#access' => TRUE,
    ];

    $form['schedule']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-wizard-step__content']],
    ];

    // Start date + time (required) - refine labels and help text.
    if (isset($form['field_event_start']) && is_array($form['field_event_start'])) {
      $form['field_event_start']['#required'] = TRUE;
      // Update description to friendly text.
      if (isset($form['field_event_start']['widget'][0])) {
        $form['field_event_start']['widget'][0]['#description'] = t('Choose when your event starts.');
        // Hide technical date/time help.
        if (isset($form['field_event_start']['widget'][0]['value']['#description'])) {
          $form['field_event_start']['widget'][0]['value']['#description'] = '';
        }
      }
      // Use reference to preserve form structure (critical for datetime elements).
      $form['schedule']['content']['field_event_start'] = &$form['field_event_start'];
      $form['schedule']['content']['field_event_start']['#access'] = TRUE;
    }

    // End date + time (optional) - refine labels and help text.
    if (isset($form['field_event_end']) && is_array($form['field_event_end'])) {
      // Update description to friendly text.
      if (isset($form['field_event_end']['widget'][0])) {
        $form['field_event_end']['widget'][0]['#description'] = t('Add an end time if your event finishes later.');
        // Hide technical date/time help.
        if (isset($form['field_event_end']['widget'][0]['value']['#description'])) {
          $form['field_event_end']['widget'][0]['value']['#description'] = '';
        }
      }
      // Use reference to preserve form structure (critical for datetime elements).
      $form['schedule']['content']['field_event_end'] = &$form['field_event_end'];
      $form['schedule']['content']['field_event_end']['#access'] = TRUE;
    }
  }

  /**
   * STEP 3: Build Location section.
   */
  private function buildStepLocation(array &$form, FormStateInterface $form_state): void {
    if (empty($form) || !is_array($form)) {
      return;
    }

    $form['location'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-wizard-step', 'mel-wizard-step--location'],
        'data-wizard-step' => 'location',
      ],
      '#weight' => 3,
      '#access' => TRUE,
    ];

    $form['location']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-wizard-step__content']],
    ];

    // Venue name - refine labels.
    if (isset($form['field_venue_name']) && is_array($form['field_venue_name'])) {
      // Update description.
      if (isset($form['field_venue_name']['widget'][0]['value'])) {
        if (!isset($form['field_venue_name']['widget'][0]['value']['#attributes'])) {
          $form['field_venue_name']['widget'][0]['value']['#attributes'] = [];
        }
        $form['field_venue_name']['widget'][0]['value']['#attributes']['placeholder'] = t('e.g., Community Hall, Park Name');
      }
      // Use reference to preserve form structure.
      $form['location']['content']['field_venue_name'] = &$form['field_venue_name'];
      $form['location']['content']['field_venue_name']['#access'] = TRUE;
    }

    // Address field - refine labels and hide technical help.
    if (isset($form['field_location']) && is_array($form['field_location'])) {
      // Update main description.
      if (isset($form['field_location']['widget'][0])) {
        $form['field_location']['widget'][0]['#description'] = t('Where will people attend this event?');
      }
      // Hide country code selector.
      if (isset($form['field_location']['widget'][0]['address']['country_code']) && is_array($form['field_location']['widget'][0]['address']['country_code'])) {
        $form['field_location']['widget'][0]['address']['country_code']['#default_value'] = 'AU';
        $form['field_location']['widget'][0]['address']['country_code']['#access'] = FALSE;
      }
      // Hide raw address field labels - we'll show a single "Event location" heading.
      if (isset($form['field_location']['widget'][0]['address']) && is_array($form['field_location']['widget'][0]['address'])) {
        foreach ($form['field_location']['widget'][0]['address'] as $key => &$element) {
          if (is_array($element) && isset($element['#type'])) {
            $element['#disabled'] = FALSE;
            // Hide individual field labels - we have a main heading.
            if (isset($element['#title']) && $key !== 'country_code') {
              // Keep the field but hide its label via CSS or make it empty.
              $element['#title_display'] = 'invisible';
            }
          }
        }
        unset($element);
      }

      // Use reference to preserve form structure.
      $form['location']['content']['field_location'] = &$form['field_location'];
      $form['location']['content']['field_location']['#access'] = TRUE;
    }
  }

  /**
   * STEP 4: Build Attendance Type section.
   */
  private function buildStepAttendanceType(array &$form, FormStateInterface $form_state): void {
    if (empty($form) || !is_array($form)) {
      return;
    }

    $form['attendance_type'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-wizard-step', 'mel-wizard-step--attendance-type'],
        'data-wizard-step' => 'attendance_type',
      ],
      '#weight' => 4,
      '#access' => TRUE,
    ];

    $form['attendance_type']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-wizard-step__content']],
    ];

    // Event type field (required) - refine label.
    if (isset($form['field_event_type']) && is_array($form['field_event_type'])) {
      // Update label.
      $form['field_event_type']['#title'] = t('How will people attend?');
      // Update description.
      $form['field_event_type']['#description'] = '';

      // Remove any existing states.
      if (isset($form['field_event_type']['#states'])) {
        unset($form['field_event_type']['#states']);
      }
      if (isset($form['field_event_type']['widget']) && is_array($form['field_event_type']['widget'])) {
        if (isset($form['field_event_type']['widget']['#states'])) {
          unset($form['field_event_type']['widget']['#states']);
        }
        if (isset($form['field_event_type']['widget'][0]) && is_array($form['field_event_type']['widget'][0])) {
          if (isset($form['field_event_type']['widget'][0]['#states'])) {
            unset($form['field_event_type']['widget'][0]['#states']);
          }
        }
      }

      $form['field_event_type']['#required'] = TRUE;
      $form['field_event_type']['#access'] = TRUE;

      // Set default for new events.
      $node = $form_state->getFormObject()->getEntity();
      if ($node && $node->isNew()) {
        $currentValue = $form_state->getValue(['field_event_type', 0, 'value']);
        if (empty($currentValue)) {
          if (isset($form['field_event_type']['widget'][0]['value'])) {
            if (empty($form['field_event_type']['widget'][0]['value']['#default_value'])) {
              $form['field_event_type']['widget'][0]['value']['#default_value'] = 'rsvp';
            }
          }
        }
      }

      // Use reference to preserve form structure.
      $form['attendance_type']['content']['field_event_type'] = &$form['field_event_type'];
      $form['attendance_type']['content']['field_event_type']['#access'] = TRUE;
    }
  }

  /**
   * STEP 5: Build Attendance Details section (conditional).
   */
  private function buildStepAttendanceDetails(array &$form, FormStateInterface $form_state): void {
    if (empty($form) || !is_array($form)) {
      return;
    }

    $form['attendance_details'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-wizard-step', 'mel-wizard-step--attendance-details'],
        'data-wizard-step' => 'attendance_details',
      ],
      '#weight' => 5,
      '#access' => TRUE,
    ];

    $form['attendance_details']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-wizard-step__content']],
    ];

    $selector = ':input[name="field_event_type[0][value]"]';

    // RSVP fields container.
    if (isset($form['field_capacity']) || isset($form['field_waitlist_capacity'])) {
      $form['attendance_details']['content']['rsvp_fields'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-attendance-rsvp'],
          'data-attendance-type' => 'rsvp',
        ],
        '#states' => [
          'visible' => [
            [$selector => ['value' => 'rsvp']],
          ],
        ],
      ];

      // Capacity - refine label.
      if (isset($form['field_capacity']) && is_array($form['field_capacity'])) {
        // Update label and description.
        $form['field_capacity']['#title'] = t('How many people can attend?');
        if (isset($form['field_capacity']['widget'][0]['value'])) {
          $form['field_capacity']['widget'][0]['value']['#description'] = t('Leave empty if there\'s no limit.');
        }
        if (isset($form['field_capacity']['#states'])) {
          unset($form['field_capacity']['#states']);
        }
        // Update label and description.
        $form['field_capacity']['#title'] = t('How many people can attend?');
        if (isset($form['field_capacity']['widget'][0]['value'])) {
          $form['field_capacity']['widget'][0]['value']['#description'] = t('Leave empty if there\'s no limit.');
        }
        $form['field_capacity']['#required'] = FALSE;
        $form['attendance_details']['content']['rsvp_fields']['field_capacity'] = $form['field_capacity'];
        unset($form['field_capacity']);
      }

      // Waitlist capacity.
      if (isset($form['field_waitlist_capacity']) && is_array($form['field_waitlist_capacity'])) {
        if (isset($form['field_waitlist_capacity']['#states'])) {
          unset($form['field_waitlist_capacity']['#states']);
        }
        $form['field_waitlist_capacity']['#required'] = FALSE;
        $form['attendance_details']['content']['rsvp_fields']['field_waitlist_capacity'] = $form['field_waitlist_capacity'];
        unset($form['field_waitlist_capacity']);
      }

      // Donation toggle for RSVP.
      $this->addDonationToggle($form['attendance_details']['content']['rsvp_fields'], $form_state, 'rsvp');
    }

    // Paid fields container.
    if (isset($form['field_ticket_types'])) {
      $form['attendance_details']['content']['paid_fields'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-attendance-paid'],
          'data-attendance-type' => 'paid',
        ],
        '#states' => [
          'visible' => [
            'or' => [
              [$selector => ['value' => 'paid']],
              [$selector => ['value' => 'both']],
            ],
          ],
        ],
      ];

      // Ticket types (paragraphs).
      if (isset($form['field_ticket_types']) && is_array($form['field_ticket_types'])) {
        if (isset($form['field_ticket_types']['#states'])) {
          unset($form['field_ticket_types']['#states']);
        }
        if (isset($form['field_ticket_types']['widget']) && is_array($form['field_ticket_types']['widget'])) {
          if (isset($form['field_ticket_types']['widget']['#states'])) {
            unset($form['field_ticket_types']['widget']['#states']);
          }
        }
        $form['field_ticket_types']['#access'] = TRUE;
        if (isset($form['field_ticket_types']['widget'])) {
          $form['field_ticket_types']['widget']['#access'] = TRUE;
        }
        $form['field_ticket_types']['#required'] = FALSE;
        $form['attendance_details']['content']['paid_fields']['field_ticket_types'] = $form['field_ticket_types'];
        unset($form['field_ticket_types']);
      }
    }

    // External fields container.
    if (isset($form['field_external_url'])) {
      $form['attendance_details']['content']['external_fields'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-attendance-external'],
          'data-attendance-type' => 'external',
        ],
        '#states' => [
          'visible' => [
            [$selector => ['value' => 'external']],
          ],
        ],
      ];

      if (isset($form['field_external_url']) && is_array($form['field_external_url'])) {
        if (isset($form['field_external_url']['#states'])) {
          unset($form['field_external_url']['#states']);
        }
        $form['attendance_details']['content']['external_fields']['field_external_url'] = $form['field_external_url'];
        unset($form['field_external_url']);
      }

      // Donation toggle for External.
      $this->addDonationToggle($form['attendance_details']['content']['external_fields'], $form_state, 'external');
    }
  }

  /**
   * Add donation toggle for RSVP and External event types.
   */
  private function addDonationToggle(array &$container, FormStateInterface $form_state, string $event_type): void {
    // Check if donations module is enabled and configured.
    if (!\Drupal::moduleHandler()->moduleExists('myeventlane_donations')) {
      return;
    }

    $donationConfig = \Drupal::config('myeventlane_donations.settings');
    $donationEnabled = $donationConfig->get('enable_rsvp_donations') ?? FALSE;

    if (!$donationEnabled) {
      return;
    }

    // Check if vendor has Stripe Connect if required.
    $requireStripeConnected = $donationConfig->get('require_stripe_connected_for_attendee_donations') ?? TRUE;
    $showDonation = TRUE;

    if ($requireStripeConnected) {
      $node = $form_state->getFormObject()->getEntity();
      if ($node instanceof NodeInterface && !$node->isNew()) {
        $vendor = $node->get('field_event_vendor')->entity;
        if ($vendor && $vendor->hasField('field_stripe_account_id')) {
          $stripeAccountId = $vendor->get('field_stripe_account_id')->value;
          $showDonation = !empty($stripeAccountId);
        }
        else {
          $showDonation = FALSE;
        }
      }
    }

    if ($showDonation) {
      $container['donation_toggle'] = [
        '#type' => 'checkbox',
        '#title' => t('Allow donations to MyEventLane'),
        '#description' => t('Support MyEventLane with a small optional contribution.'),
        '#default_value' => FALSE,
        '#weight' => 100,
        '#attributes' => ['class' => ['mel-donation-toggle']],
      ];
    }
  }

  /**
   * STEP 6: Build Extras section.
   */
  private function buildStepExtras(array &$form, FormStateInterface $form_state): void {
    if (empty($form) || !is_array($form)) {
      return;
    }

    $form['extras'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-wizard-step', 'mel-wizard-step--extras'],
        'data-wizard-step' => 'extras',
      ],
      '#weight' => 6,
      '#access' => TRUE,
    ];

    $form['extras']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-wizard-step__content']],
    ];

    // Category - refine labels.
    if (isset($form['field_category']) && is_array($form['field_category'])) {
      // Helper text will be added via section heading.
      $form['field_category']['#access'] = TRUE;
      if (isset($form['field_category']['#states'])) {
        unset($form['field_category']['#states']);
      }
      if (isset($form['field_category']['widget']) && is_array($form['field_category']['widget'])) {
        if (isset($form['field_category']['widget']['#states'])) {
          unset($form['field_category']['widget']['#states']);
        }
        $form['field_category']['widget']['#access'] = TRUE;
      }
      $form['extras']['content']['field_category'] = $form['field_category'];
      unset($form['field_category']);
    }

    // Accessibility - refine labels.
    if (isset($form['field_accessibility']) && is_array($form['field_accessibility'])) {
      // Update description.
      if (isset($form['field_accessibility']['widget'][0])) {
        $form['field_accessibility']['widget'][0]['#description'] = t('Let people know how accessible your event is.');
      }
      $form['field_accessibility']['#access'] = TRUE;
      if (isset($form['field_accessibility']['#states'])) {
        unset($form['field_accessibility']['#states']);
      }
      if (isset($form['field_accessibility']['widget']) && is_array($form['field_accessibility']['widget'])) {
        if (isset($form['field_accessibility']['widget']['#states'])) {
          unset($form['field_accessibility']['widget']['#states']);
        }
        $form['field_accessibility']['widget']['#access'] = TRUE;
      }
      $form['extras']['content']['field_accessibility'] = $form['field_accessibility'];
      unset($form['field_accessibility']);
    }

    // Attendee questions - refine labels.
    if (isset($form['field_attendee_questions']) && is_array($form['field_attendee_questions'])) {
      // Update description.
      if (isset($form['field_attendee_questions']['widget'])) {
        $form['field_attendee_questions']['#description'] = t('Optional questions you\'d like attendees to answer.');
      }
      $form['field_attendee_questions']['#required'] = FALSE;
      $form['extras']['content']['field_attendee_questions'] = $form['field_attendee_questions'];
      unset($form['field_attendee_questions']);
    }
  }

  /**
   * STEP 7: Build Review & Publish section.
   */
  private function buildStepReview(array &$form, FormStateInterface $form_state, NodeInterface $node): void {
    if (empty($form) || !is_array($form)) {
      return;
    }

    $form['review'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-wizard-step', 'mel-wizard-step--review'],
        'data-wizard-step' => 'review',
      ],
      '#weight' => 7,
      '#access' => TRUE,
    ];

    $form['review']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-wizard-step__content', 'mel-review-summary']],
    ];

    // Build read-only summary styled like public event.
    $form['review']['content']['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-review-summary__content']],
      '#markup' => $this->buildReviewSummary($form_state, $node),
    ];
  }

  /**
   * Build review summary content.
   */
  private function buildReviewSummary(FormStateInterface $form_state, NodeInterface $node): string {
    $summary = '<div class="mel-review-summary">';

    // Get form values.
    $title = $form_state->getValue('title') ?? $node->getTitle();
    $body = $form_state->getValue('body') ?? '';
    $start = $form_state->getValue(['field_event_start', 0, 'value']) ?? '';
    $end = $form_state->getValue(['field_event_end', 0, 'value']) ?? '';
    $event_type = $form_state->getValue(['field_event_type', 0, 'value']) ?? '';

    $summary .= '<h3>' . t('Event Summary') . '</h3>';
    $summary .= '<div class="mel-review-summary__section">';
    $summary .= '<h4>' . t('Title') . '</h4>';
    $summary .= '<p>' . htmlspecialchars($title) . '</p>';
    $summary .= '</div>';

    if (!empty($body)) {
      $summary .= '<div class="mel-review-summary__section">';
      $summary .= '<h4>' . t('Description') . '</h4>';
      $summary .= '<p>' . htmlspecialchars($body) . '</p>';
      $summary .= '</div>';
    }

    if (!empty($start)) {
      $summary .= '<div class="mel-review-summary__section">';
      $summary .= '<h4>' . t('Start Date') . '</h4>';
      $summary .= '<p>' . htmlspecialchars($start) . '</p>';
      $summary .= '</div>';
    }

    if (!empty($end)) {
      $summary .= '<div class="mel-review-summary__section">';
      $summary .= '<h4>' . t('End Date') . '</h4>';
      $summary .= '<p>' . htmlspecialchars($end) . '</p>';
      $summary .= '</div>';
    }

    if (!empty($event_type)) {
      $summary .= '<div class="mel-review-summary__section">';
      $summary .= '<h4>' . t('Attendance Type') . '</h4>';
      $summary .= '<p>' . htmlspecialchars($event_type) . '</p>';
      $summary .= '</div>';
    }

    $summary .= '</div>';

    return $summary;
  }

  /**
   * Build sticky action bar with Back, Next/Publish, Save Draft buttons.
   */
  private function buildStickyActionBar(array &$form, FormStateInterface $form_state, NodeInterface $node, bool $is_new): void {
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
      $form['actions']['#attributes']['class'][] = 'mel-wizard-actions';
      $form['actions']['#attributes']['class'][] = 'mel-sticky-action-bar';

      $current_step = $form_state->get('wizard_current_step') ?? 'basics';
      $steps = $form_state->get('wizard_steps') ?? [];
      $step_keys = array_keys($steps);
      $current_index = array_search($current_step, $step_keys, TRUE);

      // Back button (left).
      if ($current_index > 0) {
        $form['actions']['wizard_back'] = [
          '#type' => 'button',
          '#value' => t('Back'),
          '#weight' => -10,
          '#attributes' => [
            'class' => ['mel-btn-secondary', 'mel-btn-back'],
            'data-wizard-action' => 'back',
          ],
          '#limit_validation_errors' => [],
        ];
      }

      // Save Draft button (left).
      $form['actions']['save_draft'] = [
        '#type' => 'submit',
        '#value' => t('Save draft'),
        '#weight' => -5,
        '#submit' => ['_myeventlane_event_save_draft'],
        '#attributes' => ['class' => ['mel-btn-secondary', 'mel-btn-draft']],
        '#limit_validation_errors' => [],
      ];

      // Hide Delete button for vendors (only show for admins).
      if (isset($form['actions']['delete'])) {
        if (!$this->currentUser->hasPermission('administer nodes')) {
          $form['actions']['delete']['#access'] = FALSE;
        }
        // Also hide it from the sidebar if it appears there.
        $form['actions']['delete']['#printed'] = TRUE;
      }

      // Next or Publish button (right).
      if (isset($form['actions']['submit']) && is_array($form['actions']['submit'])) {
        if ($current_step === 'review') {
          $form['actions']['submit']['#value'] = $is_new ? t('Publish event') : t('Save changes');
        }
        else {
          $form['actions']['submit']['#value'] = t('Next');
        }
        if (!isset($form['actions']['submit']['#attributes'])) {
          $form['actions']['submit']['#attributes'] = [];
        }
        if (!isset($form['actions']['submit']['#attributes']['class'])) {
          $form['actions']['submit']['#attributes']['class'] = [];
        }
        $form['actions']['submit']['#attributes']['class'][] = 'mel-btn-primary';
        $form['actions']['submit']['#attributes']['data-wizard-action'] = 'next';
        $form['actions']['submit']['#weight'] = 10;
      }

      // Preview button (for existing events).
      if ($node && !$node->isNew()) {
        $form['actions']['preview'] = [
          '#type' => 'link',
          '#title' => t('Preview'),
          '#url' => $node->toUrl('canonical'),
          '#attributes' => [
            'class' => ['mel-btn-secondary', 'mel-btn-preview'],
            'target' => '_blank',
          ],
          '#weight' => 15,
        ];
      }
    }
  }

  /**
   * Attach required libraries.
   */
  private function attachLibraries(array &$form): void {
    if (!in_array('core/drupal.form', $form['#attached']['library'] ?? [])) {
      $form['#attached']['library'][] = 'core/drupal.form';
    }
    if (!in_array('core/drupal.states', $form['#attached']['library'] ?? [])) {
      $form['#attached']['library'][] = 'core/drupal.states';
    }

    if (\Drupal::moduleHandler()->moduleExists('conditional_fields')) {
      if (!in_array('conditional_fields/conditional_fields', $form['#attached']['library'] ?? [])) {
        $form['#attached']['library'][] = 'conditional_fields/conditional_fields';
      }
    }

    if (!in_array('myeventlane_location/address_autocomplete', $form['#attached']['library'] ?? [])) {
      $form['#attached']['library'][] = 'myeventlane_location/address_autocomplete';
    }

    // Attach wizard libraries.
    if (!in_array('myeventlane_event/event-wizard', $form['#attached']['library'] ?? [])) {
      $form['#attached']['library'][] = 'myeventlane_event/event-wizard';
    }
  }

  /**
   * Get current user's vendor ID.
   */
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

  /**
   * Get current user's store ID.
   */
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

  /**
   * Wrap wizard components (stepper + steps) in a single container.
   */
  private function wrapWizardComponents(array &$form, FormStateInterface $form_state): void {
    // Skip if already wrapped (form rebuild scenario).
    if (isset($form['mel_event_wizard'])) {
      return;
    }

    // Create the main wizard wrapper container.
    $form['mel_event_wizard'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-event-wizard'],
      ],
      '#weight' => 0,
    ];

    // Move stepper into wrapper.
    if (isset($form['wizard_stepper'])) {
      $form['mel_event_wizard']['wizard_stepper'] = $form['wizard_stepper'];
      unset($form['wizard_stepper']);
    }

    // Create steps container inside wrapper.
    $form['mel_event_wizard']['steps'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-wizard-steps-container'],
      ],
      '#weight' => 1,
    ];

    // Move all step containers into the steps container.
    $step_keys = ['event_basics', 'schedule', 'location', 'attendance_type', 'attendance_details', 'extras', 'review'];
    foreach ($step_keys as $step_key) {
      if (isset($form[$step_key])) {
        $form['mel_event_wizard']['steps'][$step_key] = $form[$step_key];
        unset($form[$step_key]);
      }
    }
  }

  /**
   * Deep copy a form element to preserve all properties.
   */
  private function copyFormElement(array $element): array {
    // Use serialize/unserialize for deep copy.
    return unserialize(serialize($element));
  }

}
