<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Form;

use Drupal\myeventlane_event\Service\EventProductManager;
use Drupal\node\NodeInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Vendor-context Event form wizard.
 *
 * Principles:
 * - PHP owns structure and visibility (one step visible via CSS).
 * - All step panels remain in form for Form API value extraction.
 * - JS only triggers step changes + focus after AJAX.
 * - Stepper is LEFT and uses vendor theme-friendly classes.
 */
final class EventFormAlter {

  private const STATE_KEY = 'mel_wizard_step';

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly RouteMatchInterface $routeMatch,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   *
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('current_user'),
      $container->get('current_route_match'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   *
   */
  public function alterForm(array &$form, FormStateInterface $form_state, string $form_id): void {
    if (!$this->isEventForm($form, $form_state, $form_id)) {
      return;
    }

    // CRITICAL: Always ensure buttons exist for event forms, regardless of context.
    // This ensures edit forms always have save buttons.
    $this->ensureActionButtons($form, $form_state);

    // Only apply wizard in vendor context.
    if (!$this->isVendorContext()) {
      return;
    }

    $form['#attached']['library'][] = 'myeventlane_event/event_wizard';
    $form['#attached']['library'][] = 'myeventlane_event/hide_venue_name';

    // Attach address autocomplete library for location fields.
    $form['#attached']['library'][] = 'myeventlane_location/address_autocomplete';

    // CRITICAL: Hide conflicting navigation from vendor module and Drupal.
    $this->hideConflictingNavigation($form);

    // Vendor UX: hide admin-only fields and help noise.
    $this->hideAdminFields($form);
    $this->hideCoordinateFields($form);
    $this->hideUnusedAddressFields($form);
    $this->suppressTextFormatHelp($form);

    // Build steps by recursively finding ALL fields in the form.
    $steps = $this->buildStepsFromForm($form);
    if (count($steps) < 2) {
      // Wizard not applicable (not enough steps), but ensure buttons are present.
      $this->ensureActionButtons($form, $form_state);
      return;
    }

    $current = $this->getCurrentStep($form_state, $steps);

    // Wizard root container. Single AJAX wrapper id.
    $form['mel_wizard'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'mel-event-wizard-wrapper',
        'class' => ['mel-wizard', 'mel-wizard--event', 'mel-event-form', 'mel-event-form--wizard'],
        'data-mel-current-step' => $current,
      ],
      '#weight' => -1000,
    ];

    // Hidden values used by JS to request step changes.
    $form['mel_wizard']['wizard_current_step'] = [
      '#type' => 'hidden',
      '#value' => $current,
      '#attributes' => ['class' => ['js-mel-wizard-current-step']],
    ];
    $form['mel_wizard']['wizard_target_step'] = [
      '#type' => 'hidden',
      '#default_value' => '',
      '#attributes' => ['class' => ['js-mel-wizard-target-step']],
    ];

    // Layout: LEFT stepper + RIGHT content.
    $form['mel_wizard']['layout'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-wizard__layout', 'mel-event-form__wizard-layout']],
    ];

    $form['mel_wizard']['layout']['nav'] = $this->buildLeftStepper($steps, $current);

    $form['mel_wizard']['layout']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-wizard__content', 'mel-event-form__wizard-content']],
    ];

    // Move ALL fields into step panels. PHP controls visibility via CSS classes.
    foreach ($steps as $step_id => $step) {
      $panel_key = 'step_' . $step_id;
      $is_active = ($step_id === $current);

      $form['mel_wizard']['layout']['content'][$panel_key] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => array_filter([
            'mel-event-form__panel',
            'mel-wizard-step',
            $is_active ? 'is-active' : 'is-hidden',
          ]),
          'data-step' => $step_id,
        ],
      ];

      // Card wrapper for step content.
      $form['mel_wizard']['layout']['content'][$panel_key]['card'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-card', 'mel-card--wizard-section']],
      ];

      // Card header with title and help text.
      $form['mel_wizard']['layout']['content'][$panel_key]['card']['header'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-card__header']],
      ];

      $form['mel_wizard']['layout']['content'][$panel_key]['card']['header']['title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $step['label'],
        '#attributes' => [
          'class' => ['mel-event-form__section-title', 'mel-wizard-step__title', 'mel-card__title'],
        ],
      ];

      // Help text for each step (Australian English, gender-neutral).
      $help_text = $this->getStepHelpText($step_id);
      if ($help_text) {
        $form['mel_wizard']['layout']['content'][$panel_key]['card']['header']['help'] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $help_text,
          '#attributes' => [
            'class' => ['mel-card__help', 'mel-wizard-step__description'],
          ],
        ];
      }

      // Card body with section container.
      $form['mel_wizard']['layout']['content'][$panel_key]['card']['body'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-card__body']],
      ];

      // Section container (vendor theme uses this card look).
      $form['mel_wizard']['layout']['content'][$panel_key]['card']['body']['section'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-form__section']],
      ];

      // Move fields - handle both top-level and nested in sections.
      foreach ($step['fields'] as $field_name) {
        $this->moveFieldToWizard($form, $form['mel_wizard']['layout']['content'][$panel_key]['card']['body']['section'], $field_name);
      }
    }

    // Hide ALL remaining top-level form elements except system fields.
    $this->hideRemainingFormElements($form);

    // Rewrite actions into wizard nav (AJAX + scoped validation).
    $this->rewriteActionsAsWizardNav($form, $form_state, $steps, $current);

    // CRITICAL: Remove vendor module's horizontal tabs AFTER all wizard setup.
    $this->removeVendorTabs($form);

    // CRITICAL: Always ensure buttons exist and are visible after all processing.
    $this->ensureActionButtons($form, $form_state);

    // Force buttons to be visible - override any #access = FALSE.
    if (isset($form['actions']['submit'])) {
      $form['actions']['submit']['#access'] = TRUE;
    }
    if (isset($form['actions']['save_draft'])) {
      $form['actions']['save_draft']['#access'] = TRUE;
    }
  }

  /**
   *
   */
  private function isEventForm(array &$form, FormStateInterface $form_state, string $form_id): bool {
    if ($form_id === 'node_event_form' || str_starts_with($form_id, 'node_event_')) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   *
   */
  private function isVendorContext(): bool {
    $route_name = (string) $this->routeMatch->getRouteName();

    // Check for vendor routes.
    if (str_contains($route_name, 'myeventlane_vendor') || str_contains($route_name, 'vendor')) {
      return TRUE;
    }

    // Check for node add/edit forms for events (these are vendor routes when on vendor domain).
    if (in_array($route_name, ['entity.node.add_form', 'entity.node.edit_form'], TRUE)) {
      // Check if it's an event node.
      try {
        // For add forms, check node_type parameter.
        $node_type = $this->routeMatch->getParameter('node_type');
        if ($node_type) {
          $bundle = is_string($node_type) ? $node_type : $node_type->id();
          if ($bundle === 'event') {
            return TRUE;
          }
        }

        // For edit forms, check node parameter.
        $node = $this->routeMatch->getParameter('node');
        if ($node && method_exists($node, 'bundle') && $node->bundle() === 'event') {
          return TRUE;
        }
      }
      catch (\Exception $e) {
        // If parameter access fails, fall through to domain check.
      }
    }

    // Check if we're on vendor domain (fallback).
    try {
      $domain_detector = \Drupal::service('myeventlane_core.domain_detector');
      if ($domain_detector && method_exists($domain_detector, 'isVendorDomain')) {
        return $domain_detector->isVendorDomain();
      }
    }
    catch (\Exception $e) {
      // Service not available, continue.
    }

    return FALSE;
  }

  /**
   * Hide conflicting navigation from vendor module and Drupal defaults.
   */
  private function hideConflictingNavigation(array &$form): void {
    if (isset($form['group_primary'])) {
      $form['group_primary']['#access'] = FALSE;
    }

    foreach (['group_secondary', 'group_advanced', 'group_content'] as $key) {
      if (isset($form[$key]) && isset($form[$key]['#type']) && $form[$key]['#type'] === 'vertical_tabs') {
        $form[$key]['#access'] = FALSE;
      }
    }
  }

  /**
   * Remove vendor module's horizontal tabs and clean up tab pane attributes.
   */
  private function removeVendorTabs(array &$form): void {
    if (isset($form['simple_tabs_nav'])) {
      unset($form['simple_tabs_nav']);
    }

    $sections = ['event_basics', 'location', 'date_time', 'booking_config', 'visibility'];
    foreach ($sections as $section_key) {
      if (isset($form[$section_key]) && is_array($form[$section_key])) {
        if (isset($form[$section_key]['#attributes']['class'])) {
          $classes = $form[$section_key]['#attributes']['class'];
          if (is_array($classes)) {
            $classes = array_filter($classes, function ($class) {
              return !in_array($class, ['mel-tab-pane', 'mel-simple-tab-pane', 'is-active'], TRUE);
            });
            $form[$section_key]['#attributes']['class'] = array_values($classes);
          }
        }
        if (isset($form[$section_key]['#attributes']['data-tab-pane'])) {
          unset($form[$section_key]['#attributes']['data-tab-pane']);
        }
        if (isset($form[$section_key]['#attributes']['data-simple-tab-pane'])) {
          unset($form[$section_key]['#attributes']['data-simple-tab-pane']);
        }
        if (isset($form[$section_key]['#attributes']['role']) && $form[$section_key]['#attributes']['role'] === 'tabpanel') {
          unset($form[$section_key]['#attributes']['role']);
        }
      }
    }

    $this->removeVendorTabsRecursive($form);
  }

  /**
   * Recursively remove vendor tab elements from form structure.
   */
  private function removeVendorTabsRecursive(array &$element): void {
    foreach ($element as $key => &$value) {
      $key_str = (string) $key;
      if (str_starts_with($key_str, '#')) {
        continue;
      }

      if ($key === 'simple_tabs_nav' || $key_str === 'simple_tabs_nav') {
        unset($element[$key]);
        continue;
      }

      if (is_array($value)) {
        if (isset($value['#attributes']['class'])) {
          $classes = $value['#attributes']['class'];
          if (is_array($classes)) {
            $classes = array_filter($classes, function ($class) {
              return !in_array($class, ['mel-simple-tabs', 'mel-simple-tabs__buttons', 'mel-simple-tab', 'mel-tab-pane', 'mel-simple-tab-pane'], TRUE);
            });
            $value['#attributes']['class'] = array_values($classes);
          }
        }
        foreach (['data-simple-tab', 'data-tab-pane', 'data-simple-tab-pane'] as $attr) {
          if (isset($value['#attributes'][$attr])) {
            unset($value['#attributes'][$attr]);
          }
        }
        $this->removeVendorTabsRecursive($value);
      }
    }
  }

  /**
   *
   */
  private function hideAdminFields(array &$form): void {
    $admin_fields = ['field_event_vendor', 'field_event_store', 'field_venue_name'];
    foreach ($admin_fields as $field_name) {
      if (isset($form[$field_name])) {
        $form[$field_name]['#access'] = FALSE;
      }

      // Also hide in nested sections.
      $sections = ['event_basics', 'location', 'date_time', 'booking_config', 'visibility'];
      foreach ($sections as $section_key) {
        if (isset($form[$section_key][$field_name])) {
          $form[$section_key][$field_name]['#access'] = FALSE;
        }
      }

      // Also hide in wizard if fields were moved there.
      if (isset($form['mel_wizard']['layout']['content'])) {
        foreach ($form['mel_wizard']['layout']['content'] as $step_key => &$step_content) {
          if (is_array($step_content) && isset($step_content['card']['body']['section'][$field_name])) {
            $step_content['card']['body']['section'][$field_name]['#access'] = FALSE;
          }
        }
      }
    }
  }

  /**
   * Hide unused address subfields: organization, given_name, family_name.
   */
  private function hideUnusedAddressFields(array &$form): void {
    $this->hideUnusedAddressFieldsRecursive($form);
  }

  /**
   * Recursively hide unused address subfields in form structure.
   */
  private function hideUnusedAddressFieldsRecursive(array &$element): void {
    foreach ($element as $key => &$value) {
      if (str_starts_with((string) $key, '#')) {
        continue;
      }

      if (is_array($value)) {
        // Check if this is an address widget structure.
        if (isset($value['widget']) && is_array($value['widget'])) {
          foreach ($value['widget'] as $delta => &$widget_element) {
            if (is_numeric($delta) && is_array($widget_element) && isset($widget_element['address'])) {
              $address_widget = &$widget_element['address'];

              // Hide organization field.
              if (isset($address_widget['organization'])) {
                $address_widget['organization']['#access'] = FALSE;
                $address_widget['organization']['#required'] = FALSE;
              }

              // Hide given_name field.
              if (isset($address_widget['given_name'])) {
                $address_widget['given_name']['#access'] = FALSE;
                $address_widget['given_name']['#required'] = FALSE;
              }

              // Hide family_name field.
              if (isset($address_widget['family_name'])) {
                $address_widget['family_name']['#access'] = FALSE;
                $address_widget['family_name']['#required'] = FALSE;
              }
            }
          }
        }

        // Recursively process nested arrays.
        $this->hideUnusedAddressFieldsRecursive($value);
      }
    }
  }

  /**
   * Hide coordinate fields - they're auto-populated by JavaScript.
   */
  private function hideCoordinateFields(array &$form): void {
    $coordinate_fields = [
      'field_location_latitude',
      'field_location_longitude',
      'field_event_lat',
      'field_event_lng',
    ];

    foreach ($coordinate_fields as $field_name) {
      if (isset($form[$field_name])) {
        $form[$field_name]['#access'] = FALSE;
        $form[$field_name]['#required'] = FALSE;
        $form[$field_name]['#validated'] = TRUE;
        if (!isset($form[$field_name]['#element_validate'])) {
          $form[$field_name]['#element_validate'] = [];
        }
        $form[$field_name]['#element_validate'][] = [get_class($this), 'validateCoordinateField'];

        if (isset($form[$field_name]['widget']) && is_array($form[$field_name]['widget'])) {
          foreach ($form[$field_name]['widget'] as $delta => &$widget_element) {
            if (is_numeric($delta) && is_array($widget_element)) {
              $widget_element['#access'] = FALSE;
              $widget_element['#required'] = FALSE;
              $widget_element['#validated'] = TRUE;

              if (isset($widget_element['value'])) {
                $widget_element['value']['#access'] = FALSE;
                $widget_element['value']['#required'] = FALSE;
                $widget_element['value']['#validated'] = TRUE;
                if (!isset($widget_element['value']['#element_validate'])) {
                  $widget_element['value']['#element_validate'] = [];
                }
                $widget_element['value']['#element_validate'][] = [get_class($this), 'validateCoordinateField'];
              }
            }
          }
        }
      }

      $sections = ['event_basics', 'location', 'date_time', 'booking_config', 'visibility'];
      foreach ($sections as $section_key) {
        if (isset($form[$section_key][$field_name])) {
          $form[$section_key][$field_name]['#access'] = FALSE;
          $form[$section_key][$field_name]['#required'] = FALSE;
          $form[$section_key][$field_name]['#validated'] = TRUE;
          if (!isset($form[$section_key][$field_name]['#element_validate'])) {
            $form[$section_key][$field_name]['#element_validate'] = [];
          }
          $form[$section_key][$field_name]['#element_validate'][] = [get_class($this), 'validateCoordinateField'];
        }
      }

      if (isset($form['mel_wizard']['layout']['content'])) {
        foreach ($form['mel_wizard']['layout']['content'] as $step_key => &$step_content) {
          if (is_array($step_content) && isset($step_content['card']['body']['section'][$field_name])) {
            $step_content['card']['body']['section'][$field_name]['#access'] = FALSE;
            $step_content['card']['body']['section'][$field_name]['#required'] = FALSE;
            $step_content['card']['body']['section'][$field_name]['#validated'] = TRUE;
            if (!isset($step_content['card']['body']['section'][$field_name]['#element_validate'])) {
              $step_content['card']['body']['section'][$field_name]['#element_validate'] = [];
            }
            $step_content['card']['body']['section'][$field_name]['#element_validate'][] = [get_class($this), 'validateCoordinateField'];
          }
        }
      }
    }
  }

  /**
   *
   */
  private function suppressTextFormatHelp(array &$form): void {
    foreach ($form as $key => &$element) {
      if (is_array($element) && isset($element['#type']) && $element['#type'] === 'text_format') {
        if (isset($element['format']['help'])) {
          $element['format']['help']['#access'] = FALSE;
        }
        if (isset($element['format']['guidelines'])) {
          $element['format']['guidelines']['#access'] = FALSE;
        }
      }
    }
  }

  /**
   * Build steps by recursively finding fields in form structure.
   *
   * @return array<string, array{label: string, fields: string[]}>
   */
  private function buildStepsFromForm(array $form): array {
    $candidates = [
      'basics' => [
        'label' => 'Basics',
        'fields' => [
          'title',
          'body',
          'field_event_image',
          'field_event_type',
          'field_category',
        ],
      ],
      'schedule' => [
        'label' => 'Schedule',
        'fields' => [
          'field_event_date',
          'field_event_start',
          'field_event_end_date',
          'field_event_end',
          'field_event_recurring',
        ],
      ],
      'location' => [
        'label' => 'Location',
        'fields' => [
          'field_event_location_mode',
          'field_event_location',
          'field_location',
          // Note: field_venue_name is hidden for vendors via hideAdminFields()
          'field_event_online_url',
        ],
      ],
      'tickets' => [
        'label' => 'Tickets',
        'fields' => [
          'field_event_mode',
          'field_event_ticket_types',
          'field_ticket_types',
          'field_event_capacity',
          'field_capacity',
          'field_waitlist_capacity',
          'field_event_external_url',
          'field_external_url',
          'field_product_target',
          'field_collect_per_ticket',
        ],
      ],
      'design' => [
        'label' => 'Design',
        'fields' => [
          'field_event_theme',
          'field_event_primary_color',
          'field_event_secondary_color',
          'field_accessibility',
          'field_tags',
        ],
      ],
      'questions' => [
        'label' => 'Questions',
        'fields' => [
          'field_event_questions',
          'field_attendee_questions',
        ],
      ],
      'review' => [
        'label' => 'Review',
        'fields' => [],
      ],
    ];

    $steps = [];
    foreach ($candidates as $id => $def) {
      $existing = [];
      foreach ($def['fields'] as $field_name) {
        if ($this->fieldExistsInForm($form, $field_name)) {
          $existing[] = $field_name;
        }
      }
      if ($id === 'review' || !empty($existing)) {
        $steps[$id] = [
          'label' => $def['label'],
          'fields' => $existing,
        ];
      }
    }
    return $steps;
  }

  /**
   * Get help text for a wizard step (Australian English, gender-neutral).
   *
   * @param string $step_id
   *   The step ID.
   *
   * @return string|null
   *   The help text, or NULL if no help text is defined.
   */
  private function getStepHelpText(string $step_id): ?string {
    $help_texts = [
      'basics' => 'Tell us about your event. Give it a clear name and description so people know what to expect. You can change these details later if needed.',
      'schedule' => 'When does your event start and finish? Add the date and times. If your event runs over multiple days, you can add recurring dates later.',
      'location' => 'Where will your event be held? Use the address search to find your venue quickly. If it\'s an online event, you can add a link instead. You can change the location later if needed.',
      'tickets' => 'How do people attend your event? Choose RSVP for free events, paid tickets, or both. You can also link to an external booking system. Don\'t worry—you can adjust ticket settings later.',
      'design' => 'Make your event stand out. Add accessibility information and tags to help people find your event. These details help create an inclusive experience for everyone.',
      'questions' => 'Want to collect extra information from attendees? Add custom questions here. This step is optional—you can skip it if you don\'t need additional details.',
      'review' => 'Review all your event details before publishing. Check that everything looks correct. You can go back to any step to make changes.',
    ];

    return $help_texts[$step_id] ?? NULL;
  }

  /**
   * Recursively check if a field exists in form (handles nested sections).
   */
  private function fieldExistsInForm(array $form, string $field_name): bool {
    if (isset($form[$field_name])) {
      return TRUE;
    }

    $sections = ['event_basics', 'location', 'date_time', 'booking_config', 'visibility'];
    foreach ($sections as $section) {
      if (isset($form[$section]) && is_array($form[$section])) {
        if ($this->fieldExistsInForm($form[$section], $field_name)) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * Move a field from form to wizard section (handles nested locations).
   */
  private function moveFieldToWizard(array &$form, array &$target_section, string $field_name): void {
    if (isset($form[$field_name])) {
      $target_section[$field_name] = $form[$field_name];
      unset($form[$field_name]);
      return;
    }

    $sections = ['event_basics', 'location', 'date_time', 'booking_config', 'visibility'];
    foreach ($sections as $section_key) {
      if (isset($form[$section_key]) && is_array($form[$section_key])) {
        if ($this->extractFieldFromSection($form[$section_key], $target_section, $field_name)) {
          $this->cleanupEmptySection($form, $section_key);
          return;
        }
      }
    }
  }

  /**
   * Recursively extract field from a section.
   */
  private function extractFieldFromSection(array &$section, array &$target, string $field_name): bool {
    if (isset($section[$field_name])) {
      $target[$field_name] = $section[$field_name];
      unset($section[$field_name]);
      return TRUE;
    }

    if (isset($section['content']) && is_array($section['content'])) {
      return $this->extractFieldFromSection($section['content'], $target, $field_name);
    }

    foreach ($section as $key => &$value) {
      if (is_array($value) && !str_starts_with($key, '#')) {
        if ($this->extractFieldFromSection($value, $target, $field_name)) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * Hide empty section containers.
   */
  private function cleanupEmptySection(array &$form, string $section_key): void {
    if (!isset($form[$section_key]) || !is_array($form[$section_key])) {
      return;
    }

    $has_content = FALSE;
    foreach ($form[$section_key] as $key => $value) {
      if (!str_starts_with($key, '#')) {
        $has_content = TRUE;
        break;
      }
    }

    if (!$has_content) {
      $form[$section_key]['#access'] = FALSE;
    }
  }

  /**
   * Hide all remaining top-level form elements except system fields.
   */
  private function hideRemainingFormElements(array &$form): void {
    $system_keys = [
      'form_build_id',
      'form_token',
      'form_id',
      'mel_wizard',
      'wizard_current_step',
      'wizard_target_step',
      'actions',
      'field_event_vendor',
      'field_event_store',
      'field_location_latitude',
      'field_location_longitude',
      'field_event_lat',
      'field_event_lng',
    ];

    foreach ($form as $key => &$element) {
      if (str_starts_with($key, '#')) {
        continue;
      }

      if (in_array($key, $system_keys, TRUE)) {
        continue;
      }

      if (in_array($key, ['event_basics', 'location', 'date_time', 'booking_config', 'visibility'], TRUE)) {
        if (isset($element['#access']) && $element['#access'] === FALSE) {
          continue;
        }
        $has_visible_content = FALSE;
        if (is_array($element)) {
          foreach ($element as $subkey => $subvalue) {
            if (!str_starts_with($subkey, '#')) {
              $has_visible_content = TRUE;
              break;
            }
          }
        }
        if (!$has_visible_content) {
          $element['#access'] = FALSE;
        }
        continue;
      }

      if (is_array($element)) {
        $element['#access'] = FALSE;
      }
    }
  }

  /**
   * @param array<string, array{label: string, fields: string[]}> $steps
   */
  private function getCurrentStep(FormStateInterface $form_state, array $steps): string {
    $requested = (string) ($form_state->getValue('wizard_target_step') ?? '');
    $current = (string) ($form_state->get(self::STATE_KEY) ?? '');

    if ($requested !== '' && isset($steps[$requested])) {
      $current = $requested;
    }
    if ($current === '' || !isset($steps[$current])) {
      $current = array_key_first($steps);
    }

    // CRITICAL: Clear wizard_target_step after consuming it.
    $form_state->setValue('wizard_target_step', '');
    $form_state->set(self::STATE_KEY, $current);
    return $current;
  }

  /**
   * LEFT stepper (buttons). JS intercepts and triggers hidden goto AJAX.
   *
   * @param array<string, array{label: string, fields: string[]}> $steps
   */
  private function buildLeftStepper(array $steps, string $current): array {
    $items = [];

    $i = 1;
    foreach ($steps as $step_id => $step) {
      $is_active = ($step_id === $current);

      $items[$step_id] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-wizard__step-row', 'mel-event-form__step-row']],
        'button' => [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => '',
          '#attributes' => [
            'type' => 'button',
            'class' => array_values(array_filter([
              'mel-wizard__step',
              'mel-event-form__step',
              'js-mel-stepper-button',
              $is_active ? 'is-active' : NULL,
            ])),
            'data-step-target' => $step_id,
          ],
          'inner' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['mel-event-form__step-inner']],
            'number' => [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#value' => (string) $i,
              '#attributes' => ['class' => ['mel-event-form__step-number']],
            ],
            'label' => [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#value' => $step['label'],
              '#attributes' => ['class' => ['mel-event-form__step-label']],
            ],
          ],
        ],
      ];

      $i++;
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-wizard__sidebar', 'mel-wizard__nav', 'mel-event-form__wizard-nav']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => 'Event setup',
        '#attributes' => ['class' => ['mel-event-form__wizard-nav-title']],
      ],
      'list' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-wizard__nav-list', 'mel-event-form__wizard-nav-list']],
        'items' => $items,
      ],
    ];
  }

  /**
   * @param array<string, array{label: string, fields: string[]}> $steps
   */
  private function rewriteActionsAsWizardNav(array &$form, FormStateInterface $form_state, array $steps, string $current): void {
    $step_ids = array_keys($steps);
    $index = array_search($current, $step_ids, TRUE);
    $index = ($index === FALSE) ? 0 : $index;

    $is_first = ($index === 0);
    $is_last = ($index === count($step_ids) - 1);

    $original_submit = $form['actions']['submit'] ?? NULL;
    $original_save_draft = $form['actions']['save_draft'] ?? NULL;

    // Preserve other action buttons (like preview, delete, etc.).
    $other_actions = $form['actions'];
    unset($other_actions['submit']);
    unset($other_actions['save_draft']);
    // Remove form property keys.
    foreach (array_keys($other_actions) as $key) {
      if (str_starts_with($key, '#')) {
        unset($other_actions[$key]);
      }
    }

    // Ensure actions container exists.
    if (!isset($form['actions'])) {
      $form['actions'] = [
        '#type' => 'actions',
      ];
    }

    // Set attributes for actions container.
    if (!isset($form['actions']['#attributes'])) {
      $form['actions']['#attributes'] = [];
    }
    if (!isset($form['actions']['#attributes']['class'])) {
      $form['actions']['#attributes']['class'] = [];
    }
    $form['actions']['#attributes']['class'][] = 'mel-event-form__actions';
    $form['actions']['#attributes']['class'][] = 'mel-event-form__actions--sticky';

    $form['actions']['wizard_back'] = [
      '#type' => 'submit',
      '#value' => 'Back',
      '#submit' => [[get_class($this), 'submitBack']],
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => [get_class($this), 'ajaxCallback'],
        'wrapper' => 'mel-event-wizard-wrapper',
      ],
      '#access' => !$is_first,
      '#attributes' => ['class' => ['button', 'mel-event-form__actions-back']],
    ];

    $form['actions']['wizard_next'] = [
      '#type' => 'submit',
      '#value' => $is_last ? 'Finish' : 'Next',
      '#submit' => [[get_class($this), 'submitNext']],
      '#ajax' => [
        'callback' => [get_class($this), 'ajaxCallback'],
        'wrapper' => 'mel-event-wizard-wrapper',
      ],
      '#limit_validation_errors' => $this->limitErrorsForStep($steps, $current),
      '#attributes' => ['class' => ['button', 'button--primary', 'mel-event-form__actions-next']],
    ];

    // Hidden goto submit for stepper clicks (no validation).
    $form['actions']['wizard_goto'] = [
      '#type' => 'submit',
      '#value' => 'Go',
      '#submit' => [[get_class($this), 'submitGoto']],
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => [get_class($this), 'ajaxCallback'],
        'wrapper' => 'mel-event-wizard-wrapper',
      ],
      '#attributes' => ['class' => ['js-mel-wizard-goto', 'visually-hidden']],
    ];

    // Show submit/save_draft on ALL steps - ALWAYS ensure they exist and are visible.
    if ($original_submit) {
      $original_submit['#access'] = TRUE;
      unset($original_submit['#ajax']);
      unset($original_submit['#limit_validation_errors']);
      if (empty($original_submit['#submit'])) {
        $original_submit['#submit'] = [];
      }
      $form_object = $form_state->getFormObject();
      if ($form_object instanceof EntityFormInterface) {
        $has_save_handler = FALSE;
        foreach ($original_submit['#submit'] as $handler) {
          if (is_array($handler) && isset($handler[1]) && $handler[1] === 'save') {
            $has_save_handler = TRUE;
            break;
          }
        }
        if (!$has_save_handler) {
          // Add our publish handler FIRST (weight -10) to set published status before save.
          array_unshift($original_submit['#submit'], [get_class($this), 'submitPublish']);
          // Then add the entity form's save handler (weight 0, default).
          $original_submit['#submit'][] = [$form_object, 'save'];
          // Finally, add post-save handler (weight 10) to sync products.
          $original_submit['#submit'][] = [get_class($this), 'submitPublishPostSave'];
        }
        else {
          // Save handler exists, add our handlers around it.
          array_unshift($original_submit['#submit'], [get_class($this), 'submitPublish']);
          $original_submit['#submit'][] = [get_class($this), 'submitPublishPostSave'];
        }
      }
      if (!isset($original_submit['#attributes'])) {
        $original_submit['#attributes'] = [];
      }
      if (!isset($original_submit['#attributes']['class'])) {
        $original_submit['#attributes']['class'] = [];
      }
      $original_submit['#attributes']['class'][] = 'button';
      $original_submit['#attributes']['class'][] = 'button--primary';
      $original_submit['#attributes']['class'][] = 'mel-event-form__actions-publish';
      $original_submit['#access'] = TRUE;
      $original_submit['#weight'] = 10;
      $form['actions']['submit'] = $original_submit;
    }
    else {
      // No submit button exists - create one.
      $form_object = $form_state->getFormObject();
      $submit_handlers = [];
      // Set published status first.
      $submit_handlers[] = [get_class($this), 'submitPublish'];
      // Then save.
      if ($form_object instanceof EntityFormInterface) {
        $submit_handlers[] = [$form_object, 'save'];
      }
      // Then sync products.
      $submit_handlers[] = [get_class($this), 'submitPublishPostSave'];
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => 'Publish event',
        '#submit' => $submit_handlers,
        '#access' => TRUE,
        '#weight' => 10,
        '#attributes' => ['class' => ['button', 'button--primary', 'mel-event-form__actions-publish']],
      ];
    }

    // Save draft button - always visible.
    if ($original_save_draft) {
      $original_save_draft['#access'] = TRUE;
      if (empty($original_save_draft['#submit'])) {
        $original_save_draft['#submit'] = [];
      }
      $form_object = $form_state->getFormObject();
      // Add our handler first to set unpublished status.
      array_unshift($original_save_draft['#submit'], [get_class($this), 'submitSaveDraft']);
      // Then add entity form's save handler.
      if ($form_object instanceof EntityFormInterface) {
        $has_save_handler = FALSE;
        foreach ($original_save_draft['#submit'] as $handler) {
          if (is_array($handler) && isset($handler[1]) && $handler[1] === 'save') {
            $has_save_handler = TRUE;
            break;
          }
        }
        if (!$has_save_handler) {
          $original_save_draft['#submit'][] = [$form_object, 'save'];
        }
      }
      if (!isset($original_save_draft['#attributes'])) {
        $original_save_draft['#attributes'] = [];
      }
      if (!isset($original_save_draft['#attributes']['class'])) {
        $original_save_draft['#attributes']['class'] = [];
      }
      $original_save_draft['#attributes']['class'][] = 'button';
      $original_save_draft['#attributes']['class'][] = 'mel-event-form__actions-draft';
      $original_save_draft['#access'] = TRUE;
      $original_save_draft['#weight'] = 5;
      $form['actions']['save_draft'] = $original_save_draft;
    }
    else {
      // No save_draft button exists - create one.
      $form_object = $form_state->getFormObject();
      $submit_handlers = [];
      // Set unpublished status first.
      $submit_handlers[] = [get_class($this), 'submitSaveDraft'];
      // Then save.
      if ($form_object instanceof EntityFormInterface) {
        $submit_handlers[] = [$form_object, 'save'];
      }
      $form['actions']['save_draft'] = [
        '#type' => 'submit',
        '#value' => 'Save draft',
        '#submit' => $submit_handlers,
        '#access' => TRUE,
        '#weight' => 5,
        '#attributes' => ['class' => ['button', 'mel-event-form__actions-draft']],
      ];
    }

    // Delete button - only show for existing events.
    $entity = $form_state->getFormObject()->getEntity();
    if (!$entity->isNew() && isset($form['actions']['delete'])) {
      $form['actions']['delete']['#access'] = TRUE;
      $form['actions']['delete']['#attributes']['class'][] = 'button';
      $form['actions']['delete']['#attributes']['class'][] = 'mel-event-form__actions-delete';
    }
    elseif (!$entity->isNew()) {
      $form['actions']['delete'] = [
        '#type' => 'link',
        '#title' => 'Delete',
        '#url' => $entity->toUrl('delete-form'),
        '#attributes' => ['class' => ['button', 'mel-event-form__actions-delete']],
      ];
    }

    // Add back other action buttons (preview, delete, etc.) - always visible.
    foreach ($other_actions as $key => $action) {
      if (is_array($action) && isset($action['#type'])) {
        // Ensure preview and other buttons are visible.
        if (isset($action['#access']) && $action['#access'] === FALSE) {
          // Only hide if it was explicitly hidden, otherwise show it.
          continue;
        }
        $action['#access'] = TRUE;
      }
      $form['actions'][$key] = $action;
    }
  }

  /**
   * Limit validation to current step fields (+ title).
   *
   * @param array<string, array{label: string, fields: string[]}> $steps
   *
   * @return array<int, array<int, string>>
   */
  private function limitErrorsForStep(array $steps, string $step_id): array {
    $limited = [];

    if (isset($steps[$step_id])) {
      foreach ($steps[$step_id]['fields'] as $field_name) {
        $limited[] = [$field_name];
        $limited[] = ['mel_wizard', 'layout', 'content', 'step_' . $step_id, 'card', 'body', 'section', $field_name];
      }
    }

    if (!in_array(['title'], $limited, TRUE)) {
      $limited[] = ['title'];
    }

    return $limited;
  }

  /**
   *
   */
  public static function ajaxCallback(array &$form, FormStateInterface $form_state): array {
    return $form['mel_wizard'] ?? $form;
  }

  /**
   *
   */
  public static function submitNext(array &$form, FormStateInterface $form_state): void {
    $steps = self::rebuildStepsFromForm($form);
    $current = self::getCurrentFromState($form_state, $steps);

    $ids = array_keys($steps);
    $i = array_search($current, $ids, TRUE);
    $i = ($i === FALSE) ? 0 : $i;

    $next = min($i + 1, count($ids) - 1);
    $form_state->set(self::STATE_KEY, $ids[$next]);
    $form_state->setValue('wizard_target_step', $ids[$next]);
    $form_state->setRebuild();
  }

  /**
   *
   */
  public static function submitBack(array &$form, FormStateInterface $form_state): void {
    $steps = self::rebuildStepsFromForm($form);
    $current = self::getCurrentFromState($form_state, $steps);

    $ids = array_keys($steps);
    $i = array_search($current, $ids, TRUE);
    $i = ($i === FALSE) ? 0 : $i;

    $prev = max($i - 1, 0);
    $form_state->set(self::STATE_KEY, $ids[$prev]);
    $form_state->setValue('wizard_target_step', $ids[$prev]);
    $form_state->setRebuild();
  }

  /**
   *
   */
  public static function submitGoto(array &$form, FormStateInterface $form_state): void {
    $steps = self::rebuildStepsFromForm($form);
    $target = (string) ($form_state->getValue('wizard_target_step') ?? '');
    if ($target !== '' && isset($steps[$target])) {
      $form_state->set(self::STATE_KEY, $target);
    }
    $form_state->setRebuild();
  }

  /**
   * Static helpers for submit handlers.
   *
   * @param array $form
   *
   * @return array<string, array{label: string, fields: string[]}>
   */
  private static function rebuildStepsFromForm(array $form): array {
    $steps = [
      'basics' => ['label' => 'Basics', 'fields' => []],
      'schedule' => ['label' => 'Schedule', 'fields' => []],
      'location' => ['label' => 'Location', 'fields' => []],
      'tickets' => ['label' => 'Tickets', 'fields' => []],
      'design' => ['label' => 'Design', 'fields' => []],
      'questions' => ['label' => 'Questions', 'fields' => []],
      'review' => ['label' => 'Review', 'fields' => []],
    ];
    return $steps;
  }

  /**
   * @param array<string, array{label: string, fields: string[]}> $steps
   */
  private static function getCurrentFromState(FormStateInterface $form_state, array $steps): string {
    $requested = (string) ($form_state->getValue('wizard_target_step') ?? '');
    $current = (string) ($form_state->get(self::STATE_KEY) ?? '');

    if ($requested !== '' && isset($steps[$requested])) {
      return $requested;
    }
    if ($current !== '' && isset($steps[$current])) {
      return $current;
    }
    return array_key_first($steps);
  }

  /**
   * Submit handler for saving draft.
   *
   * Sets entity as unpublished. The entity form's save handler (which runs after)
   * will extract form values and save the entity.
   */
  public static function submitSaveDraft(array &$form, FormStateInterface $form_state): void {
    $entity = $form_state->getFormObject()->getEntity();
    if (!$entity instanceof NodeInterface) {
      return;
    }

    // Mark as unpublished. The save handler will save with this status.
    $entity->setUnpublished();

    // Prevent redirect - rebuild form instead.
    $form_state->setRebuild();
  }

  /**
   * Submit handler for publishing event (runs BEFORE save).
   *
   * Sets entity as published. The entity form's save handler (which runs after)
   * will extract form values and save the entity with published status.
   */
  public static function submitPublish(array &$form, FormStateInterface $form_state): void {
    $entity = $form_state->getFormObject()->getEntity();
    if (!$entity instanceof NodeInterface) {
      return;
    }

    // Mark as published. The save handler will save with this status.
    $entity->setPublished();
  }

  /**
   * Submit handler for publishing event (runs AFTER save).
   *
   * Syncs products after the entity has been saved.
   */
  public static function submitPublishPostSave(array &$form, FormStateInterface $form_state): void {
    $entity = $form_state->getFormObject()->getEntity();
    if (!$entity instanceof NodeInterface) {
      return;
    }

    // Sync products ONLY if event is published and saved.
    if ($entity->isPublished() && !$entity->isNew()) {
      try {
        $product_manager = \Drupal::service('myeventlane_event.event_product_manager');
        if ($product_manager instanceof EventProductManager) {
          $product_manager->syncProducts($entity, 'publish');
        }
        \Drupal::messenger()->addStatus(t('Event published successfully.'));
      }
      catch (\Exception $e) {
        \Drupal::messenger()->addError(t('Error syncing products: @message', ['@message' => $e->getMessage()]));
      }
    }
  }

  /**
   * Validation handler for coordinate fields - always passes.
   */
  public static function validateCoordinateField(array &$element, FormStateInterface $form_state): void {
    // Always pass validation for coordinate fields - they're auto-populated.
  }

  /**
   * Ensures Save Draft and Publish buttons are present (fallback when wizard doesn't apply).
   *
   * Public so it can be called from hook implementations.
   */
  public function ensureActionButtons(array &$form, FormStateInterface $form_state): void {
    // CRITICAL: Always ensure actions container exists.
    if (!isset($form['actions'])) {
      $form['actions'] = [
        '#type' => 'actions',
        '#weight' => 1000,
      ];
    }

    // Ensure actions container is accessible.
    if (!isset($form['actions']['#access'])) {
      $form['actions']['#access'] = TRUE;
    }

    // Ensure submit button exists and is configured for publish.
    if (!isset($form['actions']['submit'])) {
      $form_object = $form_state->getFormObject();
      $submit_handlers = [];
      $submit_handlers[] = [get_class($this), 'submitPublish'];
      if ($form_object instanceof EntityFormInterface) {
        $submit_handlers[] = [$form_object, 'save'];
      }
      $submit_handlers[] = [get_class($this), 'submitPublishPostSave'];
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => 'Publish event',
        '#submit' => $submit_handlers,
        '#access' => TRUE,
        '#weight' => 10,
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    }
    else {
      // Ensure submit button has our handlers and is visible.
      $form['actions']['submit']['#access'] = TRUE;

      $form_object = $form_state->getFormObject();
      if ($form_object instanceof EntityFormInterface) {
        if (empty($form['actions']['submit']['#submit'])) {
          $form['actions']['submit']['#submit'] = [];
        }
        $has_publish_handler = FALSE;
        $has_save_handler = FALSE;
        foreach ($form['actions']['submit']['#submit'] as $handler) {
          if (is_array($handler) && isset($handler[1])) {
            if ($handler[1] === 'submitPublish') {
              $has_publish_handler = TRUE;
            }
            if ($handler[1] === 'save') {
              $has_save_handler = TRUE;
            }
          }
        }
        if (!$has_publish_handler) {
          array_unshift($form['actions']['submit']['#submit'], [get_class($this), 'submitPublish']);
        }
        if (!$has_save_handler) {
          $form['actions']['submit']['#submit'][] = [$form_object, 'save'];
        }
        if (!in_array([get_class($this), 'submitPublishPostSave'], $form['actions']['submit']['#submit'], TRUE)) {
          $form['actions']['submit']['#submit'][] = [get_class($this), 'submitPublishPostSave'];
        }
      }

      // Ensure button has proper attributes.
      if (!isset($form['actions']['submit']['#attributes'])) {
        $form['actions']['submit']['#attributes'] = [];
      }
      if (!isset($form['actions']['submit']['#attributes']['class'])) {
        $form['actions']['submit']['#attributes']['class'] = [];
      }
      if (!in_array('button', $form['actions']['submit']['#attributes']['class'])) {
        $form['actions']['submit']['#attributes']['class'][] = 'button';
      }
      if (!in_array('button--primary', $form['actions']['submit']['#attributes']['class'])) {
        $form['actions']['submit']['#attributes']['class'][] = 'button--primary';
      }
    }

    // Ensure save_draft button exists.
    if (!isset($form['actions']['save_draft'])) {
      $form_object = $form_state->getFormObject();
      $submit_handlers = [];
      $submit_handlers[] = [get_class($this), 'submitSaveDraft'];
      if ($form_object instanceof EntityFormInterface) {
        $submit_handlers[] = [$form_object, 'save'];
      }
      $form['actions']['save_draft'] = [
        '#type' => 'submit',
        '#value' => 'Save draft',
        '#submit' => $submit_handlers,
        '#access' => TRUE,
        '#weight' => 5,
        '#attributes' => ['class' => ['button']],
      ];
    }
    else {
      // Ensure save_draft button has our handler and is visible.
      $form['actions']['save_draft']['#access'] = TRUE;

      if (empty($form['actions']['save_draft']['#submit'])) {
        $form['actions']['save_draft']['#submit'] = [];
      }
      $has_draft_handler = FALSE;
      foreach ($form['actions']['save_draft']['#submit'] as $handler) {
        if (is_array($handler) && isset($handler[1]) && $handler[1] === 'submitSaveDraft') {
          $has_draft_handler = TRUE;
          break;
        }
      }
      if (!$has_draft_handler) {
        array_unshift($form['actions']['save_draft']['#submit'], [get_class($this), 'submitSaveDraft']);
        $form_object = $form_state->getFormObject();
        if ($form_object instanceof EntityFormInterface) {
          $has_save_handler = FALSE;
          foreach ($form['actions']['save_draft']['#submit'] as $handler) {
            if (is_array($handler) && isset($handler[1]) && $handler[1] === 'save') {
              $has_save_handler = TRUE;
              break;
            }
          }
          if (!$has_save_handler) {
            $form['actions']['save_draft']['#submit'][] = [$form_object, 'save'];
          }
        }
      }

      // Ensure button has proper attributes.
      if (!isset($form['actions']['save_draft']['#attributes'])) {
        $form['actions']['save_draft']['#attributes'] = [];
      }
      if (!isset($form['actions']['save_draft']['#attributes']['class'])) {
        $form['actions']['save_draft']['#attributes']['class'] = [];
      }
      if (!in_array('button', $form['actions']['save_draft']['#attributes']['class'])) {
        $form['actions']['save_draft']['#attributes']['class'][] = 'button';
      }
    }
  }

}
