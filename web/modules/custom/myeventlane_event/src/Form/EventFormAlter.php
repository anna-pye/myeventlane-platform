<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Vendor-context Event form wizard.
 *
 * Principles:
 * - PHP owns structure and visibility (one step visible).
 * - Twig prints form.mel_wizard only. No manual field rendering.
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

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('current_user'),
      $container->get('current_route_match'),
      $container->get('entity_type.manager'),
    );
  }

  public function alterForm(array &$form, FormStateInterface $form_state, string $form_id): void {
    if (!$this->isEventForm($form, $form_state, $form_id)) {
      return;
    }

    // Only apply wizard in vendor context.
    if (!$this->isVendorContext()) {
      return;
    }

    $form['#attached']['library'][] = 'myeventlane_event/event_wizard';

    // Vendor UX: hide admin-only fields and help noise.
    $this->hideAdminFields($form);
    $this->suppressTextFormatHelp($form);

    $steps = $this->buildStepsFromForm($form);
    if (count($steps) < 2) {
      return;
    }

    $current = $this->getCurrentStep($form_state, $steps);

    // Wizard root container. Single AJAX wrapper id.
    $form['mel_wizard'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'mel-event-wizard-wrapper',
        'class' => ['mel-event-form', 'mel-event-form--wizard'],
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
      '#attributes' => ['class' => ['mel-event-form__wizard-layout']],
    ];

    $form['mel_wizard']['layout']['nav'] = $this->buildLeftStepper($steps, $current);

    $form['mel_wizard']['layout']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-event-form__wizard-content']],
    ];

    // Move top-level fields into step panels.
    foreach ($steps as $step_id => $step) {
      $panel_key = 'step_' . $step_id;
      $is_active = ($step_id === $current);

      $form['mel_wizard']['layout']['content'][$panel_key] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-event-form__panel', 'mel-wizard-step'],
          'data-step' => $step_id,
        ],
        '#access' => $is_active,
      ];

      $form['mel_wizard']['layout']['content'][$panel_key]['title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $step['label'],
        '#attributes' => [
          'class' => ['mel-event-form__section-title', 'mel-wizard-step__title'],
        ],
      ];

      // Section container (vendor theme uses this card look).
      $form['mel_wizard']['layout']['content'][$panel_key]['section'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-form__section']],
      ];

      foreach ($step['fields'] as $field_name) {
        if (isset($form[$field_name])) {
          $form['mel_wizard']['layout']['content'][$panel_key]['section'][$field_name] = $form[$field_name];
          unset($form[$field_name]);
        }
      }
    }

    // Rewrite actions into wizard nav (AJAX + scoped validation).
    $this->rewriteActionsAsWizardNav($form, $form_state, $steps, $current);
  }

  private function isEventForm(array &$form, FormStateInterface $form_state, string $form_id): bool {
    if ($form_id === 'node_event_form' || str_starts_with($form_id, 'node_event_')) {
      return TRUE;
    }
    return FALSE;
  }

  private function isVendorContext(): bool {
    $route_name = (string) $this->routeMatch->getRouteName();
    // Conservative: vendor console routes typically contain "/vendor/".
    // If your canonical vendor routes differ, adjust this matcher.
    return str_contains($route_name, 'myeventlane_vendor') || str_contains($route_name, 'vendor');
  }

  private function hideAdminFields(array &$form): void {
    foreach (['field_event_vendor', 'field_event_store'] as $field_name) {
      if (isset($form[$field_name])) {
        $form[$field_name]['#access'] = FALSE;
      }
    }
  }

  private function suppressTextFormatHelp(array &$form): void {
    // Remove format help if present to reduce clutter in vendor UI.
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
   * Build steps based on fields present on the current site.
   *
   * @return array<string, array{label: string, fields: string[]}>
   */
  private function buildStepsFromForm(array $form): array {
    $candidates = [
      'basics' => [
        'label' => 'Basics',
        'fields' => ['title', 'body', 'field_event_image'],
      ],
      'schedule' => [
        'label' => 'Schedule',
        'fields' => ['field_event_date', 'field_event_end_date', 'field_event_recurring'],
      ],
      'location' => [
        'label' => 'Location',
        'fields' => ['field_event_location_mode', 'field_event_location', 'field_event_lat', 'field_event_lng', 'field_event_online_url'],
      ],
      'tickets' => [
        'label' => 'Tickets',
        'fields' => ['field_event_mode', 'field_event_ticket_types', 'field_event_capacity', 'field_event_external_url'],
      ],
      'design' => [
        'label' => 'Design',
        'fields' => ['field_event_theme', 'field_event_primary_color', 'field_event_secondary_color'],
      ],
      'questions' => [
        'label' => 'Questions',
        'fields' => ['field_event_questions'],
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
        if (isset($form[$field_name])) {
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
        '#attributes' => ['class' => ['mel-event-form__step-row']],
        'button' => [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => '',
          '#attributes' => [
            'type' => 'button',
            'class' => array_values(array_filter([
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
      '#attributes' => ['class' => ['mel-event-form__wizard-nav']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => 'Event setup',
        '#attributes' => ['class' => ['mel-event-form__wizard-nav-title']],
      ],
      'list' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-form__wizard-nav-list']],
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

    // Keep original actions but only show on last step.
    $other_actions = $form['actions'];
    unset($other_actions['submit']);

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['mel-event-form__actions', 'mel-event-form__actions--sticky']],
    ];

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

    if ($original_submit) {
      $original_submit['#access'] = $is_last;
      $original_submit['#attributes']['class'][] = 'mel-event-form__actions-publish';
      $form['actions']['submit'] = $original_submit;
    }

    foreach ($other_actions as $key => $action) {
      if (is_array($action)) {
        $action['#access'] = $is_last;
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
      }
    }

    if (!in_array(['title'], $limited, TRUE)) {
      $limited[] = ['title'];
    }

    return $limited;
  }

  public static function ajaxCallback(array &$form, FormStateInterface $form_state): array {
    return $form['mel_wizard'] ?? $form;
  }

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
   * @return array<string, array{label: string, fields: string[]}>
   */
  private static function rebuildStepsFromForm(array $form): array {
    // Minimal rebuild: rely on hidden structure created in alter stage.
    // If mel_wizard exists, the original steps are implicit. Fallback is safe.
    // This keeps submit handlers stable even if the alter runs again.
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

}
