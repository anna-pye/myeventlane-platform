<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Ticketing;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_event\Service\TicketTierLifecycleService;
use Drupal\myeventlane_event\Service\TicketTypeManager;
use Drupal\myeventlane_commerce\Service\TicketStatusService;
use Drupal\myeventlane_commerce\Service\TicketTierAnalyticsService;
use Drupal\node\NodeInterface;

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

    $form['builder_shell']['controls']['begin_add_rsvp'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add ticket'),
      '#name' => 'ticket_begin_add',
      '#submit' => ['::handleAction'],
      '#ajax' => [
        'callback' => '::ajaxRebuildTicketBuilder',
        'wrapper' => EventTicketsBuilder::BUILDER_WRAPPER_ID,
      ],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn--primary', 'mel-ticket-controls__add'],
        // Event Studio nests this builder in a form with required mel[title] etc.; without
        // formnovalidate the browser blocks AJAX before Drupal runs (standalone /tickets has no such fields).
        'formnovalidate' => 'formnovalidate',
      ],
      '#access' => !$adding_new,
    ];

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

    $form['builder_shell']['controls']['save_sync'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save & sync'),
      '#name' => 'ticket_save_sync',
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
    }

    foreach ($tickets as $ticket) {
      $tid = (int) $ticket->id();
      $ticket_status = $this->ticketStatus->getStatus($ticket);
      $read_only_card = in_array($ticket_status, [
        TicketStatusService::STATUS_ENDED,
        TicketStatusService::STATUS_ARCHIVED,
      ], TRUE);
      $is_editing = (int) $editing_id === $tid && !$read_only_card;

      $form['builder_shell']['list'][$tid] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => [
            'mel-card',
            'mel-ticket-card',
            'js-mel-ticket-card',
            $is_editing ? 'is-editing' : 'is-view',
          ],
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

    if (!$adding_new && $tickets === []) {
      $form['builder_shell']['empty'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-ticket-builder__empty', 'mel-ticket-builder__empty--hero']],
        'text' => [
          '#markup' => '<p class="mel-ticket-builder__empty-title">' . Html::escape((string) $this->t('No ticket types yet')) . '</p>'
            . '<p class="mel-ticket-builder__empty-body">' . Html::escape((string) $this->t('Add a paid, RSVP, or external ticket to start selling. You can reorder tiers anytime.')) . '</p>',
        ],
      ];
    }

    $form['#attached']['library'][] = 'myeventlane_vendor/ticket_cards';
    $form['#attached']['library'][] = 'core/drupal.ajax';
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
        $name === 'ticket_begin_add' => $this->beginAdd($form_state),
        $name === 'ticket_begin_add_rsvp' => $this->beginAddWithKind($form_state, 'rsvp'),
        $name === 'ticket_begin_add_paid' => $this->beginAddWithKind($form_state, 'paid'),
        $name === 'ticket_begin_add_external' => $this->beginAddWithKind($form_state, 'external'),
        $name === 'ticket_cancel_add' => $this->cancelAdd($form_state),
        $name === 'ticket_create' => $this->createTicket($form_state, $event),
        $name === 'ticket_save_sync' => $this->performSaveAndSync($form_state, $event),
        $name === 'ticket_reorder' => $this->reorderTickets($form_state, $event),
        str_starts_with($name, 'edit_') => $this->beginInlineEdit($form_state, $name),
        str_starts_with($name, 'cancel_') => $this->cancelInlineEdit($form_state),
        str_starts_with($name, 'save_') => $this->saveInlineEdit($form_state, $event, $name),
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

    $ticket_kind_input = ':input[name="' . $this->formElementFullName($form_state, 'builder_shell', 'list', 'new', 'fields', 'ticket_kind') . '"]';

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-card', 'mel-ticket-card', 'mel-ticket-card--new', 'is-editing'],
        'data-mel-ticket-kind' => $prefill,
      ],
      'header' => [
        '#type' => 'html_tag',
        '#tag' => 'h4',
        '#value' => $this->t('New ticket'),
        '#attributes' => ['class' => ['mel-ticket-card__title', 'mel-ticket-card__title--form']],
      ],
      'fields' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-ticket-card__fields']],
        'title' => [
          '#type' => 'textfield',
          '#title' => $this->t('Title'),
          '#default_value' => (string) ($form_state->getValue($this->valuePath($form_state, 'builder_shell', 'list', 'new', 'fields', 'title')) ?? ''),
        ],
        'short_description' => [
          '#type' => 'textarea',
          '#title' => $this->t('Short description (buyers)'),
          '#description' => $this->t('Optional. About two lines — shown on the public ticket picker.'),
          '#rows' => 2,
          '#maxlength' => self::SHORT_DESCRIPTION_MAX_LENGTH,
          '#default_value' => (string) ($form_state->getValue($this->valuePath($form_state, 'builder_shell', 'list', 'new', 'fields', 'short_description')) ?? ''),
        ],
        'ticket_kind' => [
          '#type' => 'select',
          '#title' => $this->t('Type'),
          '#options' => [
            'paid' => $this->t('Paid'),
            'rsvp' => $this->t('RSVP'),
            'external' => $this->t('External'),
          ],
          '#default_value' => (string) ($form_state->getValue($this->valuePath($form_state, 'builder_shell', 'list', 'new', 'fields', 'ticket_kind'))
            ?? (string) ($form_state->get('mel_ticket_prefill_kind') ?: 'paid')),
        ],
        'price_amount' => [
          '#type' => 'number',
          '#title' => $this->t('Price'),
          '#step' => 0.01,
          '#min' => 0,
          '#default_value' => (string) ($form_state->getValue($this->valuePath($form_state, 'builder_shell', 'list', 'new', 'fields', 'price_amount')) ?? ''),
          '#states' => [
            'visible' => [
              $ticket_kind_input => ['value' => 'paid'],
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
        'capacity' => [
          '#type' => 'number',
          '#title' => $this->t('Capacity'),
          '#min' => 1,
          '#default_value' => (string) ($form_state->getValue($this->valuePath($form_state, 'builder_shell', 'list', 'new', 'fields', 'capacity')) ?? ''),
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
          '#default_value' => 1,
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
    $meta_line = $this->buildCardPriceCapacityLine($ticket);
    $visibility_label = $this->ticketVisibilityLabel($ticket);

    $card['view'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-ticket-card__view'],
        'data-mel-ticket-view' => '1',
      ],
    ];
    $view = &$card['view'];

    $view['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-ticket-card__header']],
      'title_group' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-ticket-card__title-group']],
        'title' => [
          '#markup' => '<div class="mel-ticket-card__title">' . Html::escape($ticket->label()) . '</div>',
        ],
      ],
      'status' => [
        '#markup' => '<span class="mel-ticket-card__state" data-mel-ticket-state aria-live="polite"></span><span class="mel-badge mel-badge--' . Html::escape($status_class) . '">' . Html::escape($status_label) . '</span>',
      ],
    ];

    $view['meta'] = [
      '#markup' => '<div class="' . Html::escape('mel-ticket-card__meta-line') . '">' . Html::escape($meta_line) . '</div>',
    ];

    $view['visibility'] = [
      '#markup' => '<div class="' . Html::escape('mel-ticket-card__visibility') . '">' . Html::escape($visibility_label) . '</div>',
    ];

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

    $card['edit']['status'] = [
      '#markup' => '<div class="mel-ticket-card__header"><div class="mel-ticket-card__title-group"><div class="mel-ticket-card__title">' . Html::escape($ticket->label()) . '</div><div class="mel-ticket-card__description">' . Html::escape(ucfirst($ticket->getTicketKind())) . '</div></div><div class="mel-ticket-card__header-actions"><span class="mel-ticket-card__state" data-mel-ticket-state aria-live="polite"></span><span class="mel-badge mel-badge--' . Html::escape($edit_badge_class) . '">' . Html::escape($edit_status_label) . '</span></div></div>',
      '#weight' => -20,
    ];

    $card['edit']['primary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-ticket-card__fields', 'mel-ticket-card__fields--primary']],
      '#weight' => -10,
    ];

    $card['edit']['primary']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => (string) ($form_state->getValue(array_merge($edit_path, ['title'])) ?? $ticket->label()),
      '#required' => TRUE,
      '#parents' => array_merge($edit_path, ['title']),
      '#attributes' => [
        'class' => ['js-mel-ticket-title'],
        'data-mel-ticket-required-message' => (string) $this->t('Title is required.'),
      ],
    ];

    if ($ticket->getTicketKind() === 'paid') {
      $price = $ticket->toPriceValue();

      $card['edit']['primary']['price'] = [
        '#type' => 'number',
        '#title' => $this->t('Price'),
        '#default_value' => $price ? $price->getNumber() : 0,
        '#step' => 0.01,
        '#min' => 0.01,
        '#required' => TRUE,
        '#parents' => array_merge($edit_path, ['price']),
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
    $form_state->set('mel_ticket_adding_new', TRUE);
    $form_state->set('editing_ticket_id', NULL);
  }

  /**
   * Opens the new-ticket card with a suggested tier kind (still editable).
   */
  private function beginAddWithKind(FormStateInterface $form_state, string $kind): void {
    $allowed = ['rsvp', 'paid', 'external'];
    $form_state->set('mel_ticket_prefill_kind', in_array($kind, $allowed, TRUE) ? $kind : 'paid');
    $form_state->set('mel_ticket_adding_new', TRUE);
    $form_state->set('editing_ticket_id', NULL);
  }

  private function cancelAdd(FormStateInterface $form_state): void {
    $form_state->set('mel_ticket_adding_new', FALSE);
    $form_state->set('mel_ticket_prefill_kind', NULL);
  }

  private function beginInlineEdit(FormStateInterface $form_state, string $name): void {
    $tid = (int) str_replace('edit_', '', $name);
    $form_state->set('editing_ticket_id', $tid);
    $form_state->set('mel_ticket_adding_new', FALSE);
  }

  private function cancelInlineEdit(FormStateInterface $form_state): void {
    $form_state->set('editing_ticket_id', NULL);
  }

  private function createTicket(FormStateInterface $form_state, NodeInterface $event, bool $quiet = FALSE): void {
    $values = $form_state->getValue($this->valuePath($form_state, 'builder_shell', 'list', 'new', 'fields')) ?? [];
    $payload = $this->lifecycle->buildTicketValuesFromInput($event, $this->currentUser, $values);
    $this->lifecycle->createAttachAndSync($event, $payload);
    if (!$quiet) {
      $this->messenger->addStatus($this->t('Ticket created.'));
    }
    $form_state->set('mel_ticket_adding_new', FALSE);
    $form_state->set('mel_ticket_prefill_kind', NULL);
  }

  /**
   * Syncs Commerce for paid tiers; if a new-tier form is open with a title, saves it first.
   *
   * Vendors often click "Save & sync" instead of "Create ticket"; without this, nothing persists.
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
      $this->messenger->addWarning($this->t('Enter a ticket title and price or capacity as needed, then use Save & sync again — or use Create ticket.'));
    }
    $this->lifecycle->syncPaidTiers($event);
    $this->messenger->addStatus($this->t('Tickets saved and synced.'));
  }

  private function saveInlineEdit(FormStateInterface $form_state, NodeInterface $event, string $name): void {
    $tid = (int) str_replace('save_', '', $name);
    $card = $form_state->getValue($this->valuePath($form_state, 'builder_shell', 'list', (string) $tid, 'edit')) ?? [];

    $success = FALSE;

    try {
      $ticket = $this->findEventTicket($event, $tid);
      if (!$ticket) {
        throw new \InvalidArgumentException('Ticket not found on this event.');
      }

      $payload = $this->lifecycle->buildTicketUpdateValuesFromInput($event, $ticket, $this->currentUser, $card);
      $this->lifecycle->updateTicketType($ticket, $event, $payload);
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
   * Compact price/capacity line for the view card.
   */
  private function buildCardPriceCapacityLine(TicketTypeInterface $ticket): string {
    return $this->formatPriceText($ticket) . ' • ' . $this->formatCapacityText($ticket);
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