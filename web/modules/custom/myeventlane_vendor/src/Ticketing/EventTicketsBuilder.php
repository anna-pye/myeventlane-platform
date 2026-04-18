<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Ticketing;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Url;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
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
  ) {}

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
      '#value' => $this->t('+ Add free RSVP'),
      '#name' => 'ticket_begin_add_rsvp',
      '#submit' => ['::handleAction'],
      '#ajax' => [
        'callback' => '::ajaxRebuildTicketBuilder',
        'wrapper' => EventTicketsBuilder::BUILDER_WRAPPER_ID,
      ],
      '#limit_validation_errors' => [],
      '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary', 'mel-ticket-controls__rsvp']],
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
      '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary', 'mel-ticket-controls__paid']],
      '#access' => !$adding_new,
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
      '#attributes' => ['class' => ['mel-btn', 'mel-btn--secondary', 'mel-ticket-controls__external']],
      '#access' => !$adding_new,
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
      '#attributes' => ['class' => ['mel-btn', 'mel-btn--secondary']],
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
      $is_editing = $editing_id !== NULL && (int) $editing_id === $tid;

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

      if ($is_editing) {
        $edit_path = $this->valuePath($form_state, 'builder_shell', 'list', (string) $tid, 'edit');
        $form['builder_shell']['list'][$tid]['edit'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-ticket-edit']],
        ];

        $edit_status = $this->ticketStatus->getStatus($ticket);
        $edit_badge_class = $this->ticketStatusBadgeClassSuffix($edit_status);
        $form['builder_shell']['list'][$tid]['edit']['status'] = [
          '#markup' => '<div class="mel-ticket-edit__status"><span class="mel-badge mel-badge--' . Html::escape($edit_badge_class) . '">' . Html::escape(strtoupper($edit_status)) . '</span></div>',
          '#weight' => -20,
        ];

        $form['builder_shell']['list'][$tid]['edit']['status_published'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Published (active)'),
          '#description' => $this->t('When unchecked, the ticket is unpublished (inactive) for buyers.'),
          '#default_value' => (int) ($form_state->getValue(array_merge($edit_path, ['status_published'])) ?? ($ticket->isPublished() ? 1 : 0)),
          '#weight' => -15,
        ];

        $form['builder_shell']['list'][$tid]['edit']['title'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Title'),
          '#default_value' => (string) ($form_state->getValue(array_merge($edit_path, ['title'])) ?? $ticket->label()),
          '#required' => TRUE,
        ];
        $form['builder_shell']['list'][$tid]['edit']['short_description'] = [
          '#type' => 'textarea',
          '#title' => $this->t('Short description (buyers)'),
          '#description' => $this->t('Optional. About two lines — shown on the public ticket picker.'),
          '#rows' => 2,
          '#maxlength' => self::SHORT_DESCRIPTION_MAX_LENGTH,
          '#default_value' => (string) ($form_state->getValue(array_merge($edit_path, ['short_description']))
            ?? ($ticket->hasField('short_description') ? (string) ($ticket->get('short_description')->value ?? '') : '')),
        ];

        if ($ticket->getTicketKind() === 'paid') {
          $price = $ticket->toPriceValue();

          $form['builder_shell']['list'][$tid]['edit']['price'] = [
            '#type' => 'number',
            '#title' => $this->t('Price'),
            '#default_value' => $price ? $price->getNumber() : 0,
            '#step' => 0.01,
            '#min' => 0,
          ];
        }

        $form['builder_shell']['list'][$tid]['edit']['capacity'] = [
          '#type' => 'number',
          '#title' => $this->t('Capacity'),
          '#default_value' => (string) ($form_state->getValue(array_merge($edit_path, ['capacity'])) ?? (int) ($ticket->get('capacity')->value ?? 0)),
        ];

        $vis_default = (string) ($form_state->getValue(array_merge($edit_path, ['visibility_mode']))
          ?? ($ticket->hasField('visibility_mode') && !$ticket->get('visibility_mode')->isEmpty()
            ? (string) $ticket->get('visibility_mode')->value
            : 'public'));
        $visibility_options = [
          'public' => $this->t('Public — listed to everyone'),
          'hidden' => $this->t('Hidden — never listed publicly'),
          'access_code' => $this->t('Access code — unlock with a code'),
          'group_only' => $this->t('Organiser team — vendor owner & team members only'),
        ];
        $form['builder_shell']['list'][$tid]['edit']['visibility_mode'] = [
          '#type' => 'select',
          '#title' => $this->t('Visibility'),
          '#description' => $this->t('Team-only tiers are hidden from the public booking page. They appear when the signed-in buyer is the event owner or a user on the vendor’s team (same rule as your vendor console). Everyone else needs a different visibility mode or an access code.'),
          '#options' => $visibility_options,
          '#default_value' => $vis_default,
        ];
        $form['builder_shell']['list'][$tid]['edit']['hidden_label'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Internal label (optional)'),
          '#default_value' => (string) ($form_state->getValue(array_merge($edit_path, ['hidden_label']))
            ?? ($ticket->hasField('hidden_label') ? (string) ($ticket->get('hidden_label')->value ?? '') : '')),
        ];
        $form['builder_shell']['list'][$tid]['edit']['waitlist_enabled'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Enable tier waitlist when sold out'),
          '#default_value' => (int) ($form_state->getValue(array_merge($edit_path, ['waitlist_enabled']))
            ?? ($ticket->hasField('waitlist_enabled') && $ticket->get('waitlist_enabled')->value ? 1 : 0)),
        ];
        $form['builder_shell']['list'][$tid]['edit']['waitlist_capacity'] = [
          '#type' => 'number',
          '#title' => $this->t('Waitlist capacity (optional)'),
          '#min' => 0,
          '#default_value' => (string) ($form_state->getValue(array_merge($edit_path, ['waitlist_capacity']))
            ?? ($ticket->hasField('waitlist_capacity') && !$ticket->get('waitlist_capacity')->isEmpty()
              ? (string) (int) $ticket->get('waitlist_capacity')->value
              : '')),
        ];
        $form['builder_shell']['list'][$tid]['edit']['auto_promote_waitlist'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Auto-offer waitlist when tickets free up'),
          '#default_value' => (int) ($form_state->getValue(array_merge($edit_path, ['auto_promote_waitlist']))
            ?? ($ticket->hasField('auto_promote_waitlist') && $ticket->get('auto_promote_waitlist')->value ? 1 : 0)),
        ];
        $group_mode_default = (string) ($form_state->getValue(array_merge($edit_path, ['group_sale_mode']))
          ?? ($ticket->hasField('group_sale_mode') && !$ticket->get('group_sale_mode')->isEmpty()
            ? (string) $ticket->get('group_sale_mode')->value
            : 'none'));
        $form['builder_shell']['list'][$tid]['edit']['group_sale_mode'] = [
          '#type' => 'select',
          '#title' => $this->t('Group sales'),
          '#options' => [
            'none' => $this->t('None'),
            'fixed_bundle' => $this->t('Fixed bundle (e.g. table of N)'),
            'minimum_group_size' => $this->t('Minimum group size'),
            'reserved_block' => $this->t('Reserved block / partner'),
          ],
          '#default_value' => $group_mode_default,
          '#description' => $this->t('Quantity rules are enforced at checkout.'),
        ];
        $form['builder_shell']['list'][$tid]['edit']['group_min_size'] = [
          '#type' => 'number',
          '#title' => $this->t('Minimum group size'),
          '#min' => 0,
          '#default_value' => (string) ($form_state->getValue(array_merge($edit_path, ['group_min_size']))
            ?? ($ticket->hasField('group_min_size') && !$ticket->get('group_min_size')->isEmpty()
              ? (string) (int) $ticket->get('group_min_size')->value
              : '')),
        ];
        $form['builder_shell']['list'][$tid]['edit']['group_bundle_size'] = [
          '#type' => 'number',
          '#title' => $this->t('Bundle / block size'),
          '#min' => 0,
          '#default_value' => (string) ($form_state->getValue(array_merge($edit_path, ['group_bundle_size']))
            ?? ($ticket->hasField('group_bundle_size') && !$ticket->get('group_bundle_size')->isEmpty()
              ? (string) (int) $ticket->get('group_bundle_size')->value
              : '')),
        ];

        $form['builder_shell']['list'][$tid]['edit']['actions'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-ticket-card__actions']],
        ];

        $limit_edit = [$this->valuePath($form_state, 'builder_shell', 'list', (string) $tid, 'edit')];

        $form['builder_shell']['list'][$tid]['edit']['actions']['save'] = [
          '#type' => 'submit',
          '#value' => $this->t('Save'),
          '#name' => 'save_' . $tid,
          '#submit' => ['::handleAction'],
          '#ajax' => [
            'callback' => '::ajaxRebuildTicketBuilder',
            'wrapper' => self::BUILDER_WRAPPER_ID,
          ],
          '#limit_validation_errors' => $limit_edit,
          '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary']],
        ];

        $form['builder_shell']['list'][$tid]['edit']['actions']['cancel'] = [
          '#type' => 'submit',
          '#value' => $this->t('Cancel'),
          '#name' => 'cancel_' . $tid,
          '#submit' => ['::handleAction'],
          '#ajax' => [
            'callback' => '::ajaxRebuildTicketBuilder',
            'wrapper' => self::BUILDER_WRAPPER_ID,
          ],
          '#limit_validation_errors' => $limit_edit,
          '#attributes' => ['class' => ['mel-btn', 'mel-btn--ghost']],
        ];
      }
      else {
        $this->appendViewTicketCardElements($form['builder_shell']['list'][$tid], $ticket, $form_state, $event);
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
      $form_state->setRebuild();
      return;
    }

    $trigger = $form_state->getTriggeringElement();
    $name = (string) ($trigger['#name'] ?? '');

    if ($name === '') {
      $this->loggerFactory->get('myeventlane_vendor')->warning('Ticket builder action triggered without #name.');
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
        $name === 'ticket_save_sync' => $this->saveAndSync($event),
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
      $this->messenger->addError($this->t('Ticket update failed: @message', ['@message' => $e->getMessage()]));
    }

    // Full form rebuild so EventFormAlter can re-apply ticket-driven #access on ops fields.
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
          '#states' => [
            'visible' => [
              $ticket_kind_input => [
                ['value' => 'paid'],
                'or',
                ['value' => 'rsvp'],
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
          '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary']],
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
          '#attributes' => ['class' => ['mel-btn', 'mel-btn--ghost']],
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
  private function appendViewTicketCardElements(array &$card, TicketTypeInterface $ticket, FormStateInterface $form_state, NodeInterface $event): void {
    $tid = (int) $ticket->id();
    $kind = $ticket->getTicketKind();
    $ticket_status = $this->ticketStatus->getStatus($ticket);

    $capacity_display = !$ticket->get('capacity')->isEmpty()
      ? (string) (int) $ticket->get('capacity')->value
      : (string) $this->t('—');

    $metrics = $this->tierAnalytics->buildTierMetrics($ticket);
    $sold = (int) $metrics['sold'];
    $activity_line = match ($kind) {
      'rsvp' => (string) $this->t('@count going', ['@count' => (string) $sold]),
      'paid' => (string) $this->t('@count sold', ['@count' => (string) $sold]),
      default => (string) $this->t('External ticket'),
    };
    $capacity_line = (string) $this->t('Capacity: @cap', ['@cap' => $capacity_display]);

    $highlight_capacity = $ticket_status === TicketStatusService::STATUS_SOLD_OUT
      && !$ticket->get('capacity')->isEmpty()
      && (int) $ticket->get('capacity')->value > 0;
    $capacity_li_attr = $highlight_capacity
      ? ' class="' . Html::escape('mel-ticket-card__capacity--highlight') . '"'
      : '';

    $card['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-ticket-card__header']],
      'title_group' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-ticket-card__title-group']],
        'title' => [
          '#markup' => '<div class="mel-ticket-card__title">' . Html::escape($ticket->label()) . '</div>',
        ],
      ],
    ];
    $buyer_desc = '';
    if ($ticket->hasField('short_description') && !$ticket->get('short_description')->isEmpty()) {
      $buyer_desc = trim((string) $ticket->get('short_description')->value);
    }
    if ($buyer_desc !== '') {
      $card['header']['title_group']['description'] = [
        '#markup' => '<div class="mel-ticket-card__description">' . nl2br(Html::escape($buyer_desc), FALSE) . '</div>',
      ];
    }
    $card['header'] = array_merge($card['header'], [
      'price' => [
        '#markup' => '<div class="mel-ticket-card__price">' . $this->formatPrice($ticket) . '</div>',
      ],
    ]);

    $card['meta'] = [
      '#markup' => '<ul class="' . Html::escape('mel-ticket-card__meta') . '"><li>' . Html::escape($activity_line) . '</li><li' . $capacity_li_attr . '>' . Html::escape($capacity_line) . '</li></ul>',
    ];

    if ($ticket_status === TicketStatusService::STATUS_SOLD_OUT) {
      $card['soldout_note'] = [
        '#markup' => '<div class="mel-ticket-card__sold-out-note" role="status">' . Html::escape((string) $this->t('Sold out')) . '</div>',
      ];
    }

    $card['sales'] = [
      '#markup' => $this->buildCardAnalyticsStatsMarkup($ticket, $ticket_status),
    ];

    $archive_only = in_array($ticket_status, [
      TicketStatusService::STATUS_ENDED,
      TicketStatusService::STATUS_INACTIVE,
    ], TRUE);

    $full_edit_url = Url::fromRoute('myeventlane_tickets.event_ticket_type_edit', [
      'event' => $event->id(),
      'mel_ticket_type' => $ticket->id(),
    ]);

    $card['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-ticket-card__actions']],
      'edit' => [
        '#type' => 'submit',
        '#value' => $this->t('Edit'),
        '#name' => 'edit_' . $tid,
        '#submit' => ['::handleAction'],
        '#ajax' => [
          'callback' => '::ajaxRebuildTicketBuilder',
          'wrapper' => EventTicketsBuilder::BUILDER_WRAPPER_ID,
        ],
        '#limit_validation_errors' => [
          $this->valuePath($form_state, 'builder_shell', 'list', (string) $tid, 'edit'),
        ],
        '#attributes' => ['class' => ['mel-btn', 'mel-btn--ghost']],
        '#access' => !$archive_only,
      ],
      'full_edit' => [
        '#type' => 'link',
        '#title' => $this->t('Full edit'),
        '#url' => $full_edit_url,
        '#access' => !$archive_only && $full_edit_url->access($this->currentUser),
        '#attributes' => [
          'class' => ['mel-btn', 'mel-btn--secondary'],
        ],
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
        '#attributes' => ['class' => ['mel-btn', 'mel-btn--ghost']],
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
        '#attributes' => ['class' => ['mel-btn', 'mel-btn--ghost']],
      ],
      'remove' => [
        '#type' => 'submit',
        '#value' => $this->t('Remove'),
        '#name' => 'ticket_remove_' . $tid,
        '#submit' => ['::handleAction'],
        '#ajax' => [
          'callback' => '::ajaxRebuildTicketBuilder',
          'wrapper' => EventTicketsBuilder::BUILDER_WRAPPER_ID,
        ],
        '#limit_validation_errors' => [],
        '#attributes' => ['class' => ['mel-btn', 'mel-btn--danger']],
        '#access' => !$archive_only,
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

  private function createTicket(FormStateInterface $form_state, NodeInterface $event): void {
    $values = $form_state->getValue($this->valuePath($form_state, 'builder_shell', 'list', 'new', 'fields')) ?? [];
    $payload = $this->normalisePayload($values, $event, TRUE);
    $this->lifecycle->createAttachAndSync($event, $payload);
    $this->messenger->addStatus($this->t('Ticket created.'));
    $form_state->set('mel_ticket_adding_new', FALSE);
    $form_state->set('mel_ticket_prefill_kind', NULL);
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

      $title = trim((string) ($card['title'] ?? ''));
      if ($title === '') {
        throw new \InvalidArgumentException('Ticket title is required.');
      }

      $payload = ['title' => $title];
      $kind = $ticket->getTicketKind();

      if (in_array($kind, ['paid', 'rsvp'], TRUE)) {
        $capacity = (int) ($card['capacity'] ?? 0);
        if ($capacity < 1) {
          throw new \InvalidArgumentException('Capacity must be at least 1.');
        }
        $payload['capacity'] = $capacity;
      }
      elseif (array_key_exists('capacity', $card)) {
        $payload['capacity'] = (int) $card['capacity'];
      }

      if ($kind === 'paid' && array_key_exists('price', $card)) {
        $existing = $ticket->toPriceValue();
        $currency = $existing
          ? $existing->getCurrencyCode()
          : $this->ticketTypeManager->getDefaultCurrencyCodeForEvent($event);
        $num = trim((string) $card['price']);
        if ($num === '' || !is_numeric($num) || (float) $num <= 0) {
          throw new \InvalidArgumentException('Paid tickets require a price greater than zero.');
        }
        $payload['price'] = [
          'number' => $num,
          'currency_code' => $currency,
        ];
      }

      if (array_key_exists('status_published', $card)) {
        $payload['status'] = !empty($card['status_published']) ? 1 : 0;
      }

      if (isset($card['visibility_mode'])) {
        $payload['visibility_mode'] = (string) $card['visibility_mode'];
      }
      if (array_key_exists('hidden_label', $card)) {
        $payload['hidden_label'] = trim((string) $card['hidden_label']);
      }
      if (array_key_exists('short_description', $card)) {
        $payload['short_description'] = $this->normalizeShortDescription((string) ($card['short_description'] ?? ''));
      }
      if (array_key_exists('waitlist_enabled', $card)) {
        $payload['waitlist_enabled'] = !empty($card['waitlist_enabled']) ? 1 : 0;
      }
      if (array_key_exists('waitlist_capacity', $card)) {
        $v = trim((string) $card['waitlist_capacity']);
        $payload['waitlist_capacity'] = $v === '' ? NULL : max(0, (int) $v);
      }
      if (array_key_exists('auto_promote_waitlist', $card)) {
        $payload['auto_promote_waitlist'] = !empty($card['auto_promote_waitlist']) ? 1 : 0;
      }
      if (isset($card['group_sale_mode'])) {
        $payload['group_sale_mode'] = (string) $card['group_sale_mode'];
      }
      if (array_key_exists('group_min_size', $card)) {
        $v = trim((string) $card['group_min_size']);
        $payload['group_min_size'] = $v === '' ? NULL : max(0, (int) $v);
      }
      if (array_key_exists('group_bundle_size', $card)) {
        $v = trim((string) $card['group_bundle_size']);
        $payload['group_bundle_size'] = $v === '' ? NULL : max(0, (int) $v);
      }

      $this->applyPayloadToExistingTicket($ticket, $payload);
      $this->lifecycle->updateTicketType($ticket, $event);
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

      $this->messenger->addError($this->t('We couldn’t save this ticket. Please check your details and try again.'));
    }

    if ($success) {
      $form_state->set('editing_ticket_id', NULL);
    }
  }

  /**
   * @param array<string, mixed> $payload
   *   Output-shaped array from normalisePayload().
   */
  private function applyPayloadToExistingTicket(TicketTypeInterface $ticket, array $payload): void {
    if (isset($payload['title'])) {
      $ticket->setTitle($payload['title']);
    }
    if (isset($payload['ticket_kind'])) {
      $ticket->set('ticket_kind', $payload['ticket_kind']);
    }
    if (array_key_exists('status', $payload)) {
      $ticket->set('status', (int) $payload['status']);
    }
    if (isset($payload['capacity'])) {
      $ticket->set('capacity', (int) $payload['capacity']);
    }
    if (isset($payload['price'])) {
      $ticket->set('price', [[
        'number' => (string) $payload['price']['number'],
        'currency_code' => (string) $payload['price']['currency_code'],
      ]]);
    }
    if (isset($payload['external_url'])) {
      $row = $payload['external_url'];
      $ticket->set('external_url', [[
        'uri' => $row['uri'],
        'title' => $row['title'] ?? '',
      ]]);
    }
    if (isset($payload['rsvp_limit'])) {
      $ticket->set('rsvp_limit', (int) $payload['rsvp_limit']);
    }
    if (array_key_exists('sale_start', $payload)) {
      $v = $payload['sale_start'];
      $ticket->set('sale_start', ($v !== NULL && $v !== '') ? ['value' => $v] : NULL);
    }
    if (array_key_exists('sale_end', $payload)) {
      $v = $payload['sale_end'];
      $ticket->set('sale_end', ($v !== NULL && $v !== '') ? ['value' => $v] : NULL);
    }
    if (isset($payload['visibility_mode']) && $ticket->hasField('visibility_mode')) {
      $ticket->set('visibility_mode', $payload['visibility_mode']);
    }
    if (array_key_exists('hidden_label', $payload) && $ticket->hasField('hidden_label')) {
      $v = $payload['hidden_label'];
      $ticket->set('hidden_label', $v === '' ? NULL : $v);
    }
    if (array_key_exists('short_description', $payload) && $ticket->hasField('short_description')) {
      $v = $payload['short_description'];
      $ticket->set('short_description', ($v === NULL || $v === '') ? NULL : (string) $v);
    }
    if (array_key_exists('waitlist_enabled', $payload) && $ticket->hasField('waitlist_enabled')) {
      $ticket->set('waitlist_enabled', (bool) $payload['waitlist_enabled']);
    }
    if (array_key_exists('waitlist_capacity', $payload) && $ticket->hasField('waitlist_capacity')) {
      $v = $payload['waitlist_capacity'];
      $ticket->set('waitlist_capacity', ($v === NULL || $v === '') ? NULL : (int) $v);
    }
    if (array_key_exists('auto_promote_waitlist', $payload) && $ticket->hasField('auto_promote_waitlist')) {
      $ticket->set('auto_promote_waitlist', (bool) $payload['auto_promote_waitlist']);
    }
    if (isset($payload['group_sale_mode']) && $ticket->hasField('group_sale_mode')) {
      $ticket->set('group_sale_mode', $payload['group_sale_mode']);
    }
    if (array_key_exists('group_min_size', $payload) && $ticket->hasField('group_min_size')) {
      $v = $payload['group_min_size'];
      $ticket->set('group_min_size', ($v === NULL || $v === '') ? NULL : (int) $v);
    }
    if (array_key_exists('group_bundle_size', $payload) && $ticket->hasField('group_bundle_size')) {
      $v = $payload['group_bundle_size'];
      $ticket->set('group_bundle_size', ($v === NULL || $v === '') ? NULL : (int) $v);
    }
  }

  private function removeTicket(NodeInterface $event, string $name): void {
    $tid = (int) str_replace('ticket_remove_', '', $name);
    $this->lifecycle->detachTicketFromEvent($event, $tid);
    $this->messenger->addStatus($this->t('Ticket removed from this event.'));
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
    if ($ticket->hasField('waitlist_enabled')) {
      $payload['waitlist_enabled'] = $ticket->get('waitlist_enabled')->value ? 1 : 0;
    }
    if ($ticket->hasField('waitlist_capacity') && !$ticket->get('waitlist_capacity')->isEmpty()) {
      $payload['waitlist_capacity'] = (int) $ticket->get('waitlist_capacity')->value;
    }
    if ($ticket->hasField('auto_promote_waitlist')) {
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

  private function saveAndSync(NodeInterface $event): void {
    $this->lifecycle->syncPaidTiers($event);
    $this->messenger->addStatus($this->t('Tickets saved and synced.'));
  }

  private function normalisePayload(array $values, NodeInterface $event, bool $is_new): array {
    $kind = (string) ($values['ticket_kind'] ?? 'paid');
    $title = trim((string) ($values['title'] ?? ''));

    if ($title === '') {
      throw new \InvalidArgumentException('Ticket title is required.');
    }

    $payload = [
      'title' => $title,
      'ticket_kind' => $kind,
      'vendor_id' => ['target_id' => (int) $this->currentUser->id()],
      'status' => array_key_exists('status', $values)
        ? (!empty($values['status']) ? 1 : 0)
        : 1,
    ];

    $short_desc = $this->normalizeShortDescription((string) ($values['short_description'] ?? ''));
    if ($short_desc !== NULL) {
      $payload['short_description'] = $short_desc;
    }

    if ($is_new || $kind !== 'external') {
      $payload['event'] = ['target_id' => (int) $event->id()];
    }
    $payload['is_reusable'] = FALSE;

    if (in_array($kind, ['paid', 'rsvp'], TRUE)) {
      $capacity = (int) ($values['capacity'] ?? 0);
      if ($capacity < 1) {
        throw new \InvalidArgumentException('Capacity must be at least 1.');
      }
      $payload['capacity'] = $capacity;
    }

    if ($kind === 'paid') {
      $amount = trim((string) ($values['price_amount'] ?? ''));
      $currency = strtoupper(trim((string) ($values['price_currency'] ?? '')));
      if ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
        throw new \InvalidArgumentException('Paid tickets require a price greater than zero.');
      }
      if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        throw new \InvalidArgumentException('Enter a valid 3-letter currency code.');
      }
      $payload['price'] = [
        'number' => $amount,
        'currency_code' => $currency,
      ];
    }

    if ($kind === 'external') {
      $uri = trim((string) ($values['external_uri'] ?? ''));
      if ($uri === '' || !str_starts_with(strtolower($uri), 'https://')) {
        throw new \InvalidArgumentException('External tickets require a valid https URL.');
      }
      $payload['external_url'] = [
        'uri' => $uri,
        'title' => '',
      ];
    }

    if ($kind === 'rsvp' && !empty($values['rsvp_limit'])) {
      $payload['rsvp_limit'] = (int) $values['rsvp_limit'];
    }

    foreach (['sale_start', 'sale_end'] as $key) {
      if (!empty($values[$key]) && $values[$key] instanceof DrupalDateTime) {
        $payload[$key] = $values[$key]->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
      }
    }

    return $payload;
  }

  /**
   * Human-readable price line for card header (escaped HTML string).
   */
  private function formatPrice(TicketTypeInterface $ticket): string {
    $kind = $ticket->getTicketKind();
    if ($kind === 'paid') {
      $price = $ticket->toPriceValue();
      if ($price) {
        $formatted = $price->getCurrencyCode() . ' ' . $this->formatPriceNumberForDisplay((string) $price->getNumber());
        return Html::escape($formatted);
      }
      return Html::escape((string) $this->t('—'));
    }
    if ($kind === 'rsvp') {
      return Html::escape((string) $this->t('$0'));
    }
    return Html::escape((string) $this->t('External'));
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
    ];
    return in_array($status, $allowed, TRUE) ? $status : TicketStatusService::STATUS_INACTIVE;
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
    $remaining = $rollup['total_remaining'];
    $remDisplay = $remaining === NULL ? '—' : (string) $remaining;

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
    $capDisplay = ($capacity !== NULL && $capacity > 0) ? (string) $capacity : '—';
    $remaining = $metrics['remaining'];
    $remDisplay = $remaining === NULL ? '—' : (string) $remaining;
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
    if ($ticket->hasField('waitlist_enabled') && $ticket->get('waitlist_enabled')->value) {
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

  /**
   * Normalises optional buyer-facing copy (plain text, max length).
   */
  private function normalizeShortDescription(string $raw): ?string {
    $t = trim($raw);
    if ($t === '') {
      return NULL;
    }
    if (mb_strlen($t) > self::SHORT_DESCRIPTION_MAX_LENGTH) {
      $t = mb_substr($t, 0, self::SHORT_DESCRIPTION_MAX_LENGTH);
    }
    return $t;
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