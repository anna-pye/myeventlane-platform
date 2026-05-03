<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Ticketing;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_event\Service\TicketPresetManager;
use Drupal\myeventlane_event\Service\TicketTierLifecycleService;
use Drupal\myeventlane_event\Service\TicketTypeManager;
use Drupal\myeventlane_commerce\Service\TicketStatusService;
use Drupal\myeventlane_commerce\Service\TicketTierAnalyticsService;
use Drupal\node\NodeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Shared ticket builder UI for wizard and workspace roots.
 *
 * This class only builds Form API elements into an existing root form.
 * It never owns the root form itself.
 */
final class EventTicketsBuilder {

  use StringTranslationTrait;

  public const BUILDER_WRAPPER_ID = 'mel-ticket-builder-ajax-wrapper';

  private const SHORT_DESCRIPTION_MAX_LENGTH = 320;

  public function __construct(
    private readonly TicketTierLifecycleService $lifecycle,
    private readonly TicketTypeManager $ticketTypeManager,
    private readonly TicketStatusService $ticketStatus,
    private readonly TicketTierAnalyticsService $tierAnalytics,
    private readonly AccountProxyInterface $currentUser,
    private readonly MessengerInterface $messenger,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TicketPresetManager $ticketPresetManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Temporary trace: Event Studio ticket builder AJAX always rebuilds; see MEL- debug.
   */
  private function logFormRebuildContext(string $context): void {
    $this->loggerFactory->get('mel_debug')->notice('FORM REBUILD TRIGGERED (@context)', [
      '@context' => $context,
    ]);
  }

  /**
   * Form value / #limit_validation_errors path under the root form.
   *
   * @return list<string|int>
   */
  private function valuePath(FormStateInterface $form_state, string ...$segments): array {
    $prefix = $form_state->get('mel_ticket_builder_value_prefix');
    if (!is_array($prefix)) {
      $prefix = [];
    }
    return array_merge($prefix, $segments);
  }

  /**
   * HTML name attribute for a nested element (matches Form API rendering).
   *
   * @param list<string|int> $segments
   *   Path segments under the builder root (same order as valuePath()).
   */
  private function formElementFullName(FormStateInterface $form_state, string ...$segments): string {
    $path = $this->valuePath($form_state, ...$segments);
    if ($path === []) {
      return '';
    }
    $head = (string) array_shift($path);
    foreach ($path as $segment) {
      $head .= '[' . $segment . ']';
    }
    return $head;
  }

  /**
   * Computes sale_start / sale_end for lifecycle payloads from ticket builder rows.
   *
   * Does not duplicate availability evaluation — stored fields are enforced by
   * TicketAvailabilityService / BookingFlowResolver downstream.
   *
   * @param array<string, mixed> $values
   *
   * @return array<string, mixed|null>
   */
  private function buildSaleWindowFragmentForLifecycle(array $values): array {
    if (empty($values['sale_window_enabled'])) {
      return ['sale_start' => NULL, 'sale_end' => NULL];
    }
    $start = $this->normalizeSaleBoundaryInput($values['sale_start'] ?? NULL);
    $end = $this->normalizeSaleBoundaryInput($values['sale_end'] ?? NULL);
    if ($start === NULL || $start === '') {
      throw new \InvalidArgumentException((string) $this->t('Enter a sale start date and time.'));
    }
    if ($end === NULL || $end === '') {
      throw new \InvalidArgumentException((string) $this->t('Enter a sale end date and time.'));
    }
    $startTs = strtotime($start);
    $endTs = strtotime($end);
    if ($startTs === FALSE || $endTs === FALSE) {
      throw new \InvalidArgumentException((string) $this->t('Sale dates could not be read. Check the values and try again.'));
    }
    if ($startTs >= $endTs) {
      throw new \InvalidArgumentException((string) $this->t('Sale start must be before sale end.'));
    }

    return [
      'sale_start' => $start,
      'sale_end' => $end,
    ];
  }

  private function normalizeSaleBoundaryInput(mixed $input): ?string {
    if ($input === NULL || $input === '') {
      return NULL;
    }
    if ($input instanceof DrupalDateTime) {
      return $input->hasErrors()
        ? NULL
        : $input->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
    }
    if (is_array($input)) {
      $candidates = [
        $input['date'] ?? NULL,
        $input['object'] ?? NULL,
      ];
      foreach ($candidates as $candidate) {
        if ($candidate instanceof DrupalDateTime) {
          return $candidate->hasErrors()
            ? NULL
            : $candidate->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
        }
      }
      return NULL;
    }
    if (!is_string($input)) {
      return NULL;
    }
    $t = trim($input);
    if ($t === '') {
      return NULL;
    }
    try {
      $d = new DrupalDateTime($t);
      if ($d->hasErrors()) {
        return NULL;
      }
      return $d->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  private function ticketSaleWindowDefaultChecked(TicketTypeInterface $ticket): bool {
    return !$ticket->get('sale_start')->isEmpty() || !$ticket->get('sale_end')->isEmpty();
  }

  private function ticketSaleDatetimeDefault(?TicketTypeInterface $ticket, string $field): ?DrupalDateTime {
    if (!$ticket instanceof TicketTypeInterface || !$ticket->hasField($field) || $ticket->get($field)->isEmpty()) {
      return NULL;
    }

    try {
      return new DrupalDateTime((string) $ticket->get($field)->value);
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  private function buildTicketSaleWindowSummaryMarkup(TicketTypeInterface $ticket): ?string {
    $has_start = !$ticket->get('sale_start')->isEmpty();
    $has_end = !$ticket->get('sale_end')->isEmpty();
    if (!$has_start && !$has_end) {
      return NULL;
    }
    if ($has_end) {
      $end = $ticket->get('sale_end')->date;
      if (!$end instanceof \DateTimeInterface) {
        return NULL;
      }
      return (string) $this->t('On sale until @date', [
        '@date' => $this->dateFormatter->format($end->getTimestamp(), 'medium'),
      ]);
    }
    $start = $ticket->get('sale_start')->date;
    if (!$start instanceof \DateTimeInterface) {
      return NULL;
    }
    return (string) $this->t('Sales start @date', [
      '@date' => $this->dateFormatter->format($start->getTimestamp(), 'medium'),
    ]);
  }

  /**
   * Builds the ticket builder UI into an existing parent form subtree.
   */
  public function build(array &$form, FormStateInterface $form_state, NodeInterface $event): void {
    // Hierarchical #parents are required so submitted values nest under
    // builder_shell/list/… and match valuePath() + #limit_validation_errors.
    // Without #tree, FAPI uses single-key #parents and limited validation
    // strips all builder input before submit handlers run.
    if (!array_key_exists('#tree', $form)) {
      $form['#tree'] = TRUE;
    }

    if (!$this->canManageEvent($event)) {
      $form['builder_shell'] = [
        '#type' => 'container',
        '#attributes' => [
          'id' => self::BUILDER_WRAPPER_ID,
          'class' => ['mel-ticket-builder', 'mel-stack', 'mel-stack--lg'],
        ],
      ];
      $form['builder_shell']['error'] = [
        '#markup' => '<p>' . Html::escape((string) $this->t('You do not have access to manage this event.')) . '</p>',
      ];
      return;
    }

    $form_state->set('event', $event);

    // Align node.field_ticket_types with ticket entities that reference this event
    // (fixes orphan inverse refs when the node field was never written).
    $this->lifecycle->reconcileEventTicketReferences($event);
    if ($event->id()) {
      $fresh = $this->entityTypeManager->getStorage('node')->load($event->id());
      if ($fresh instanceof NodeInterface) {
        $event = $fresh;
        $form_state->set('event', $fresh);
      }
    }

    $tickets = array_values(array_filter(
      $this->lifecycle->loadOrderedTicketsForEvent($event),
      static fn ($ticket) => $ticket instanceof TicketTypeInterface
    ));
    $ticket_presets = $this->ticketPresetManager->getPresets();

    $ordered_ids = array_map(static fn (TicketTypeInterface $ticket) => (int) $ticket->id(), $tickets);
    $editing_id = $form_state->get('editing_ticket_id');
    $adding_new = (bool) $form_state->get('mel_ticket_adding_new');

    $form['builder_shell'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => self::BUILDER_WRAPPER_ID,
        'class' => ['mel-ticket-builder', 'mel-stack', 'mel-stack--lg'],
      ],
    ];

    $form['builder_shell']['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-ticket-builder__header']],
    ];

    $form['builder_shell']['header']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => $this->t('Tickets'),
      '#attributes' => ['class' => ['mel-ticket-builder__title']],
    ];

    $form['builder_shell']['header']['summary'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Add RSVP, paid, or external tiers — paid tiers stay in sync with checkout.'),
      '#attributes' => ['class' => ['mel-ticket-builder__summary', 'description']],
    ];

    if ($tickets !== []) {
      $form['builder_shell']['header']['analytics_strip'] = $this->buildEventAnalyticsStrip($event, $tickets);
    }

    $form['builder_shell']['controls'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-ticket-controls', 'mel-ticket-controls--primary']],
    ];

    $form['builder_shell']['controls']['open_presets'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $this->t('Add another ticket type'),
      '#attributes' => [
        'type' => 'button',
        'class' => ['mel-btn', 'mel-btn--primary', 'mel-ticket-controls__add'],
        'data-mel-ticket-presets-toggle' => '1',
        'aria-controls' => 'mel-ticket-preset-selector',
        'aria-expanded' => 'false',
      ],
      '#access' => !$adding_new,
    ];

    $form['builder_shell']['controls']['preset_selector'] = $this->buildPresetSelector($ticket_presets, $tickets !== [], $form_state);
    $form['builder_shell']['controls']['preset_selector']['#access'] = !$adding_new;

    $form['builder_shell']['controls']['begin_add_paid'] = [
      '#type' => 'submit',
      '#value' => $this->t('+ Add paid ticket'),
      '#name' => 'ticket_begin_add_paid',
      '#submit' => ['::handleAction'],
      '#ajax' => [
        'callback' => '::ajaxRebuildTicketBuilder',
        'wrapper' => EventTicketsBuilder::BUILDER_WRAPPER_ID,
      ],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn--primary', 'mel-ticket-controls__paid'],
        'formnovalidate' => 'formnovalidate',
      ],
      '#access' => FALSE,
    ];

    $form['builder_shell']['controls']['begin_add_external'] = [
      '#type' => 'submit',
      '#value' => $this->t('+ External ticket link'),
      '#name' => 'ticket_begin_add_external',
      '#submit' => ['::handleAction'],
      '#ajax' => [
        'callback' => '::ajaxRebuildTicketBuilder',
        'wrapper' => EventTicketsBuilder::BUILDER_WRAPPER_ID,
      ],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn--secondary', 'mel-ticket-controls__external'],
        'formnovalidate' => 'formnovalidate',
      ],
      '#access' => FALSE,
    ];

    $form['builder_shell']['order'] = [
      '#type' => 'hidden',
      '#default_value' => Json::encode($ordered_ids),
      '#attributes' => ['class' => ['js-mel-ticket-order']],
    ];

    $form['builder_shell']['reorder_trigger'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save ticket order'),
      '#name' => 'ticket_reorder',
      '#submit' => ['::handleAction'],
      '#ajax' => [
        'callback' => '::ajaxRebuildTicketBuilder',
        'wrapper' => EventTicketsBuilder::BUILDER_WRAPPER_ID,
      ],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['js-mel-ticket-reorder-submit', 'visually-hidden'],
        // Wizard forms include many required fields; without this, programmatic
        // .click() on this button is blocked by HTML5 constraint validation.
        'formnovalidate' => 'formnovalidate',
      ],
    ];

    $list_classes = ['mel-ticket-list', 'js-mel-ticket-sortable'];
    if (count($tickets) < 2) {
      $list_classes[] = 'mel-ticket-list--no-reorder';
    }

    $form['builder_shell']['list'] = [
      '#type' => 'container',
      '#attributes' => ['class' => $list_classes],
    ];

    if ($adding_new) {
      $form['builder_shell']['list']['new'] = $this->buildNewTicketCard($event, $form_state);
      // Hero styling: first row when this is the only ticket surface (preset flow).
      if ($tickets === []) {
        $form['builder_shell']['list']['new']['#attributes']['class'][] = 'mel-ticket--primary';
      }
    }

    // First saved ticket is "primary" when it leads the list (or follows the inline
    // "new" card — match client-side reorder highlighting in ticket-cards.js).
    $assign_primary_saved = !$adding_new || $tickets !== [];

    foreach ($tickets as $ticket) {
      $tid = (int) $ticket->id();
      $ticket_status = $this->ticketStatus->getStatus($ticket);
      $read_only_card = in_array($ticket_status, [
        TicketStatusService::STATUS_ENDED,
        TicketStatusService::STATUS_ARCHIVED,
      ], TRUE);
      $is_editing = (int) $editing_id === $tid && !$read_only_card;
      $card_classes = [
        'mel-card',
        'mel-ticket-card',
        'js-mel-ticket-card',
        $is_editing ? 'is-editing' : 'is-view',
      ];
      if (!$read_only_card && $this->ticketCardIsOutcomeComplete($ticket)) {
        $card_classes[] = 'mel-ticket--complete';
      }
      if ($assign_primary_saved) {
        $card_classes[] = 'mel-ticket--primary';
        $assign_primary_saved = FALSE;
      }
      if ($ticket->hasField('field_is_default_ticket') && $ticket->isDefaultTicket()) {
        $card_classes[] = 'mel-ticket-card--recommended';
      }

      $form['builder_shell']['list'][$tid] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => $card_classes,
          'data-ticket-id' => (string) $tid,
          'data-mel-ticket-kind' => $ticket->getTicketKind(),
          'data-mel-ticket-dirty-label' => (string) $this->t('Unsaved changes'),
        ],
      ];

      // Use a span, not <button>: Chrome and others often fail to start HTML5
      // drag when draggable="true" is on a button inside a form.
      $form['builder_shell']['list'][$tid]['drag_handle'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => '',
        '#attributes' => [
          'role' => 'button',
          'tabindex' => '0',
          'class' => [
            'mel-ticket-card__drag',
            'mel-ticket-drag-handle',
            'js-mel-ticket-drag-handle',
          ],
          'aria-label' => (string) $this->t('Drag to reorder ticket'),
          'title' => (string) $this->t('Drag to reorder'),
          'draggable' => 'true',
        ],
      ];

      $this->appendViewTicketCardElements($form['builder_shell']['list'][$tid], $ticket, $form_state);
      if (!$read_only_card) {
        $this->appendEditTicketCardElements($form['builder_shell']['list'][$tid], $ticket, $form_state);
      }
    }

    $suggestions = $this->buildTicketSuggestions($tickets, $ticket_presets, $form_state);
    if ($suggestions !== []) {
      $form['builder_shell']['suggestions'] = $suggestions;
    }

    if (!$adding_new && $tickets === []) {
      $form['builder_shell']['empty'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-ticket-builder__empty', 'mel-ticket-builder__empty--hero']],
        'text' => [
          '#markup' => '<p class="mel-ticket-builder__empty-title">' . Html::escape((string) $this->t('Create your first ticket')) . '</p>'
            . '<p class="mel-ticket-builder__empty-body">' . Html::escape((string) $this->t('Start with General Admission or choose another preset. You can edit every detail before saving.')) . '</p>',
        ],
        'actions' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-ticket-builder__empty-actions']],
          'general' => $this->buildPresetSubmitButton('general', $ticket_presets['general'], TRUE),
          'show_all' => [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#value' => $this->t('Show all presets'),
            '#attributes' => [
              'type' => 'button',
              'class' => ['mel-btn', 'mel-btn--secondary'],
              'data-mel-ticket-presets-toggle' => '1',
              'aria-controls' => 'mel-ticket-preset-selector',
              'aria-expanded' => 'false',
            ],
          ],
        ],
      ];
    }

    $form['#attached']['library'][] = 'myeventlane_vendor/ticket_cards';
    $form['#attached']['library'][] = 'core/drupal.ajax';
    $form['#attached']['drupalSettings']['myeventlaneTicketPresets']['presets'] = $ticket_presets;
  }

  /**
   * Builds next-best-action guidance after the first ticket is created.
   *
   * @param list<TicketTypeInterface> $tickets
   * @param array<string, array{label: string, description: string, values: array<string, mixed>}> $presets
   */
  private function buildTicketSuggestions(array $tickets, array $presets, FormStateInterface $form_state): array {
    if (
      count($tickets) !== 1 ||
      !$form_state->get('mel_ticket_show_suggestions') ||
      $form_state->get('mel_ticket_suggestions_dismissed') ||
      $form_state->get('mel_ticket_adding_new')
    ) {
      return [];
    }

    $ticket = $tickets[0];
    $suggestions = match ($ticket->getTicketKind()) {
      'paid' => [
        'early_bird' => [
          'icon' => '🔥',
          'label' => (string) $this->t('Add Early Bird'),
          'explanation' => (string) $this->t('Limited tickets to drive early sales'),
        ],
        'vip' => [
          'icon' => '💎',
          'label' => (string) $this->t('Add VIP'),
          'explanation' => (string) $this->t('Offer premium access'),
        ],
      ],
      'rsvp' => [
        'donation' => [
          'icon' => '💛',
          'label' => (string) $this->t('Add donation option'),
          'explanation' => (string) $this->t('Let guests add optional support'),
        ],
      ],
      default => [],
    };

    if ($suggestions === []) {
      return [];
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-ticket-suggestions']],
      'intro' => [
        '#markup' => '<div class="mel-ticket-suggestions__intro">'
          . '<h4>' . Html::escape((string) $this->t('Nice — your main ticket is ready.')) . '</h4>'
          . '<p>' . Html::escape((string) $this->t('Add one more option to give buyers a clear next choice.')) . '</p>'
          . '</div>',
      ],
      'items' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-ticket-suggestions__items']],
      ],
    ];

    $has_items = FALSE;
    foreach ($suggestions as $preset_key => $suggestion) {
      if (!isset($presets[$preset_key])) {
        continue;
      }
      $has_items = TRUE;
      $build['items'][$preset_key] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-ticket-suggestion']],
        'copy' => [
          '#markup' => '<div class="mel-ticket-suggestion__copy">'
            . '<span class="mel-ticket-suggestion__icon" aria-hidden="true">' . Html::escape($suggestion['icon']) . '</span>'
            . '<div><h5>' . Html::escape($suggestion['label']) . '</h5>'
            . '<p>' . Html::escape($suggestion['explanation']) . '</p></div>'
            . '</div>',
        ],
        'action' => $this->buildPresetSubmitButton($preset_key, $presets[$preset_key], FALSE, $suggestion['label'], ['mel-ticket-suggestion__button']),
      ];
    }

    if (!$has_items) {
      return [];
    }

    $build['dismiss'] = [
      '#type' => 'submit',
      '#value' => $this->t('Hide suggestions'),
      '#name' => 'ticket_suggestions_dismiss',
      '#submit' => ['::handleAction'],
      '#ajax' => [
        'callback' => '::ajaxRebuildTicketBuilder',
        'wrapper' => EventTicketsBuilder::BUILDER_WRAPPER_ID,
      ],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn--ghost', 'mel-ticket-suggestions__dismiss'],
        'formnovalidate' => 'formnovalidate',
      ],
    ];

    return $build;
  }

  /**
   * Builds the inline preset selector shown by the Add ticket control.
   *
   * @param array<string, array{label: string, description: string, values: array<string, mixed>}> $presets
   */
  private function buildPresetSelector(array $presets, bool $has_existing_tickets, FormStateInterface $form_state): array {
    $selector = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'mel-ticket-preset-selector',
        'class' => ['mel-ticket-presets', 'js-mel-ticket-presets'],
        'hidden' => 'hidden',
      ],
      'intro' => [
        '#markup' => '<div class="mel-ticket-presets__intro">'
          . '<h4>' . Html::escape((string) $this->t('Choose a ticket preset')) . '</h4>'
          . '<p>' . Html::escape((string) $this->t('Start with a useful structure, then edit every field before saving.')) . '</p>'
          . '</div>',
      ],
      'cards' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-ticket-presets__grid']],
      ],
    ];

    foreach ($presets as $key => $preset) {
      $classes = ['mel-ticket-preset-card', 'js-mel-ticket-preset'];
      if ($key === 'general' && !$has_existing_tickets) {
        $classes[] = 'mel-ticket-preset-card--recommended';
      }

      $hint = $this->presetHint($key);
      $selector['cards'][$key] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => $classes,
          'data-mel-ticket-preset-key' => $key,
          'tabindex' => '0',
        ],
        'copy' => [
          '#markup' => '<div class="mel-ticket-preset-card__copy">'
            . '<h5>' . Html::escape((string) $preset['label']) . '</h5>'
            . '<p>' . Html::escape((string) $preset['description']) . '</p>'
            . ($hint !== '' ? '<p class="mel-ticket-preset-card__hint">' . Html::escape($hint) . '</p>' : '')
            . '</div>',
        ],
        'select' => $this->buildPresetSubmitButton($key, $preset, $key === 'general' && !$has_existing_tickets),
      ];
    }

    return $selector;
  }

  /**
   * Builds one AJAX submit button that opens the existing new-ticket card.
   *
   * @param array{label: string, description: string, values: array<string, mixed>} $preset
   */
  private function buildPresetSubmitButton(string $key, array $preset, bool $primary = FALSE, mixed $button_label = NULL, array $extra_classes = []): array {
    return [
      '#type' => 'submit',
      '#value' => $button_label ?? ($primary ? $this->t('General Admission') : $this->t('Use @label', ['@label' => $preset['label']])),
      '#name' => 'ticket_begin_preset_' . $key,
      '#submit' => ['::handleAction'],
      '#ajax' => [
        'callback' => '::ajaxRebuildTicketBuilder',
        'wrapper' => EventTicketsBuilder::BUILDER_WRAPPER_ID,
      ],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => array_filter([
          'mel-btn',
          $primary ? 'mel-btn--primary' : 'mel-btn--secondary',
          'js-mel-ticket-preset-submit',
          ...$extra_classes,
        ]),
        'data-mel-ticket-preset-key' => $key,
        // Event Studio nests this builder in a form with required mel[title] etc.; without
        // formnovalidate the browser blocks AJAX before Drupal runs.
        'formnovalidate' => 'formnovalidate',
      ],
    ];
  }

  private function presetHint(string $key): string {
    return match ($key) {
      'general' => (string) $this->t('Recommended as your first ticket'),
      'early_bird' => (string) $this->t('Limited tickets create urgency'),
      'vip' => (string) $this->t('Higher price tier with added value'),
      default => '',
    };
  }

  /**
   * Reads a new-ticket field value, preferring submitted values over presets.
   */
  private function newTicketDefault(FormStateInterface $form_state, string $key, mixed $fallback): mixed {
    $submitted = $form_state->getValue($this->valuePath($form_state, 'builder_shell', 'list', 'new', 'fields', $key));
    if ($submitted !== NULL) {
      return $submitted;
    }

    $defaults = $form_state->get('mel_ticket_preset_defaults');
    if (is_array($defaults) && array_key_exists($key, $defaults)) {
      return $defaults[$key];
    }

    return $fallback;
  }

  /**
   * Maps product preset values onto the existing ticket builder input contract.
   *
   * @param array<string, mixed> $values
   */
  private function normalizePresetValuesForNewTicket(array $values): array {
    $defaults = [];
    foreach ($values as $key => $value) {
      $target = match ($key) {
        'price' => 'price_amount',
        'visibility' => 'visibility_mode',
        default => $key,
      };
      $defaults[$target] = $value;
    }

    if (!isset($defaults['ticket_kind'])) {
      $defaults['ticket_kind'] = 'paid';
    }
    if (!array_key_exists('status', $defaults)) {
      $defaults['status'] = 1;
    }
    if (!array_key_exists('visibility_mode', $defaults)) {
      $defaults['visibility_mode'] = 'public';
    }

    return $defaults;
  }

  /**
   * Handles all builder actions from the parent form root.
   */
  public function handleAction(array &$form, FormStateInterface $form_state, NodeInterface $event): void {
    if (!$this->canManageEvent($event)) {
      $this->messenger->addError($this->t('You do not have access to manage this event.'));
      $this->logFormRebuildContext('EventTicketsBuilder::handleAction canManageEvent');
      \Drupal::logger('mel_debug')->notice('FORM REBUILD TRIGGERED: EventTicketsBuilder::handleAction (canManageEvent)');
      $form_state->setRebuild();
      return;
    }

    $trigger = $form_state->getTriggeringElement();
    $name = (string) ($trigger['#name'] ?? '');

    if ($name === '') {
      $this->loggerFactory->get('myeventlane_vendor')->warning('Ticket builder action triggered without #name.');
      $this->logFormRebuildContext('EventTicketsBuilder::handleAction empty #name');
      \Drupal::logger('mel_debug')->notice('FORM REBUILD TRIGGERED: EventTicketsBuilder::handleAction (empty #name)');
      $form_state->setRebuild();
      return;
    }

    try {
      match (TRUE) {
        str_starts_with($name, 'ticket_begin_preset_') => $this->beginAddWithPreset($form_state, $event, $name),
        $name === 'ticket_begin_add' => $this->beginAdd($form_state),
        $name === 'ticket_begin_add_rsvp' => $this->beginAddWithKind($form_state, 'rsvp'),
        $name === 'ticket_begin_add_paid' => $this->beginAddWithKind($form_state, 'paid'),
        $name === 'ticket_begin_add_external' => $this->beginAddWithKind($form_state, 'external'),
        $name === 'ticket_cancel_add' => $this->cancelAdd($form_state),
        $name === 'ticket_create' => $this->createTicket($form_state, $event),
        $name === 'ticket_save_sync' => $this->performSaveAndSync($form_state, $event),
        $name === 'ticket_suggestions_dismiss' => $this->dismissTicketSuggestions($form_state),
        $name === 'ticket_reorder' => $this->reorderTickets($form_state, $event),
        str_starts_with($name, 'edit_') => $this->beginInlineEdit($form_state, $name),
        str_starts_with($name, 'cancel_') => $this->cancelInlineEdit($form_state),
        str_starts_with($name, 'save_') => $this->saveInlineEdit($form, $form_state, $event, $name),
        str_starts_with($name, 'ticket_remove_') => $this->removeTicket($event, $name),
        str_starts_with($name, 'ticket_duplicate_') => $this->duplicateTicket($event, $name),
        str_starts_with($name, 'ticket_archive_') => $this->archiveTicket($event, $name),
        default => NULL,
      };
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_vendor')->error(
        'Ticket builder action @action failed for event @nid: @message',
        [
          '@action' => $name,
          '@nid' => (string) $event->id(),
          '@message' => $e->getMessage(),
        ]
      );
      if ($this->isCurrencyMismatchException($e)) {
        $this->messenger->addError($this->t(TicketTierLifecycleService::CURRENCY_MISMATCH_MESSAGE));
      }
      else {
        $this->messenger->addError($this->t('Ticket update failed: @message', ['@message' => $e->getMessage()]));
      }
    }

    if ($event->id()) {
      $this->lifecycle->reconcileEventTicketReferences($event);
    }

    // Full form rebuild so EventFormAlter can re-apply ticket-driven #access on ops fields.
    $this->logFormRebuildContext('EventTicketsBuilder::handleAction after match');
    \Drupal::logger('mel_debug')->notice('FORM REBUILD TRIGGERED: EventTicketsBuilder::handleAction (after action match, trigger=@t)', [
      '@t' => $name,
    ]);
    $form_state->setRebuild();
  }

  /**
   * New ticket card.
   */
  private function buildNewTicketCard(NodeInterface $event, FormStateInterface $form_state): array {
    $currency = $this->ticketTypeManager->getDefaultCurrencyCodeForEvent($event);

    $prefill = (string) ($form_state->get('mel_ticket_prefill_kind') ?: 'paid');
    $preset_key = (string) ($form_state->get('mel_ticket_selected_preset') ?: 'custom');
    $title_default = (string) $this->newTicketDefault($form_state, 'title', '');
    $kind_default = (string) $this->newTicketDefault($form_state, 'ticket_kind', $prefill);
    $price_default = (string) $this->newTicketDefault($form_state, 'price_amount', '');
    $capacity_default = $this->newTicketDefault($form_state, 'capacity', '');
    $visibility_default = (string) $this->newTicketDefault($form_state, 'visibility_mode', 'public');
    $status_default = (int) $this->newTicketDefault($form_state, 'status', 1);
    $recommended_default = (int) $this->newTicketDefault($form_state, 'field_is_default_ticket', 0);
    $best_value_default = (int) $this->newTicketDefault($form_state, 'field_is_best_value', 0);

    $ticket_kind_input = ':input[name="' . $this->formElementFullName($form_state, 'builder_shell', 'list', 'new', 'fields', 'ticket_kind') . '"]';

    $preview_title = trim((string) $title_default);
    $display_heading = $preview_title !== '' ? $preview_title : (string) $this->t('New ticket');
    $price_caption_default = $this->newTicketOutcomePriceCaption((string) $kind_default, (string) $price_default, (string) $currency);

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-card', 'mel-ticket-card', 'mel-ticket-card--new', 'is-editing'],
        'data-mel-ticket-kind' => $kind_default,
        'data-mel-ticket-selected-preset' => $preset_key,
      ],
      'display_preview' => [
        '#markup' => '<div class="mel-ticket-card__display mel-ticket-card__display--new" data-mel-ticket-outcome-display="1">'
          . '<h4 class="mel-ticket-card__display-title" data-mel-ticket-display-title>' . Html::escape($display_heading) . '</h4>'
          . '<span class="mel-ticket-card__price" data-mel-ticket-display-price>' . Html::escape($price_caption_default) . '</span>'
          . '</div>',
      ],
      'fields' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-ticket-card__fields', 'mel-ticket-card__edit-layer']],
        'ticket_kind' => [
          '#type' => 'select',
          '#title' => $this->t('Type'),
          '#options' => [
            'paid' => $this->t('Paid'),
            'rsvp' => $this->t('RSVP'),
            'external' => $this->t('External'),
          ],
          '#default_value' => $kind_default,
        ],
        'row_title_price' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-ticket-card__fields-inline-2']],
          'title' => [
            '#type' => 'textfield',
            '#title' => $this->t('Title'),
            '#default_value' => $title_default,
            '#wrapper_attributes' => [
              'class' => ['mel-ticket-card__field--title'],
            ],
            '#attributes' => [
              'class' => ['js-mel-ticket-title'],
              'data-mel-ticket-required-message' => (string) $this->t('Title is required.'),
            ],
          ],
          'price_amount' => [
            '#type' => 'number',
            '#title' => $this->t('Price'),
            '#step' => 0.01,
            '#min' => 0,
            '#default_value' => $price_default,
            '#wrapper_attributes' => [
              'class' => ['mel-ticket-card__field--price'],
            ],
            '#attributes' => [
              'data-mel-ticket-preset-focus' => 'price',
              'class' => ['js-mel-ticket-price'],
            ],
            '#states' => [
              'visible' => [
                $ticket_kind_input => ['value' => 'paid'],
              ],
            ],
          ],
        ],
        'price_currency' => [
          '#type' => 'textfield',
          '#title' => $this->t('Currency'),
          '#size' => 4,
          '#maxlength' => 3,
          '#default_value' => (string) ($form_state->getValue($this->valuePath($form_state, 'builder_shell', 'list', 'new', 'fields', 'price_currency')) ?? $currency),
          '#states' => [
            'visible' => [
              $ticket_kind_input => ['value' => 'paid'],
            ],
          ],
        ],
        'short_description' => [
          '#type' => 'textarea',
          '#title' => $this->t('Short description (buyers)'),
          '#description' => $this->t('Optional. About two lines — shown on the public ticket picker.'),
          '#rows' => 2,
          '#maxlength' => self::SHORT_DESCRIPTION_MAX_LENGTH,
          '#default_value' => (string) ($form_state->getValue($this->valuePath($form_state, 'builder_shell', 'list', 'new', 'fields', 'short_description')) ?? ''),
        ],
        'capacity' => [
          '#type' => 'number',
          '#title' => $this->t('Capacity'),
          '#min' => 1,
          '#default_value' => $capacity_default === NULL ? '' : (string) $capacity_default,
          '#description' => $this->t('Leave empty for unlimited tickets'),
          '#attributes' => [
            'class' => ['js-mel-ticket-capacity'],
            'placeholder' => (string) $this->t('Leave empty for unlimited tickets'),
          ],
          // Must use core #states "or" shape; nesting "or" inside the selector value breaks
          // drupal.states.js so RSVP capacity never shows/submits and create fails validation.
          '#states' => [
            'visible' => [
              'or' => [
                [$ticket_kind_input => ['value' => 'paid']],
                [$ticket_kind_input => ['value' => 'rsvp']],
              ],
            ],
          ],
        ],
        'external_uri' => [
          '#type' => 'url',
          '#title' => $this->t('External URL'),
          '#default_value' => (string) ($form_state->getValue($this->valuePath($form_state, 'builder_shell', 'list', 'new', 'fields', 'external_uri')) ?? ''),
          '#states' => [
            'visible' => [
              $ticket_kind_input => ['value' => 'external'],
            ],
          ],
        ],
        'status' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Published (visible to buyers)'),
          '#default_value' => $status_default,
        ],
        'visibility_mode' => [
          '#type' => 'hidden',
          '#default_value' => $visibility_default,
        ],
        'field_is_default_ticket' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Set as recommended ticket'),
          '#description' => $this->t('This ticket will be highlighted to buyers and selected by default.'),
          '#description_display' => 'after',
          '#default_value' => $recommended_default,
        ],
        'field_is_best_value' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Mark as best value'),
          '#description' => $this->t('Shows a Best value badge to buyers. Only one ticket per event can be marked best value.'),
          '#description_display' => 'after',
          '#default_value' => $best_value_default,
        ],
        'sale_window_enabled' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Set sale window'),
          '#description' => $this->t('Schedule when this tier can be purchased (optional).'),
          '#default_value' => (int) ($form_state->getValue($this->valuePath($form_state, 'builder_shell', 'list', 'new', 'fields', 'sale_window_enabled')) ?? 0),
        ],
        'sale_start' => [
          '#type' => 'datetime',
          '#title' => $this->t('Sale start'),
          '#date_increment' => 15,
          '#default_value' => $form_state->getValue($this->valuePath($form_state, 'builder_shell', 'list', 'new', 'fields', 'sale_start')) ?? NULL,
          '#states' => [
            'visible' => [
              ':input[name="' . $this->formElementFullName($form_state, 'builder_shell', 'list', 'new', 'fields', 'sale_window_enabled') . '"]' => ['checked' => TRUE],
            ],
          ],
        ],
        'sale_end' => [
          '#type' => 'datetime',
          '#title' => $this->t('Sale end'),
          '#description' => $this->t('Must be after sale start.'),
          '#date_increment' => 15,
          '#default_value' => $form_state->getValue($this->valuePath($form_state, 'builder_shell', 'list', 'new', 'fields', 'sale_end')) ?? NULL,
          '#states' => [
            'visible' => [
              ':input[name="' . $this->formElementFullName($form_state, 'builder_shell', 'list', 'new', 'fields', 'sale_window_enabled') . '"]' => ['checked' => TRUE],
            ],
          ],
        ],
      ],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-ticket-card__actions']],
        'create' => [
          '#type' => 'submit',
          '#value' => $this->t('Create ticket'),
          '#name' => 'ticket_create',
          '#submit' => ['::handleAction'],
          '#ajax' => [
            'callback' => '::ajaxRebuildTicketBuilder',
            'wrapper' => EventTicketsBuilder::BUILDER_WRAPPER_ID,
          ],
          '#limit_validation_errors' => [$this->valuePath($form_state, 'builder_shell', 'list', 'new', 'fields')],
          '#attributes' => [
            'class' => ['mel-btn', 'mel-btn--primary', 'js-mel-ticket-create'],
            'formnovalidate' => 'formnovalidate',
          ],
        ],
        'cancel' => [
          '#type' => 'submit',
          '#value' => $this->t('Cancel'),
          '#name' => 'ticket_cancel_add',
          '#submit' => ['::handleAction'],
          '#ajax' => [
            'callback' => '::ajaxRebuildTicketBuilder',
            'wrapper' => EventTicketsBuilder::BUILDER_WRAPPER_ID,
          ],
          '#limit_validation_errors' => [],
          '#attributes' => [
            'class' => ['mel-btn', 'mel-btn--ghost'],
            'formnovalidate' => 'formnovalidate',
          ],
        ],
      ],
    ];
  }

  /**
   * Appends read-only ticket card elements to the card container (view mode).
   *
   * @param array<string, mixed> $card
   *   The Form API subtree for one ticket row (already contains drag_handle).
   */
  private function appendViewTicketCardElements(array &$card, TicketTypeInterface $ticket, FormStateInterface $form_state): void {
    $tid = (int) $ticket->id();
    $ticket_status = $this->ticketStatus->getStatus($ticket);
    $status_label = $this->ticketCardStatusLabel($ticket_status);
    $status_class = $this->ticketCardStatusClassSuffix($ticket_status);
    $capacity_line = $this->formatCapacityText($ticket);
    $visibility_label = $this->ticketVisibilityLabel($ticket);

    $card['view'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-ticket-card__view'],
        'data-mel-ticket-view' => '1',
      ],
    ];
    $view = &$card['view'];

    $badge_cluster = '<span class="mel-ticket-card__state" data-mel-ticket-state aria-live="polite"></span>'
      . ($ticket->hasField('field_is_default_ticket') && $ticket->isDefaultTicket()
        ? '<span class="mel-ticket-card__recommended-badge">' . Html::escape($this->recommendedTicketBadgeLabel($ticket)) . '</span>'
        : ($ticket->hasField('field_is_best_value') && $ticket->isBestValueTicket()
          ? '<span class="mel-ticket-card__recommended-badge mel-ticket-card__best-value-badge">' . Html::escape($this->bestValueTicketBadgeLabel($ticket)) . '</span>'
          : ''))
      . '<span class="mel-badge mel-badge--' . Html::escape($status_class) . '">' . Html::escape($status_label) . '</span>';

    $view['hero'] = [
      '#markup' => '<div class="mel-ticket-card__header mel-ticket-card__header--outcome">'
        . '<div class="mel-ticket-card__display">'
        . '<h4 class="mel-ticket-card__display-title">' . Html::escape($ticket->label()) . '</h4>'
        . '<span class="mel-ticket-card__price">' . Html::escape($this->formatPriceText($ticket)) . '</span>'
        . '</div>'
        . '<div class="mel-ticket-card__header-actions">' . $badge_cluster . '</div>'
        . '</div>',
    ];

    $view['meta'] = [
      '#markup' => '<div class="' . Html::escape('mel-ticket-card__meta-line') . '">' . Html::escape($capacity_line) . '</div>',
    ];

    $view['visibility'] = [
      '#markup' => '<div class="' . Html::escape('mel-ticket-card__visibility') . '">' . Html::escape($visibility_label) . '</div>',
    ];

    $sale_line = $this->buildTicketSaleWindowSummaryMarkup($ticket);
    if ($sale_line !== NULL) {
      $view['sale_window'] = [
        '#markup' => '<div class="' . Html::escape('mel-ticket-card__sale-window') . '" role="status">' . Html::escape($sale_line) . '</div>',
      ];
    }

    $archive_only = in_array($ticket_status, [
      TicketStatusService::STATUS_ENDED,
      TicketStatusService::STATUS_ARCHIVED,
    ], TRUE);

    $view['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-ticket-card__actions']],
      'edit' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => $this->t('Edit'),
        '#attributes' => [
          'type' => 'button',
          'class' => ['mel-btn', 'mel-btn--secondary', 'js-mel-ticket-edit-toggle'],
          'data-mel-ticket-edit-toggle' => '1',
          'aria-expanded' => 'false',
        ],
        '#access' => !$archive_only,
      ],
      'duplicate' => [
        '#type' => 'submit',
        '#value' => $this->t('Duplicate'),
        '#name' => 'ticket_duplicate_' . $tid,
        '#submit' => ['::handleAction'],
        '#ajax' => [
          'callback' => '::ajaxRebuildTicketBuilder',
          'wrapper' => EventTicketsBuilder::BUILDER_WRAPPER_ID,
        ],
        '#limit_validation_errors' => [],
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn--secondary'],
          'formnovalidate' => 'formnovalidate',
        ],
        '#access' => !$archive_only,
      ],
      'archive' => [
        '#type' => 'submit',
        '#value' => $this->t('Archive'),
        '#name' => 'ticket_archive_' . $tid,
        '#submit' => ['::handleAction'],
        '#ajax' => [
          'callback' => '::ajaxRebuildTicketBuilder',
          'wrapper' => EventTicketsBuilder::BUILDER_WRAPPER_ID,
        ],
        '#limit_validation_errors' => [],
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn--danger'],
          'formnovalidate' => 'formnovalidate',
        ],
      ],
    ];
  }

  /**
   * Appends the inline edit form to the card so JS can open it immediately.
   *
   * @param array<string, mixed> $card
   *   The Form API subtree for one ticket row.
   */
  private function appendEditTicketCardElements(array &$card, TicketTypeInterface $ticket, FormStateInterface $form_state): void {
    $tid = (int) $ticket->id();
    $edit_path = $this->valuePath($form_state, 'builder_shell', 'list', (string) $tid, 'edit');
    $ticket_status = $this->ticketStatus->getStatus($ticket);
    $edit_badge_class = $this->ticketCardStatusClassSuffix($ticket_status);
    $edit_status_label = $this->ticketCardStatusLabel($ticket_status);

    $card['edit'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-ticket-edit'],
        'data-mel-ticket-edit' => '1',
      ],
    ];

    $badge_edit = '<span class="mel-ticket-card__state" data-mel-ticket-state aria-live="polite"></span>'
      . ($ticket->hasField('field_is_default_ticket') && $ticket->isDefaultTicket()
        ? '<span class="mel-ticket-card__recommended-badge">' . Html::escape($this->recommendedTicketBadgeLabel($ticket)) . '</span>'
        : ($ticket->hasField('field_is_best_value') && $ticket->isBestValueTicket()
          ? '<span class="mel-ticket-card__recommended-badge mel-ticket-card__best-value-badge">' . Html::escape($this->bestValueTicketBadgeLabel($ticket)) . '</span>'
          : ''))
      . '<span class="mel-badge mel-badge--' . Html::escape($edit_badge_class) . '">' . Html::escape($edit_status_label) . '</span>';

    $card['edit']['outcome_heading'] = [
      '#markup' => '<div class="mel-ticket-card__header mel-ticket-card__header--outcome mel-ticket-card__header--editable">'
        . '<div class="mel-ticket-card__display" data-mel-ticket-outcome-display="1">'
        . '<h4 class="mel-ticket-card__display-title">' . Html::escape($ticket->label()) . '</h4>'
        . '<span class="mel-ticket-card__price" data-mel-ticket-display-price>' . Html::escape($this->formatPriceText($ticket)) . '</span>'
        . '</div>'
        . '<p class="mel-ticket-card__kind-hint">' . Html::escape(ucfirst($ticket->getTicketKind())) . '</p>'
        . '<div class="mel-ticket-card__header-actions">' . $badge_edit . '</div>'
        . '</div>',
      '#weight' => -30,
    ];

    $card['edit']['primary'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-ticket-card__fields', 'mel-ticket-card__fields--primary', 'mel-ticket-card__edit-layer'],
      ],
      '#weight' => -10,
    ];

    $card['edit']['primary']['pair'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-ticket-card__fields-inline-2']],
    ];

    $card['edit']['primary']['pair']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => (string) ($form_state->getValue(array_merge($edit_path, ['title'])) ?? $ticket->label()),
      '#required' => TRUE,
      '#parents' => array_merge($edit_path, ['title']),
      '#wrapper_attributes' => [
        'class' => ['mel-ticket-card__field--title'],
      ],
      '#attributes' => [
        'class' => ['js-mel-ticket-title'],
        'data-mel-ticket-required-message' => (string) $this->t('Title is required.'),
      ],
    ];

    if ($ticket->getTicketKind() === 'paid') {
      $price = $ticket->toPriceValue();

      $card['edit']['primary']['pair']['price'] = [
        '#type' => 'number',
        '#title' => $this->t('Price'),
        '#default_value' => $price ? $price->getNumber() : 0,
        '#step' => 0.01,
        '#min' => 0.01,
        '#required' => TRUE,
        '#parents' => array_merge($edit_path, ['price']),
        '#wrapper_attributes' => [
          'class' => ['mel-ticket-card__field--price'],
        ],
        '#attributes' => [
          'class' => ['js-mel-ticket-price'],
        ],
      ];
    }

    $capacity_default = (string) ($form_state->getValue(array_merge($edit_path, ['capacity']))
      ?? (!$ticket->get('capacity')->isEmpty() ? (int) $ticket->get('capacity')->value : ''));
    if ($capacity_default === '0') {
      $capacity_default = '';
    }
    $card['edit']['primary']['capacity'] = [
      '#type' => 'number',
      '#title' => $this->t('Capacity'),
      '#min' => in_array($ticket->getTicketKind(), ['paid', 'rsvp'], TRUE) ? 1 : 0,
      '#default_value' => $capacity_default,
      '#description' => $this->t('Leave empty for unlimited tickets'),
      '#parents' => array_merge($edit_path, ['capacity']),
      '#attributes' => [
        'class' => ['js-mel-ticket-capacity'],
        'placeholder' => (string) $this->t('Leave empty for unlimited tickets'),
      ],
    ];

    $card['edit']['primary']['short_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Short description (buyers)'),
      '#description' => $this->t('Optional. About two lines — shown on the public ticket picker.'),
      '#rows' => 2,
      '#maxlength' => self::SHORT_DESCRIPTION_MAX_LENGTH,
      '#default_value' => (string) ($form_state->getValue(array_merge($edit_path, ['short_description']))
        ?? ($ticket->hasField('short_description') ? (string) ($ticket->get('short_description')->value ?? '') : '')),
      '#parents' => array_merge($edit_path, ['short_description']),
    ];

    $sale_window_checked = $this->ticketSaleWindowDefaultChecked($ticket);
    $sale_win_chk = ':input[name="' . $this->formElementFullName($form_state, 'builder_shell', 'list', (string) $tid, 'edit', 'sale_window_enabled') . '"]';

    $sale_start_default = $this->ticketSaleDatetimeDefault($ticket, 'sale_start');
    $sale_end_default = $this->ticketSaleDatetimeDefault($ticket, 'sale_end');

    $card['edit']['primary']['sale_window_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Set sale window'),
      '#description' => $this->t('When off, this tier follows default availability rules (no custom start/end on the ticket).'),
      '#default_value' => (int) ($form_state->getValue(array_merge($edit_path, ['sale_window_enabled'])) ?? ($sale_window_checked ? 1 : 0)),
      '#parents' => array_merge($edit_path, ['sale_window_enabled']),
    ];

    $card['edit']['primary']['sale_start'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Sale start'),
      '#description' => $this->t('First moment this tier can be purchased.'),
      '#date_increment' => 15,
      '#default_value' => $form_state->getValue(array_merge($edit_path, ['sale_start'])) ?? $sale_start_default,
      '#parents' => array_merge($edit_path, ['sale_start']),
      '#states' => [
        'visible' => [
          $sale_win_chk => ['checked' => TRUE],
        ],
      ],
    ];

    $card['edit']['primary']['sale_end'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Sale end'),
      '#description' => $this->t('Last moment this tier can be purchased (must be after start).'),
      '#date_increment' => 15,
      '#default_value' => $form_state->getValue(array_merge($edit_path, ['sale_end'])) ?? $sale_end_default,
      '#parents' => array_merge($edit_path, ['sale_end']),
      '#states' => [
        'visible' => [
          $sale_win_chk => ['checked' => TRUE],
        ],
      ],
    ];

    $card['edit']['secondary'] = [
      '#type' => 'details',
      '#title' => $this->t('Secondary settings'),
      '#open' => FALSE,
      '#attributes' => ['class' => ['mel-ticket-edit__secondary']],
    ];

    $card['edit']['secondary']['status_published'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Published (active)'),
      '#description' => $this->t('When unchecked, the ticket is unpublished (inactive) for buyers.'),
      '#default_value' => (int) ($form_state->getValue(array_merge($edit_path, ['status_published'])) ?? ($ticket->isPublished() ? 1 : 0)),
      '#parents' => array_merge($edit_path, ['status_published']),
    ];
    $card['edit']['secondary']['field_is_default_ticket'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Set as recommended ticket'),
      '#description' => $this->t('This ticket will be highlighted to buyers and selected by default.'),
      '#description_display' => 'after',
      '#default_value' => (int) ($form_state->getValue(array_merge($edit_path, ['field_is_default_ticket']))
        ?? ($ticket->hasField('field_is_default_ticket') && $ticket->isDefaultTicket() ? 1 : 0)),
      '#parents' => array_merge($edit_path, ['field_is_default_ticket']),
    ];
    $card['edit']['secondary']['field_is_best_value'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Mark as best value'),
      '#description' => $this->t('Shows a Best value badge to buyers. Only one ticket per event can be marked best value.'),
      '#description_display' => 'after',
      '#default_value' => (int) ($form_state->getValue(array_merge($edit_path, ['field_is_best_value']))
        ?? ($ticket->hasField('field_is_best_value') && $ticket->isBestValueTicket() ? 1 : 0)),
      '#parents' => array_merge($edit_path, ['field_is_best_value']),
    ];

    $vis_default = (string) ($form_state->getValue(array_merge($edit_path, ['visibility_mode']))
      ?? ($ticket->hasField('visibility_mode') && !$ticket->get('visibility_mode')->isEmpty()
        ? (string) $ticket->get('visibility_mode')->value
        : 'public'));
    $visibility_options = [
      'public' => $this->t('Public'),
      'hidden' => $this->t('Private'),
      'access_code' => $this->t('Private — access code'),
      'group_only' => $this->t('Private — organiser team'),
    ];
    $card['edit']['secondary']['visibility_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Visibility'),
      '#options' => $visibility_options,
      '#default_value' => $vis_default,
      '#parents' => array_merge($edit_path, ['visibility_mode']),
    ];
    $card['edit']['secondary']['waitlist_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable tier waitlist when sold out'),
      '#description' => $this->isUnlimitedCapacity($ticket)
        ? $this->t('Waitlist is only used when tickets sell out')
        : '',
      '#default_value' => $this->isUnlimitedCapacity($ticket)
        ? 0
        : (int) ($form_state->getValue(array_merge($edit_path, ['waitlist_enabled']))
          ?? ($ticket->hasField('waitlist_enabled') && $ticket->get('waitlist_enabled')->value ? 1 : 0)),
      '#disabled' => $this->isUnlimitedCapacity($ticket),
      '#parents' => array_merge($edit_path, ['waitlist_enabled']),
    ];
    $card['edit']['secondary']['waitlist_capacity'] = [
      '#type' => 'number',
      '#title' => $this->t('Waitlist capacity'),
      '#min' => 0,
      '#default_value' => $this->isUnlimitedCapacity($ticket)
        ? ''
        : (string) ($form_state->getValue(array_merge($edit_path, ['waitlist_capacity']))
          ?? ($ticket->hasField('waitlist_capacity') && !$ticket->get('waitlist_capacity')->isEmpty()
            ? (string) (int) $ticket->get('waitlist_capacity')->value
            : '')),
      '#disabled' => $this->isUnlimitedCapacity($ticket),
      '#parents' => array_merge($edit_path, ['waitlist_capacity']),
    ];
    $card['edit']['secondary']['auto_promote_waitlist'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-offer waitlist when tickets free up'),
      '#default_value' => $this->isUnlimitedCapacity($ticket)
        ? 0
        : (int) ($form_state->getValue(array_merge($edit_path, ['auto_promote_waitlist']))
          ?? ($ticket->hasField('auto_promote_waitlist') && $ticket->get('auto_promote_waitlist')->value ? 1 : 0)),
      '#disabled' => $this->isUnlimitedCapacity($ticket),
      '#parents' => array_merge($edit_path, ['auto_promote_waitlist']),
    ];
    $group_mode_default = (string) ($form_state->getValue(array_merge($edit_path, ['group_sale_mode']))
      ?? ($ticket->hasField('group_sale_mode') && !$ticket->get('group_sale_mode')->isEmpty()
        ? (string) $ticket->get('group_sale_mode')->value
        : 'none'));
    $card['edit']['secondary']['group_sale_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Group rules'),
      '#options' => [
        'none' => $this->t('None'),
        'fixed_bundle' => $this->t('Fixed bundle'),
        'minimum_group_size' => $this->t('Minimum group size'),
        'reserved_block' => $this->t('Reserved block / partner'),
      ],
      '#default_value' => $group_mode_default,
      '#description' => $this->t('Quantity rules are enforced at checkout.'),
      '#parents' => array_merge($edit_path, ['group_sale_mode']),
    ];
    $card['edit']['secondary']['group_min_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum group size'),
      '#min' => 0,
      '#default_value' => (string) ($form_state->getValue(array_merge($edit_path, ['group_min_size']))
        ?? ($ticket->hasField('group_min_size') && !$ticket->get('group_min_size')->isEmpty()
          ? (string) (int) $ticket->get('group_min_size')->value
          : '')),
      '#parents' => array_merge($edit_path, ['group_min_size']),
    ];
    $card['edit']['secondary']['group_bundle_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Bundle / block size'),
      '#min' => 0,
      '#default_value' => (string) ($form_state->getValue(array_merge($edit_path, ['group_bundle_size']))
        ?? ($ticket->hasField('group_bundle_size') && !$ticket->get('group_bundle_size')->isEmpty()
          ? (string) (int) $ticket->get('group_bundle_size')->value
          : '')),
      '#parents' => array_merge($edit_path, ['group_bundle_size']),
    ];
    $card['edit']['secondary']['hidden_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Internal label (optional)'),
      '#default_value' => (string) ($form_state->getValue(array_merge($edit_path, ['hidden_label']))
        ?? ($ticket->hasField('hidden_label') ? (string) ($ticket->get('hidden_label')->value ?? '') : '')),
      '#parents' => array_merge($edit_path, ['hidden_label']),
    ];

    $toggle_selector = ':input[name="' . Html::escape($this->formElementFullName($form_state, 'builder_shell', 'list', (string) $tid, 'edit', 'field_use_ticket_attendee_questions', '0', 'value')) . '"]';

    $card['edit']['mel_ticket_questions_divider'] = [
      '#markup' => '<hr class="mel-ticket-questions__divider" aria-hidden="true" />',
      '#weight' => 38,
      '#states' => [
        'visible' => [
          $toggle_selector => ['checked' => TRUE],
        ],
      ],
    ];

    $questions_subform = ['#parents' => $edit_path];
    $display = EntityFormDisplay::collectRenderDisplay($ticket, 'default');
    if ($ticket->hasField('field_use_ticket_attendee_questions')
      && ($w_toggle = $display->getRenderer('field_use_ticket_attendee_questions'))) {
      $card['edit']['field_use_ticket_attendee_questions'] = $w_toggle->form(
        $ticket->get('field_use_ticket_attendee_questions'),
        $questions_subform,
        $form_state
      );
      $card['edit']['field_use_ticket_attendee_questions']['#weight'] = 39;
    }
    if ($ticket->hasField('field_attendee_questions')
      && ($w_questions = $display->getRenderer('field_attendee_questions'))) {
      $card['edit']['mel_ticket_questions_intro'] = [
        '#markup' => '<div class="mel-ticket-questions"><h5>' . Html::escape((string) $this->t('Attendee questions')) . '</h5>'
          . '<p class="description">' . Html::escape((string) $this->t('Add questions specific to this ticket. Event-level questions always apply first.')) . '</p></div>',
        '#weight' => 40,
        '#states' => [
          'visible' => [
            $toggle_selector => ['checked' => TRUE],
          ],
        ],
      ];
      $card['edit']['field_attendee_questions'] = $w_questions->form(
        $ticket->get('field_attendee_questions'),
        $questions_subform,
        $form_state
      );
      $card['edit']['field_attendee_questions']['#weight'] = 41;
      $card['edit']['field_attendee_questions']['#states'] = [
        'visible' => [
          $toggle_selector => ['checked' => TRUE],
        ],
      ];
    }

    $card['edit']['validation'] = [
      '#markup' => '<div class="mel-ticket-card__validation" data-mel-ticket-validation aria-live="polite"></div>',
    ];

    $card['edit']['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-ticket-card__actions', 'mel-ticket-edit__actions']],
    ];

    $limit_edit = [$this->valuePath($form_state, 'builder_shell', 'list', (string) $tid, 'edit')];

    $card['edit']['actions']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save changes'),
      '#name' => 'save_' . $tid,
      '#submit' => ['::handleAction'],
      '#ajax' => [
        'callback' => '::ajaxRebuildTicketBuilder',
        'wrapper' => self::BUILDER_WRAPPER_ID,
      ],
      '#limit_validation_errors' => $limit_edit,
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn--primary', 'js-mel-ticket-save'],
        'formnovalidate' => 'formnovalidate',
      ],
    ];

    $card['edit']['actions']['cancel'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $this->t('Cancel'),
      '#attributes' => [
        'type' => 'button',
        'class' => ['mel-btn', 'mel-btn--secondary', 'js-mel-ticket-cancel'],
        'data-mel-ticket-cancel' => '1',
      ],
    ];

    $card['edit']['actions']['duplicate'] = [
      '#type' => 'submit',
      '#value' => $this->t('Duplicate'),
      '#name' => 'ticket_duplicate_' . $tid,
      '#submit' => ['::handleAction'],
      '#ajax' => [
        'callback' => '::ajaxRebuildTicketBuilder',
        'wrapper' => self::BUILDER_WRAPPER_ID,
      ],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn--secondary'],
        'formnovalidate' => 'formnovalidate',
      ],
    ];

    $card['edit']['actions']['archive'] = [
      '#type' => 'submit',
      '#value' => $this->t('Archive'),
      '#name' => 'ticket_archive_' . $tid,
      '#submit' => ['::handleAction'],
      '#ajax' => [
        'callback' => '::ajaxRebuildTicketBuilder',
        'wrapper' => self::BUILDER_WRAPPER_ID,
      ],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn--danger'],
        'formnovalidate' => 'formnovalidate',
      ],
    ];
  }

  private function beginAdd(FormStateInterface $form_state): void {
    $form_state->set('mel_ticket_prefill_kind', NULL);
    $form_state->set('mel_ticket_selected_preset', NULL);
    $form_state->set('mel_ticket_preset_defaults', NULL);
    $form_state->set('mel_ticket_adding_new', TRUE);
    $form_state->set('editing_ticket_id', NULL);
  }

  private function beginAddWithPreset(FormStateInterface $form_state, NodeInterface $event, string $name): void {
    $preset_key = substr($name, strlen('ticket_begin_preset_'));
    $preset = $this->ticketPresetManager->getPreset($preset_key);
    if ($preset === NULL) {
      $this->loggerFactory->get('myeventlane_vendor')->warning('Unknown ticket preset @preset requested for event @nid.', [
        '@preset' => $preset_key,
        '@nid' => (string) $event->id(),
      ]);
      $preset_key = 'custom';
      $preset = $this->ticketPresetManager->getPreset('custom') ?? [
        'label' => 'Custom',
        'description' => 'Start from scratch',
        'values' => [],
      ];
    }

    $existing_tickets = $this->lifecycle->loadOrderedTicketsForEvent($event);
    $defaults = $this->normalizePresetValuesForNewTicket($preset['values']);
    if ($preset_key === 'general' && $existing_tickets === []) {
      $defaults['field_is_default_ticket'] = 1;
    }

    $form_state->set('mel_ticket_selected_preset', $preset_key);
    $form_state->set('mel_ticket_preset_defaults', $defaults);
    $form_state->set('mel_ticket_prefill_kind', (string) ($defaults['ticket_kind'] ?? 'paid'));
    $form_state->set('mel_ticket_adding_new', TRUE);
    $form_state->set('editing_ticket_id', NULL);
  }

  /**
   * Opens the new-ticket card with a suggested tier kind (still editable).
   */
  private function beginAddWithKind(FormStateInterface $form_state, string $kind): void {
    $allowed = ['rsvp', 'paid', 'external'];
    $form_state->set('mel_ticket_prefill_kind', in_array($kind, $allowed, TRUE) ? $kind : 'paid');
    $form_state->set('mel_ticket_selected_preset', NULL);
    $form_state->set('mel_ticket_preset_defaults', NULL);
    $form_state->set('mel_ticket_adding_new', TRUE);
    $form_state->set('editing_ticket_id', NULL);
  }

  private function cancelAdd(FormStateInterface $form_state): void {
    $form_state->set('mel_ticket_adding_new', FALSE);
    $form_state->set('mel_ticket_prefill_kind', NULL);
    $form_state->set('mel_ticket_selected_preset', NULL);
    $form_state->set('mel_ticket_preset_defaults', NULL);
  }

  private function dismissTicketSuggestions(FormStateInterface $form_state): void {
    $form_state->set('mel_ticket_suggestions_dismissed', TRUE);
  }

  private function beginInlineEdit(FormStateInterface $form_state, string $name): void {
    $tid = (int) str_replace('edit_', '', $name);
    $form_state->set('editing_ticket_id', $tid);
    $form_state->set('mel_ticket_adding_new', FALSE);
    $form_state->set('mel_ticket_selected_preset', NULL);
    $form_state->set('mel_ticket_preset_defaults', NULL);
  }

  private function cancelInlineEdit(FormStateInterface $form_state): void {
    $form_state->set('editing_ticket_id', NULL);
  }

  private function createTicket(FormStateInterface $form_state, NodeInterface $event, bool $quiet = FALSE): void {
    $values = $form_state->getValue($this->valuePath($form_state, 'builder_shell', 'list', 'new', 'fields')) ?? [];
    $payload = $this->lifecycle->buildTicketValuesFromInput($event, $this->currentUser, $values);
    $payload = array_merge($payload, $this->buildSaleWindowFragmentForLifecycle($values));
    $this->lifecycle->createAttachAndSync($event, $payload);
    $form_state->set('mel_ticket_show_suggestions', TRUE);
    if (!$quiet) {
      $this->messenger->addStatus($this->t('Ticket created.'));
    }
    $form_state->set('mel_ticket_adding_new', FALSE);
    $form_state->set('mel_ticket_prefill_kind', NULL);
    $form_state->set('mel_ticket_selected_preset', NULL);
    $form_state->set('mel_ticket_preset_defaults', NULL);
  }

  /**
   * Syncs Commerce for paid tiers; if a new-tier form is open with a title, saves it first.
   *
   * Vendors historically used this path as a shorthand for create + commerce sync.
   */
  private function performSaveAndSync(FormStateInterface $form_state, NodeInterface $event): void {
    if ($form_state->get('mel_ticket_adding_new')) {
      $values = $form_state->getValue($this->valuePath($form_state, 'builder_shell', 'list', 'new', 'fields')) ?? [];
      $title = trim((string) ($values['title'] ?? ''));
      if ($title !== '') {
        try {
          $this->createTicket($form_state, $event, TRUE);
          $this->messenger->addStatus($this->t('Tickets saved and synced.'));
        }
        catch (\InvalidArgumentException $e) {
          if ($this->isCurrencyMismatchException($e)) {
            $this->messenger->addError($this->t(TicketTierLifecycleService::CURRENCY_MISMATCH_MESSAGE));
          }
          else {
            $this->messenger->addError($this->t('Could not save ticket: @message', ['@message' => $e->getMessage()]));
          }
        }
        return;
      }
      $this->messenger->addWarning($this->t('Enter a ticket title and a valid price or capacity as needed, then use Create ticket.'));
    }
    $this->lifecycle->syncPaidTiers($event);
    $this->messenger->addStatus($this->t('Tickets saved and synced.'));
  }

  private function saveInlineEdit(array &$form, FormStateInterface $form_state, NodeInterface $event, string $name): void {
    $tid = (int) str_replace('save_', '', $name);
    $card = $form_state->getValue($this->valuePath($form_state, 'builder_shell', 'list', (string) $tid, 'edit')) ?? [];

    $success = FALSE;

    try {
      $ticket = $this->findEventTicket($event, $tid);
      if (!$ticket) {
        throw new \InvalidArgumentException('Ticket not found on this event.');
      }

      $payload = $this->lifecycle->buildTicketUpdateValuesFromInput($event, $ticket, $this->currentUser, $card);
      $payload = array_merge($payload, $this->buildSaleWindowFragmentForLifecycle($card));
      $this->lifecycle->updateTicketType($ticket, $event, $payload);

      $ticket = $this->findEventTicket($event, $tid);
      if (!$ticket) {
        throw new \InvalidArgumentException('Ticket not found after save.');
      }

      $edit_path = $this->valuePath($form_state, 'builder_shell', 'list', (string) $tid, 'edit');
      $branch = NestedArray::getValue($form, $edit_path);
      $form_fragment = (is_array($branch) ? $branch : []) + ['#parents' => $edit_path];

      $display = EntityFormDisplay::collectRenderDisplay($ticket, 'default');
      foreach (['field_use_ticket_attendee_questions', 'field_attendee_questions'] as $field_name) {
        if (!$ticket->hasField($field_name)) {
          continue;
        }
        $renderer = $display->getRenderer($field_name);
        if ($renderer) {
          $renderer->extractFormValues($ticket->get($field_name), $form_fragment, $form_state);
        }
      }

      if ($ticket->hasField('field_use_ticket_attendee_questions')
        && !$ticket->get('field_use_ticket_attendee_questions')->value) {
        $this->removeTicketTypeAttendeeQuestionParagraphs($ticket);
      }

      $ticket->save();

      $this->messenger->addStatus($this->t('Ticket updated.'));
      $success = TRUE;
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_vendor')->error(
        'Inline ticket save failed for ticket @tid on event @nid: @message',
        [
          '@tid' => (string) $tid,
          '@nid' => (string) $event->id(),
          '@message' => $e->getMessage(),
        ]
      );

      if ($this->isCurrencyMismatchException($e)) {
        $this->messenger->addError($this->t(TicketTierLifecycleService::CURRENCY_MISMATCH_MESSAGE));
      }
      else {
        $this->messenger->addError($this->t('We couldn’t save this ticket. Please check your details and try again.'));
      }
    }

    if ($success) {
      $form_state->set('editing_ticket_id', NULL);
    }
    else {
      $form_state->set('editing_ticket_id', $tid);
    }
  }

  /**
   * Deletes per-ticket question paragraphs and clears the field reference.
   */
  private function removeTicketTypeAttendeeQuestionParagraphs(TicketTypeInterface $ticket): void {
    if (!$ticket->hasField('field_attendee_questions')) {
      return;
    }
    $refs = $ticket->get('field_attendee_questions')->referencedEntities();
    $ticket->set('field_attendee_questions', []);
    foreach ($refs as $entity) {
      if ($entity instanceof ParagraphInterface) {
        $entity->delete();
      }
    }
  }

  private function isCurrencyMismatchException(\Throwable $e): bool {
    return $e instanceof \InvalidArgumentException
      && $e->getMessage() === TicketTierLifecycleService::CURRENCY_MISMATCH_MESSAGE;
  }

  private function removeTicket(NodeInterface $event, string $name): void {
    $tid = (int) str_replace('ticket_remove_', '', $name);
    $ticket = $this->findEventTicket($event, $tid);
    if (!$ticket) {
      throw new \InvalidArgumentException('Ticket not found on this event.');
    }
    $this->lifecycle->archiveTicketOnEvent($event, $ticket);
    $this->messenger->addStatus($this->t('Ticket removed from this event and archived.'));
  }

  private function duplicateTicket(NodeInterface $event, string $name): void {
    $tid = (int) str_replace('ticket_duplicate_', '', $name);
    $ticket = $this->findEventTicket($event, $tid);
    if (!$ticket) {
      throw new \InvalidArgumentException('Ticket not found on this event.');
    }

    $payload = [
      'title' => $ticket->label() . ' ' . $this->t('(Copy)'),
      'ticket_kind' => $ticket->getTicketKind(),
      'vendor_id' => ['target_id' => (int) $this->currentUser->id()],
      'event' => ['target_id' => (int) $event->id()],
      'is_reusable' => FALSE,
      'status' => (int) $ticket->isPublished(),
    ];

    if (!$ticket->get('capacity')->isEmpty()) {
      $payload['capacity'] = (int) $ticket->get('capacity')->value;
    }
    if (!$ticket->get('rsvp_limit')->isEmpty()) {
      $payload['rsvp_limit'] = (int) $ticket->get('rsvp_limit')->value;
    }
    if (!$ticket->get('sale_start')->isEmpty()) {
      $payload['sale_start'] = (string) $ticket->get('sale_start')->value;
    }
    if (!$ticket->get('sale_end')->isEmpty()) {
      $payload['sale_end'] = (string) $ticket->get('sale_end')->value;
    }
    if (!$ticket->get('external_url')->isEmpty()) {
      $link = $ticket->get('external_url')->first();
      if ($link) {
        $payload['external_url'] = [
          'uri' => (string) $link->getUri(),
          'title' => (string) ($link->title ?? ''),
        ];
      }
    }
    if ($ticket->toPriceValue()) {
      $payload['price'] = [
        'number' => $ticket->toPriceValue()->getNumber(),
        'currency_code' => $ticket->toPriceValue()->getCurrencyCode(),
      ];
    }

    if ($ticket->hasField('visibility_mode') && !$ticket->get('visibility_mode')->isEmpty()) {
      $payload['visibility_mode'] = (string) $ticket->get('visibility_mode')->value;
    }
    if ($ticket->hasField('hidden_label') && !$ticket->get('hidden_label')->isEmpty()) {
      $payload['hidden_label'] = (string) $ticket->get('hidden_label')->value;
    }
    if ($ticket->hasField('short_description') && !$ticket->get('short_description')->isEmpty()) {
      $payload['short_description'] = (string) $ticket->get('short_description')->value;
    }
    if (!$this->isUnlimitedCapacity($ticket) && $ticket->hasField('waitlist_enabled')) {
      $payload['waitlist_enabled'] = $ticket->get('waitlist_enabled')->value ? 1 : 0;
    }
    if (!$this->isUnlimitedCapacity($ticket) && $ticket->hasField('waitlist_capacity') && !$ticket->get('waitlist_capacity')->isEmpty()) {
      $payload['waitlist_capacity'] = (int) $ticket->get('waitlist_capacity')->value;
    }
    if (!$this->isUnlimitedCapacity($ticket) && $ticket->hasField('auto_promote_waitlist')) {
      $payload['auto_promote_waitlist'] = $ticket->get('auto_promote_waitlist')->value ? 1 : 0;
    }
    if ($ticket->hasField('group_sale_mode') && !$ticket->get('group_sale_mode')->isEmpty()) {
      $payload['group_sale_mode'] = (string) $ticket->get('group_sale_mode')->value;
    }
    if ($ticket->hasField('group_min_size') && !$ticket->get('group_min_size')->isEmpty()) {
      $payload['group_min_size'] = (int) $ticket->get('group_min_size')->value;
    }
    if ($ticket->hasField('group_bundle_size') && !$ticket->get('group_bundle_size')->isEmpty()) {
      $payload['group_bundle_size'] = (int) $ticket->get('group_bundle_size')->value;
    }

    if ($ticket->hasField('field_use_ticket_attendee_questions')) {
      $payload['field_use_ticket_attendee_questions'] = $ticket->get('field_use_ticket_attendee_questions')->value ? 1 : 0;
    }
    if ($ticket->hasField('field_attendee_questions') && !$ticket->get('field_attendee_questions')->isEmpty()) {
      $refs = [];
      foreach ($ticket->get('field_attendee_questions')->referencedEntities() as $paragraph) {
        if (!$paragraph instanceof ParagraphInterface) {
          continue;
        }
        $dup = $paragraph->createDuplicate();
        $dup->save();
        $refs[] = [
          'target_id' => (int) $dup->id(),
          'target_revision_id' => (int) $dup->getRevisionId(),
        ];
      }
      if ($refs !== []) {
        $payload['field_attendee_questions'] = $refs;
      }
    }

    $this->lifecycle->createAttachAndSync($event, $payload);
    $this->messenger->addStatus($this->t('Ticket duplicated.'));
  }

  private function archiveTicket(NodeInterface $event, string $name): void {
    $tid = (int) str_replace('ticket_archive_', '', $name);
    $ticket = $this->findEventTicket($event, $tid);
    if (!$ticket) {
      throw new \InvalidArgumentException('Ticket not found on this event.');
    }
    $this->lifecycle->archiveTicketOnEvent($event, $ticket);
    $this->messenger->addStatus($this->t('Ticket archived.'));
  }

  private function reorderTickets(FormStateInterface $form_state, NodeInterface $event): void {
    $logger = $this->loggerFactory->get('myeventlane_vendor');
    $raw = trim((string) ($form_state->getValue($this->valuePath($form_state, 'builder_shell', 'order')) ?? ''));

    $decoded = $raw === '' ? NULL : Json::decode($raw);
    if (!is_array($decoded)) {
      $logger->warning(
        'Ticket reorder rejected: order field is not valid JSON array for event @nid.',
        ['@nid' => (string) $event->id()]
      );
      $this->messenger->addError($this->t('Could not save ticket order. Please refresh and try again.'));
      return;
    }

    $ids = [];
    foreach ($decoded as $index => $item) {
      $id = $this->normaliseJsonOrderTicketId($item);
      if ($id === NULL) {
        $logger->warning(
          'Ticket reorder rejected: value at index @i is not a positive integer for event @nid.',
          ['@i' => (string) $index, '@nid' => (string) $event->id()]
        );
        $this->messenger->addError($this->t('Could not save ticket order. Please refresh and try again.'));
        return;
      }
      $ids[] = $id;
    }

    $allowed = array_map(
      static fn (TicketTypeInterface $ticket) => (int) $ticket->id(),
      array_filter(
        $this->lifecycle->loadOrderedTicketsForEvent($event),
        static fn ($ticket) => $ticket instanceof TicketTypeInterface
      )
    );

    if (count($ids) !== count($allowed)) {
      $logger->warning(
        'Ticket reorder rejected: id count mismatch for event @nid (expected @expected, got @got).',
        [
          '@nid' => (string) $event->id(),
          '@expected' => (string) count($allowed),
          '@got' => (string) count($ids),
        ]
      );
      $this->messenger->addError($this->t('Could not save ticket order. Please refresh and try again.'));
      return;
    }

    if (count($ids) !== count(array_unique($ids))) {
      $logger->warning(
        'Ticket reorder rejected: duplicate ids in payload for event @nid.',
        ['@nid' => (string) $event->id()]
      );
      $this->messenger->addError($this->t('Could not save ticket order. Please refresh and try again.'));
      return;
    }

    $sorted_payload = $ids;
    $sorted_allowed = $allowed;
    sort($sorted_payload, SORT_NUMERIC);
    sort($sorted_allowed, SORT_NUMERIC);
    if ($sorted_payload !== $sorted_allowed) {
      $logger->warning(
        'Ticket reorder rejected: payload ids do not match tickets on event @nid.',
        ['@nid' => (string) $event->id()]
      );
      $this->messenger->addError($this->t('Could not save ticket order. Please refresh and try again.'));
      return;
    }

    if ($ids === $allowed) {
      return;
    }

    $this->lifecycle->reorderTicketsOnEvent($event, $ids);
    $this->messenger->addStatus($this->t('Ticket order saved.'));
  }

  /**
   * Accepts JSON-decoded scalars that represent a positive ticket ID.
   *
   * json_decode may return float for numeric JSON values; only whole numbers
   * in range are accepted.
   */
  private function normaliseJsonOrderTicketId(mixed $item): ?int {
    if (is_int($item)) {
      return $item >= 1 ? $item : NULL;
    }
    if (is_float($item)) {
      if (!is_finite($item) || $item < 1.0 || $item > (float) PHP_INT_MAX) {
        return NULL;
      }
      $as_int = (int) $item;
      return ((float) $as_int === $item) ? $as_int : NULL;
    }
    return NULL;
  }

  /**
   * Whether the ticket has the minimum viable info for buyer-facing tiers.
   */
  private function ticketCardIsOutcomeComplete(TicketTypeInterface $ticket): bool {
    $title = trim((string) $ticket->label());
    if ($title === '') {
      return FALSE;
    }

    return match ($ticket->getTicketKind()) {
      'paid' => (function () use ($ticket): bool {
          $price = $ticket->toPriceValue();
          return $price !== NULL && is_numeric($price->getNumber()) && (float) $price->getNumber() > 0;
        })(),
      'external' => (function () use ($ticket): bool {
          $uri = $ticket->getExternalUrlString();
          if ($uri === NULL || trim($uri) === '') {
            return FALSE;
          }
          return str_starts_with(mb_strtolower(trim($uri)), 'https://');
        })(),
      default => TRUE,
    };
  }

  /**
   * Default outcome price line for new-ticket display before inline JS updates it.
   */
  private function newTicketOutcomePriceCaption(string $ticket_kind, string $price_amount_raw, string $currency_code): string {
    return match ($ticket_kind) {
      'paid' => ''
        !== trim($price_amount_raw) && is_numeric($price_amount_raw) && (float) $price_amount_raw > 0
        ? $currency_code . ' ' . $this->formatPriceNumberForDisplay(trim($price_amount_raw))
          : (string) $this->t('—'),
      'rsvp' => (string) $this->t('$0'),
      default => (string) $this->t('External'),
    };
  }

  /**
   * Human-readable price line for card header (escaped HTML string).
   */
  private function formatPrice(TicketTypeInterface $ticket): string {
    return Html::escape($this->formatPriceText($ticket));
  }

  /**
   * Human-readable price text for compact ticket cards.
   */
  private function formatPriceText(TicketTypeInterface $ticket): string {
    $kind = $ticket->getTicketKind();
    if ($kind === 'paid') {
      $price = $ticket->toPriceValue();
      if ($price) {
        return $price->getCurrencyCode() . ' ' . $this->formatPriceNumberForDisplay((string) $price->getNumber());
      }
      return (string) $this->t('—');
    }
    if ($kind === 'rsvp') {
      return (string) $this->t('$0');
    }
    return (string) $this->t('External');
  }

  /**
   * Capacity label for compact ticket cards.
   */
  private function formatCapacityText(TicketTypeInterface $ticket): string {
    if ($this->isUnlimitedCapacity($ticket)) {
      return (string) $this->t('Unlimited');
    }
    $capacity = (int) $ticket->get('capacity')->value;
    return (string) $this->formatPlural($capacity, '1 spot', '@count spots');
  }

  private function isUnlimitedCapacity(TicketTypeInterface $ticket): bool {
    return $ticket->get('capacity')->isEmpty() || (int) $ticket->get('capacity')->value < 1;
  }

  /**
   * Public/private visibility label for the compact card.
   */
  private function ticketVisibilityLabel(TicketTypeInterface $ticket): string {
    if ($ticket->hasField('visibility_mode') && !$ticket->get('visibility_mode')->isEmpty()) {
      return (string) ($ticket->get('visibility_mode')->value === 'public'
        ? $this->t('Public')
        : $this->t('Private'));
    }
    return (string) $this->t('Public');
  }

  /**
   * Buyer-facing recommendation badge shown on organiser ticket cards.
   */
  private function recommendedTicketBadgeLabel(TicketTypeInterface $ticket): string {
    // Future labels can branch here on price rank or remaining inventory.
    return (string) $this->t('⭐ Most popular');
  }

  /**
   * Buyer-facing best-value badge shown on organiser ticket cards.
   */
  private function bestValueTicketBadgeLabel(TicketTypeInterface $ticket): string {
    return (string) $this->t('Best value');
  }

  /**
   * @return string
   *   Plain numeric string without trailing zeros (display only).
   */
  private function formatPriceNumberForDisplay(string $number): string {
    if ($number === '' || !is_numeric($number)) {
      return $number;
    }
    $n = (float) $number;
    if (abs($n - round($n)) < 0.0000001) {
      return (string) (int) round($n);
    }
    $out = number_format($n, 2, '.', '');
    return rtrim(rtrim($out, '0'), '.') ?: '0';
  }

  /**
   * @return string
   *   Safe CSS suffix (whitelist) for mel-badge--* classes.
   */
  private function ticketStatusBadgeClassSuffix(string $status): string {
    $allowed = [
      TicketStatusService::STATUS_ACTIVE,
      TicketStatusService::STATUS_UPCOMING,
      TicketStatusService::STATUS_ENDED,
      TicketStatusService::STATUS_SOLD_OUT,
      TicketStatusService::STATUS_INACTIVE,
      TicketStatusService::STATUS_ARCHIVED,
    ];
    return in_array($status, $allowed, TRUE) ? $status : TicketStatusService::STATUS_INACTIVE;
  }

  /**
   * Simplified lifecycle label requested by the MEL card UI.
   */
  private function ticketCardStatusLabel(string $status): string {
    return match ($status) {
      TicketStatusService::STATUS_ARCHIVED => (string) $this->t('Archived'),
      TicketStatusService::STATUS_INACTIVE => (string) $this->t('Draft'),
      default => (string) $this->t('Active'),
    };
  }

  /**
   * Safe CSS suffix for simplified card status badges.
   */
  private function ticketCardStatusClassSuffix(string $status): string {
    return match ($status) {
      TicketStatusService::STATUS_ARCHIVED => 'archived',
      TicketStatusService::STATUS_INACTIVE => 'draft',
      default => 'active',
    };
  }

  /**
   * Event-level snapshot above the card list (completed-order metrics only).
   *
   * @param list<TicketTypeInterface> $tickets
   */
  private function buildEventAnalyticsStrip(NodeInterface $event, array $tickets): array {
    $rollup = $this->tierAnalytics->buildEventTierRollup($event, $tickets);
    $gross = $rollup['gross_revenue'];
    $revDisplay = '—';
    if (is_array($gross) && isset($gross['number'], $gross['currency_code'])) {
      $revDisplay = $gross['currency_code'] . ' ' . $this->formatPriceNumberForDisplay((string) $gross['number']);
    }
    $remDisplay = (string) ($rollup['remaining_display'] ?? '0');
    if ($remDisplay === 'Tickets available') {
      $remDisplay = (string) $this->t('Tickets available');
    }
    elseif ($remDisplay === 'No limit') {
      $remDisplay = (string) $this->t('No limit');
    }
    elseif ($remDisplay === 'Unlimited') {
      $remDisplay = (string) $this->t('Unlimited');
    }

    $note = (string) ($rollup['conversion_note'] ?? '');
    $html = '<div class="mel-ticket-analytics-strip" role="region" aria-label="' . Html::escape((string) $this->t('Ticket sales summary')) . '">';
    $html .= '<div class="mel-ticket-analytics-strip__grid">';
    $cells = [
      [(string) $this->t('Sold'), (string) $rollup['total_sold']],
      [(string) $this->t('Remaining'), $remDisplay],
      [(string) $this->t('Gross revenue'), $revDisplay],
      [(string) $this->t('Active tiers'), (string) $rollup['active_tier_count']],
      [(string) $this->t('Sold out'), (string) $rollup['sold_out_tier_count']],
      [(string) $this->t('Restricted'), (string) $rollup['restricted_tier_count']],
    ];
    foreach ($cells as [$label, $value]) {
      $html .= '<div class="mel-ticket-analytics-strip__cell">';
      $html .= '<span class="mel-ticket-analytics-strip__label">' . Html::escape($label) . '</span>';
      $html .= '<span class="mel-ticket-analytics-strip__value">' . Html::escape($value) . '</span>';
      $html .= '</div>';
    }
    $html .= '</div>';
    if ($note !== '') {
      $html .= '<p class="mel-ticket-analytics-strip__note">' . Html::escape($note) . '</p>';
    }
    $html .= '</div>';

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-ticket-builder__analytics-wrap']],
      'inner' => ['#markup' => $html],
    ];
  }

  /**
   * Structured analytics row on each ticket card (no extra queries).
   */
  private function buildCardAnalyticsStatsMarkup(TicketTypeInterface $ticket, string $ticket_status): string {
    $metrics = $this->tierAnalytics->buildTierMetrics($ticket);
    $sold = (int) $metrics['sold'];
    $capacity = $metrics['capacity'];
    $isUnlimited = $capacity === NULL || $capacity < 1;
    $capDisplay = $isUnlimited ? (string) $this->t('Unlimited') : (string) $capacity;
    $remaining = $metrics['remaining'];
    $remDisplay = $remaining === NULL ? (string) $this->t('No limit') : (string) $remaining;
    $pct = $metrics['sell_through_percent'];
    $pctDisplay = $pct === NULL ? '—' : (string) $pct . '%';

    $revLine = '—';
    $rev = $metrics['revenue'];
    if (is_array($rev) && isset($rev['number'], $rev['currency_code'])) {
      $revLine = $rev['currency_code'] . ' ' . $this->formatPriceNumberForDisplay((string) $rev['number']);
    }

    $status_label = strtoupper($ticket_status);
    $access_markup = $this->buildMelAccessBadgesMarkup($ticket);

    $html = '<div class="mel-ticket-card__analytics" role="group" aria-label="' . Html::escape((string) $this->t('Sales and access')) . '">';
    $html .= '<div class="mel-ticket-card__analytics-grid">';
    $html .= '<div class="mel-ticket-card__stat"><span class="mel-ticket-card__stat-label">' . Html::escape((string) $this->t('Sold / cap')) . '</span>';
    $html .= '<span class="mel-ticket-card__stat-value">' . Html::escape((string) $sold . ' / ' . $capDisplay) . '</span></div>';
    $html .= '<div class="mel-ticket-card__stat"><span class="mel-ticket-card__stat-label">' . Html::escape((string) $this->t('Remaining')) . '</span>';
    $html .= '<span class="mel-ticket-card__stat-value">' . Html::escape($remDisplay) . '</span></div>';
    $html .= '<div class="mel-ticket-card__stat"><span class="mel-ticket-card__stat-label">' . Html::escape((string) $this->t('Gross')) . '</span>';
    $html .= '<span class="mel-ticket-card__stat-value">' . Html::escape($revLine) . '</span></div>';
    $html .= '<div class="mel-ticket-card__stat"><span class="mel-ticket-card__stat-label">' . Html::escape((string) $this->t('Sell-through')) . '</span>';
    $html .= '<span class="mel-ticket-card__stat-value">' . Html::escape($pctDisplay) . '</span></div>';
    $html .= '</div>';
    $html .= '<div class="mel-ticket-card__analytics-badges">';
    $html .= '<span class="mel-badge mel-badge--' . Html::escape($this->ticketStatusBadgeClassSuffix($ticket_status)) . '">' . Html::escape($status_label) . '</span>';
    if ($access_markup !== '') {
      $html .= $access_markup;
    }
    $html .= '</div>';
    $conv = (string) ($metrics['conversion_note'] ?? '');
    if ($conv !== '') {
      $html .= '<p class="mel-ticket-card__analytics-note">' . Html::escape($conv) . '</p>';
    }
    $html .= '</div>';

    return $html;
  }

  /**
   * MEL policy badges (visibility / waitlist) for organiser cards.
   */
  private function buildMelAccessBadgesMarkup(TicketTypeInterface $ticket): string {
    $badges = [];
    if ($ticket->hasField('visibility_mode') && !$ticket->get('visibility_mode')->isEmpty()) {
      $mode = (string) $ticket->get('visibility_mode')->value;
      $badges[] = [
        'label' => match ($mode) {
          'public' => (string) $this->t('Public listing'),
          'hidden' => (string) $this->t('Hidden'),
          'access_code' => (string) $this->t('Access code'),
          'group_only' => (string) $this->t('Team only'),
          default => '',
        },
        'class' => match ($mode) {
          'public' => 'mel-badge--access-public',
          'hidden' => 'mel-badge--access-hidden',
          'access_code' => 'mel-badge--access-code',
          'group_only' => 'mel-badge--access-group',
          default => '',
        },
      ];
    }
    if (!$this->isUnlimitedCapacity($ticket) && $ticket->hasField('waitlist_enabled') && $ticket->get('waitlist_enabled')->value) {
      $badges[] = [
        'label' => (string) $this->t('Waitlist'),
        'class' => 'mel-badge--access-waitlist',
      ];
    }
    $badges = array_values(array_filter($badges, static fn (array $b) => $b['label'] !== '' && $b['class'] !== ''));
    if ($badges === []) {
      return '';
    }
    $html = '';
    foreach ($badges as $badge) {
      $html .= '<span class="mel-badge mel-badge--access ' . Html::escape($badge['class']) . '">' . Html::escape($badge['label']) . '</span>';
    }
    return $html;
  }

  private function findEventTicket(NodeInterface $event, int $tid): ?TicketTypeInterface {
    foreach ($this->lifecycle->loadOrderedTicketsForEvent($event) as $ticket) {
      if ($ticket instanceof TicketTypeInterface && (int) $ticket->id() === $tid) {
        return $ticket;
      }
    }
    return NULL;
  }

  private function canManageEvent(NodeInterface $event): bool {
    if ($this->currentUser->hasPermission('administer nodes')) {
      return TRUE;
    }
    return (int) $event->getOwnerId() === (int) $this->currentUser->id();
  }

}