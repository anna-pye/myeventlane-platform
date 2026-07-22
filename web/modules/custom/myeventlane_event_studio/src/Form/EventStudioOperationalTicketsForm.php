<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_commerce\Service\TicketTierAnalyticsService;
use Drupal\myeventlane_event\Service\TicketTierLifecycleService;
use Drupal\myeventlane_event_studio\Service\EventStudioAutosaveService;
use Drupal\myeventlane_event_studio\Service\EventStudioSaveService;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Operational Event Studio ticket table backed by mel_ticket_type entities.
 */
final class EventStudioOperationalTicketsForm extends FormBase {

  /**
   * Mel keys submitted with Save tickets for RSVP donation settings.
   *
   * @var list<string>
   */
  private const DONATION_MEL_KEYS = [
    'enable_donations',
    'donation_amount',
    'donation_options',
    'donation_label',
  ];

  /**
   * Injected services must be protected (not private readonly) so form cache
   * serialization can restore them; see {@see ensureInjectedServices()}.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  protected AccountProxyInterface $currentUser;

  protected EventVendorAccessChecker $eventVendorAccessChecker;

  protected TicketTierLifecycleService $ticketTierLifecycle;

  protected EventStudioAutosaveService $autosaveService;

  protected EventStudioSaveService $saveService;

  protected LoggerInterface $logger;

  protected ?TicketTierAnalyticsService $ticketTierAnalytics = NULL;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    EventVendorAccessChecker $event_vendor_access_checker,
    TicketTierLifecycleService $ticket_tier_lifecycle,
    EventStudioAutosaveService $autosave_service,
    EventStudioSaveService $save_service,
    LoggerInterface $logger,
    ?TicketTierAnalyticsService $ticket_tier_analytics = NULL,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->eventVendorAccessChecker = $event_vendor_access_checker;
    $this->ticketTierLifecycle = $ticket_tier_lifecycle;
    $this->autosaveService = $autosave_service;
    $this->saveService = $save_service;
    $this->logger = $logger;
    $this->ticketTierAnalytics = $ticket_tier_analytics;
  }

  public static function create(ContainerInterface $container): static {
    $form = new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('myeventlane_vendor.event_access_checker'),
      $container->get('myeventlane_event.ticket_tier_lifecycle'),
      $container->get('myeventlane_event_studio.autosave'),
      $container->get('myeventlane_event_studio.save'),
      $container->get('logger.factory')->get('myeventlane_event_studio'),
      $container->has('myeventlane_commerce.ticket_tier_analytics')
        ? $container->get('myeventlane_commerce.ticket_tier_analytics')
        : NULL,
    );
    $form->setRequestStack($container->get('request_stack'));
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function __wakeup(): void {
    parent::__wakeup();
    $this->ensureInjectedServices();
  }

  /**
   * Ensures services are present after form cache unserialization.
   *
   * Cached form state restores the form object in build_info. New typed
   * properties or untracked services can otherwise stay uninitialized.
   */
  private function ensureInjectedServices(): void {
    if (isset(
      $this->entityTypeManager,
      $this->currentUser,
      $this->eventVendorAccessChecker,
      $this->ticketTierLifecycle,
      $this->autosaveService,
      $this->saveService,
      $this->logger,
    )) {
      return;
    }
    $container = \Drupal::getContainer();
    if (!isset($this->entityTypeManager)) {
      $this->entityTypeManager = $container->get('entity_type.manager');
    }
    if (!isset($this->currentUser)) {
      $this->currentUser = $container->get('current_user');
    }
    if (!isset($this->eventVendorAccessChecker)) {
      $this->eventVendorAccessChecker = $container->get('myeventlane_vendor.event_access_checker');
    }
    if (!isset($this->ticketTierLifecycle)) {
      $this->ticketTierLifecycle = $container->get('myeventlane_event.ticket_tier_lifecycle');
    }
    if (!isset($this->autosaveService)) {
      $this->autosaveService = $container->get('myeventlane_event_studio.autosave');
    }
    if (!isset($this->saveService)) {
      $this->saveService = $container->get('myeventlane_event_studio.save');
    }
    if (!isset($this->logger)) {
      $this->logger = $container->get('logger.factory')->get('myeventlane_event_studio');
    }
    if (!isset($this->ticketTierAnalytics) && $container->has('myeventlane_commerce.ticket_tier_analytics')) {
      $this->ticketTierAnalytics = $container->get('myeventlane_commerce.ticket_tier_analytics');
    }
  }

  public function getFormId(): string {
    return 'myeventlane_event_studio_operational_tickets';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $event = $this->getRouteEvent($node);
    $this->assertCanManageEvent($event);

    $form['#tree'] = TRUE;
    $form['#attributes']['class'][] = 'mel-event-studio-operational-tickets';
    $form['event_id'] = [
      '#type' => 'hidden',
      '#value' => (string) $event->id(),
    ];
    $form['mel_studio_changed'] = [
      '#type' => 'hidden',
      '#value' => (string) $event->getChangedTime(),
    ];
    $form['mel_studio_revision'] = [
      '#type' => 'hidden',
      '#value' => (string) (int) $event->getRevisionId(),
    ];

    $form['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-es-card', 'mel-event-studio-ticket-intro']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Tickets'),
        '#attributes' => ['class' => ['mel-es-card__title']],
      ],
      'copy' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Create the ticket types people can book — General Admission, VIP, Early Bird, Donation, and more. Focus on pricing, capacity, and availability. Advanced tools stay tucked away until you need them.'),
        '#attributes' => ['class' => ['mel-es-card__hint']],
      ],
    ];

    $form['tickets'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => [
        'class' => ['mel-event-studio-ticket-table', 'mel-event-studio-ticket-cards'],
        'role' => 'list',
      ],
    ];

    $tickets = $this->ticketTierLifecycle->loadOrderedTicketsForEvent($event);
    foreach ($tickets as $ticket) {
      $ticket_id = (int) $ticket->id();
      $form['tickets'][$ticket_id] = $this->buildExistingTicketRow($ticket);
    }

    if ($tickets === []) {
      $form['tickets']['empty'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => [
            'mel-event-studio-ticket-empty',
            'mel-event-studio-ticket-empty--guided',
          ],
          'role' => 'region',
          'aria-labelledby' => 'mel-tickets-empty-title',
        ],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h4',
          '#value' => $this->t('Add your first ticket'),
          '#attributes' => [
            'id' => 'mel-tickets-empty-title',
            'class' => ['mel-event-studio-ticket-empty__title'],
          ],
        ],
        'why' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Tickets tell guests how they can join — and let you start selling straight away.'),
          '#attributes' => ['class' => ['mel-event-studio-ticket-empty__lead']],
        ],
        'next' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Next, choose a name, set a price (or keep it free), and decide how many spots you have.'),
        ],
        'modes' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Free RSVP collects names without payment. Paid tickets take card payments through MyEventLane. You can mix both later if you need to.'),
          '#attributes' => ['class' => ['mel-event-studio-ticket-empty__modes']],
        ],
      ];
    }

    $form['new_ticket'] = [
      '#type' => 'details',
      '#title' => $this->t('Add Ticket'),
      '#open' => $tickets === [],
      '#attributes' => [
        'class' => [
          'mel-es-card',
          'mel-event-studio-ticket-add',
          'mel-event-studio-ticket-add--primary',
        ],
        'id' => 'mel-add-ticket',
      ],
      'intro' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Start simple. You can adjust capacity, visibility, and timing after the ticket exists.'),
        '#attributes' => ['class' => ['mel-event-studio-ticket-add__helper']],
      ],
      'ticket_kind' => [
        '#type' => 'select',
        '#title' => $this->t('Type'),
        '#options' => [
          'paid' => $this->t('Paid'),
          'rsvp' => $this->t('Free RSVP'),
          'external' => $this->t('External'),
        ],
        '#default_value' => 'paid',
      ],
      'title' => [
        '#type' => 'textfield',
        '#title' => $this->t('Ticket name'),
        '#required' => FALSE,
        '#maxlength' => 255,
        '#attributes' => [
          'placeholder' => $this->t('General Admission, VIP, Early Bird…'),
        ],
        '#description' => $this->t('Example: General Admission, VIP, Early Bird, Donation.'),
      ],
      'price_amount' => [
        '#type' => 'number',
        '#title' => $this->t('Price'),
        '#min' => 0,
        '#step' => 0.01,
        '#description' => $this->t('Enter the attendee price. Use Free RSVP above for no payment.'),
        '#states' => [
          'visible' => [
            ':input[name="new_ticket[ticket_kind]"]' => ['value' => 'paid'],
          ],
        ],
      ],
      'capacity' => [
        '#type' => 'number',
        '#title' => $this->t('Capacity'),
        '#min' => 1,
        '#step' => 1,
        '#description' => $this->t('Optional. Leave empty if there is no fixed limit.'),
      ],
      'external_uri' => [
        '#type' => 'url',
        '#title' => $this->t('External URL'),
        '#states' => [
          'visible' => [
            ':input[name="new_ticket[ticket_kind]"]' => ['value' => 'external'],
          ],
        ],
      ],
      'visibility_mode' => [
        '#type' => 'select',
        '#title' => $this->t('Availability'),
        '#options' => $this->visibilityOptions(),
        '#default_value' => 'public',
        '#description' => $this->t('Access code tickets need codes from Advanced Ticket Tools before guests can see them.'),
      ],
      'status' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Active'),
        '#default_value' => TRUE,
      ],
      'field_is_best_value' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Best value'),
        '#description' => $this->t('Optional. Use only when one offer should be gently highlighted.'),
      ],
    ];

    $form['sticky_add'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-event-studio-ticket-sticky-add'],
      ],
      'cta' => [
        '#type' => 'html_tag',
        '#tag' => 'a',
        '#value' => $this->t('Add Ticket'),
        '#attributes' => [
          'href' => '#mel-add-ticket',
          'class' => [
            'mel-btn',
            'mel-btn--primary',
            'mel-event-studio-ticket-sticky-add__button',
          ],
        ],
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save tickets'),
        '#button_type' => 'primary',
        '#attributes' => [
          'class' => ['mel-event-studio-ticket-save'],
        ],
      ],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $event = $this->loadSubmittedEvent($form_state);
    if (!$event instanceof NodeInterface) {
      $form_state->setErrorByName('', $this->t('The event could not be loaded.'));
      return;
    }
    $baseChanged = (int) ($form_state->getValue('mel_studio_changed') ?? 0);
    $baseRevisionId = (int) ($form_state->getValue('mel_studio_revision') ?? 0);
    if ($this->autosaveService->isStaleSubmission($event, $baseChanged, $baseRevisionId)) {
      $form_state->setErrorByName('', $this->t('This section was updated elsewhere. Refresh to continue editing safely.'));
      return;
    }

    foreach ($this->submittedExistingTicketRows($form_state) as $ticket_id => $row) {
      if (!empty($row['archive']) || !empty($row['duplicate'])) {
        continue;
      }
      $ticket = $this->ticketTierLifecycle->loadWritableTicketForEvent($event, $ticket_id, $this->currentUser);
      if (!$ticket instanceof TicketTypeInterface) {
        $form_state->setErrorByName('tickets][' . $ticket_id, $this->t('Ticket data could not be matched to this event. Reload and try again.'));
        continue;
      }
      $row = $this->normalizeExistingTicketRowInput($ticket, $row);
      try {
        $this->ticketTierLifecycle->buildTicketUpdateValuesFromInput($event, $ticket, $this->currentUser, $row);
      }
      catch (\InvalidArgumentException $e) {
        $form_state->setErrorByName(
          $this->ticketValidationErrorElement((int) $ticket_id, $e),
          $this->mapTicketInputExceptionMessage($e),
        );
      }
    }

    $new = $form_state->getValue('new_ticket');
    if (is_array($new) && $this->newTicketRowHasIntent($new)) {
      if (trim((string) ($new['title'] ?? '')) === '') {
        $form_state->setErrorByName('new_ticket][title', $this->t('Ticket name is required when adding a ticket.'));
      }
      else {
        try {
          $this->ticketTierLifecycle->buildTicketValuesFromInput($event, $this->currentUser, $new);
        }
        catch (\InvalidArgumentException $e) {
          $form_state->setErrorByName('new_ticket][title', $this->mapTicketInputExceptionMessage($e));
        }
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event = $this->loadSubmittedEvent($form_state);
    if (!$event instanceof NodeInterface) {
      $this->messenger()->addError($this->t('The event could not be loaded.'));
      return;
    }
    $this->assertCanManageEvent($event);

    try {
      foreach ($this->submittedExistingTicketRows($form_state) as $ticket_id => $row) {
        $ticket = $this->ticketTierLifecycle->loadWritableTicketForEvent($event, $ticket_id, $this->currentUser);
        if (!$ticket instanceof TicketTypeInterface) {
          continue;
        }
        if (!empty($row['duplicate'])) {
          $copy = $this->ticketTierLifecycle->duplicateTicketOnEvent($event, $ticket, $this->currentUser);
          $this->recordTicketAnalyticsEvent('ticket_created', $event, (int) $copy->id(), [
            'source' => 'duplicate',
            'source_ticket_id' => (int) $ticket->id(),
          ]);
          continue;
        }
        if (!empty($row['archive'])) {
          $this->ticketTierLifecycle->archiveTicketOnEvent($event, $ticket);
          $this->recordTicketAnalyticsEvent('ticket_archived', $event, (int) $ticket->id());
          continue;
        }
        $row = $this->normalizeExistingTicketRowInput($ticket, $row);
        $values = $this->ticketTierLifecycle->buildTicketUpdateValuesFromInput($event, $ticket, $this->currentUser, $row);
        $this->ticketTierLifecycle->updateTicketType($ticket, $event, $values);
        // Instrumentation hook reserved for material field diffs / single-ticket APIs.
        $this->recordTicketAnalyticsEvent('ticket_updated', $event, (int) $ticket->id(), [
          'source' => 'workspace_tickets_save',
        ]);
      }

      $new = $form_state->getValue('new_ticket');
      if (is_array($new) && $this->newTicketRowHasIntent($new) && trim((string) ($new['title'] ?? '')) !== '') {
        $values = $this->ticketTierLifecycle->buildTicketValuesFromInput($event, $this->currentUser, $new);
        $created = $this->ticketTierLifecycle->createAttachAndSync($event, $values);
        $this->recordTicketAnalyticsEvent('ticket_created', $event, (int) $created->id());
      }

      $this->ticketTierLifecycle->reconcileEventTicketReferences($event);
    }
    catch (\Throwable $e) {
      $this->logger->error('Event Studio operational ticket save failed for event @nid: @message', [
        '@nid' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Tickets could not be saved. Check the rows and try again.'));
      return;
    }

    $this->persistDonationSettingsFromWorkspace($event);

    $this->messenger()->addStatus($this->t('Tickets saved and synced.'));
    $form_state->setRebuild(TRUE);
  }

  /**
   * @return array<string, mixed>
   */
  private function buildExistingTicketRow(TicketTypeInterface $ticket): array {
    $ticket_id = (int) $ticket->id();
    $kind = $ticket->getTicketKind();
    $price = $ticket->toPriceValue();
    $status = $ticket->isPublished() ? $this->t('Active') : $this->t('Inactive');
    if ($ticket->isArchived()) {
      $status = $this->t('Archived');
    }
    $price_summary = $kind === 'paid' && $price !== NULL
      ? $this->t('$@amount', ['@amount' => rtrim(rtrim(number_format((float) $price->getNumber(), 2), '0'), '.')])
      : $this->t('Free RSVP');
    $status_summary = match (TRUE) {
      $ticket->isArchived() => $this->t('Archived'),
      $ticket->isPublished() => $this->t('Selling now'),
      default => $this->t('Draft'),
    };
    $capacity_summary = $ticket->get('capacity')->isEmpty()
      ? $this->t('No fixed capacity')
      : $this->t('@count available', ['@count' => (string) $ticket->get('capacity')->value]);
    $sales_summary = $this->buildTicketSalesSummary($ticket);
    $availability_label = match (TRUE) {
      $ticket->isArchived() => $this->t('Archived'),
      !$ticket->isPublished() => $this->t('Hidden from checkout'),
      default => $this->visibilityOptions()[
        $ticket->hasField('visibility_mode') && !$ticket->get('visibility_mode')->isEmpty()
          ? (string) $ticket->get('visibility_mode')->value
          : 'public'
      ] ?? $this->t('Public'),
    };

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => array_filter([
          'mel-event-studio-ticket-card',
          $ticket->isArchived() ? 'is-archived' : ($ticket->isPublished() ? 'is-active' : 'is-inactive'),
          $ticket->isBestValueTicket() ? 'is-best-value' : NULL,
        ]),
        'role' => 'listitem',
      ],
      'header' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-studio-ticket-card__header']],
        'identity' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-event-studio-ticket-card__identity']],
          'summary' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['mel-event-studio-ticket-card__summary']],
            'price' => [
              '#type' => 'html_tag',
              '#tag' => 'p',
              '#value' => $price_summary,
              '#attributes' => ['class' => ['mel-event-studio-ticket-card__summary-price']],
            ],
            'status' => [
              '#type' => 'html_tag',
              '#tag' => 'p',
              '#value' => $status_summary,
              '#attributes' => ['class' => ['mel-event-studio-ticket-card__summary-status']],
            ],
            'availability' => [
              '#type' => 'html_tag',
              '#tag' => 'p',
              '#value' => $availability_label,
              '#attributes' => ['class' => ['mel-event-studio-ticket-card__summary-availability']],
            ],
            'capacity' => [
              '#type' => 'html_tag',
              '#tag' => 'p',
              '#value' => $capacity_summary,
              '#attributes' => ['class' => ['mel-event-studio-ticket-card__summary-capacity']],
            ],
            'sales' => [
              '#type' => 'html_tag',
              '#tag' => 'p',
              '#value' => $sales_summary,
              '#attributes' => ['class' => ['mel-event-studio-ticket-card__summary-sales']],
            ],
          ],
          'title' => [
            '#type' => 'textfield',
            '#title' => $this->t('Ticket name'),
            '#required' => FALSE,
            '#default_value' => $ticket->getTitle(),
            '#maxlength' => 255,
            '#parents' => ['tickets', $ticket_id, 'title'],
          ],
        ],
        'price' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-event-studio-ticket-card__pricing']],
          'price_amount' => [
            '#type' => 'number',
            '#title' => $this->t('Price'),
            '#min' => 0,
            '#step' => 0.01,
            '#default_value' => $price?->getNumber() ?? '',
            '#disabled' => $kind !== 'paid',
            '#parents' => ['tickets', $ticket_id, 'price_amount'],
          ],
        ],
        'capacity' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-event-studio-ticket-card__capacity']],
          'capacity' => [
            '#type' => 'number',
            '#title' => $this->t('Capacity'),
            '#min' => 1,
            '#step' => 1,
            '#default_value' => $ticket->get('capacity')->isEmpty() ? '' : (string) $ticket->get('capacity')->value,
            '#description' => $this->t('Optional. How many guests can book this offer.'),
            '#parents' => ['tickets', $ticket_id, 'capacity'],
          ],
        ],
        'badges' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-event-studio-ticket-card__badges']],
          'kind' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => match ($kind) {
              'rsvp' => $this->t('Free RSVP'),
              'external' => $this->t('External'),
              default => $this->t('Paid'),
            },
            '#attributes' => ['class' => ['mel-event-studio-card-badge', 'mel-event-studio-card-badge--type']],
          ],
          'status' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $status,
            '#attributes' => ['class' => ['mel-event-studio-card-badge', $ticket->isPublished() && !$ticket->isArchived() ? 'mel-event-studio-card-badge--active' : 'mel-event-studio-card-badge--muted']],
          ],
          'best_value' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $this->t('Best value'),
            '#access' => $ticket->isBestValueTicket(),
            '#attributes' => [
              'class' => ['mel-event-studio-card-badge', 'mel-event-studio-card-badge--accent'],
            ],
          ],
          'access_code' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $this->t('Access code required'),
            '#access' => $ticket->hasField('visibility_mode')
              && !$ticket->get('visibility_mode')->isEmpty()
              && (string) $ticket->get('visibility_mode')->value === 'access_code',
            '#attributes' => [
              'class' => ['mel-event-studio-card-badge', 'mel-event-studio-card-badge--access-code'],
            ],
          ],
        ],
      ],
      'attendee_preview' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-studio-ticket-card__attendee-preview']],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('How attendees will see this'),
          '#attributes' => ['class' => ['mel-event-studio-ticket-card__preview-label']],
        ],
        'name' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => Html::escape($ticket->getTitle()),
          '#attributes' => ['class' => ['mel-event-studio-ticket-card__preview-name']],
        ],
        'details' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-event-studio-ticket-card__preview-details']],
          'price' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $price_summary,
          ],
          'availability' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $status_summary,
          ],
          'capacity' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $capacity_summary,
          ],
          'sales' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#value' => $sales_summary,
          ],
        ],
        'reassurance' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $ticket->isPublished()
            ? $this->t('Nice work — this ticket is ready for attendees.')
            : $this->t('This ticket stays draft-safe until you make it available.'),
          '#attributes' => ['class' => ['mel-event-studio-ticket-card__preview-reassurance']],
        ],
      ],
      'body' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-studio-ticket-card__body']],
        'sales_window' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-event-studio-ticket-card__field-group', 'mel-event-studio-ticket-card__sales-window']],
          'heading' => [
            '#type' => 'html_tag',
            '#tag' => 'h4',
            '#value' => $this->t('Sales window'),
            '#attributes' => ['class' => ['mel-event-studio-ticket-card__group-title']],
          ],
          'sale_start' => [
            '#type' => 'datetime',
            '#title' => $this->t('Sales start'),
            '#default_value' => $this->dateFieldDefault($ticket, 'sale_start'),
            '#parents' => ['tickets', $ticket_id, 'sales_window', 'sale_start'],
          ],
          'sale_end' => [
            '#type' => 'datetime',
            '#title' => $this->t('Sales end'),
            '#default_value' => $this->dateFieldDefault($ticket, 'sale_end'),
            '#parents' => ['tickets', $ticket_id, 'sales_window', 'sale_end'],
          ],
        ],
        'visibility_group' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-event-studio-ticket-card__field-group', 'mel-event-studio-ticket-card__visibility']],
          'heading' => [
            '#type' => 'html_tag',
            '#tag' => 'h4',
            '#value' => $this->t('Availability'),
            '#attributes' => ['class' => ['mel-event-studio-ticket-card__group-title']],
          ],
          'visibility_mode' => [
            '#type' => 'select',
            '#title' => $this->t('Visibility'),
            '#title_display' => 'invisible',
            '#options' => $this->visibilityOptions(),
            '#default_value' => $ticket->hasField('visibility_mode') && !$ticket->get('visibility_mode')->isEmpty()
              ? (string) $ticket->get('visibility_mode')->value
              : 'public',
            '#description' => $this->t('Access code tickets need codes from Advanced Ticket Tools before guests can see them.'),
            '#parents' => ['tickets', $ticket_id, 'visibility_mode'],
          ],
        ],
        'availability_group' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['mel-event-studio-ticket-card__field-group', 'mel-event-studio-ticket-card__status']],
          'heading' => [
            '#type' => 'html_tag',
            '#tag' => 'h4',
            '#value' => $this->t('Status'),
            '#attributes' => ['class' => ['mel-event-studio-ticket-card__group-title']],
          ],
          'published' => [
            '#type' => 'checkbox',
            '#title' => $status,
            '#default_value' => $ticket->isPublished(),
            '#disabled' => $ticket->isArchived(),
            '#wrapper_attributes' => ['class' => ['mel-event-studio-ticket-card__checkbox']],
            '#parents' => ['tickets', $ticket_id, 'status', 'published'],
          ],
          'best_value' => [
            '#type' => 'checkbox',
            '#title' => $this->t('Highlight as best value'),
            '#description' => $this->t('Use sparingly when one ticket should be gently highlighted for attendees.'),
            '#default_value' => $ticket->hasField('field_is_best_value') && $ticket->isBestValueTicket(),
            '#disabled' => $ticket->isArchived() || !$ticket->hasField('field_is_best_value'),
            '#wrapper_attributes' => ['class' => ['mel-event-studio-ticket-card__checkbox']],
            '#parents' => ['tickets', $ticket_id, 'status', 'best_value'],
          ],
        ],
      ],
      'quick_actions' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['mel-event-studio-ticket-card__quick-actions'],
          'aria-label' => $this->t('Quick actions'),
        ],
        'hint' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Quick edit above, then save. Use duplicate or archive when you need a copy or to stop new sales.'),
          '#attributes' => ['class' => ['mel-event-studio-ticket-card__quick-actions-hint']],
        ],
        'duplicate' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Duplicate ticket'),
          '#disabled' => $ticket->isArchived(),
          '#wrapper_attributes' => ['class' => ['mel-event-studio-ticket-card__checkbox', 'mel-event-studio-ticket-card__action']],
          '#parents' => ['tickets', $ticket_id, 'actions', 'duplicate'],
        ],
        'archive' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Archive ticket'),
          '#description' => $this->t('Stops new checkout sales. Past orders stay intact.'),
          '#disabled' => $ticket->isArchived(),
          '#wrapper_attributes' => ['class' => ['mel-event-studio-ticket-card__checkbox', 'mel-event-studio-ticket-card__action']],
          '#parents' => ['tickets', $ticket_id, 'actions', 'archive'],
        ],
      ],
      'advanced' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-studio-ticket-card__advanced-content']],
        '#prefix' => '<details class="mel-event-studio-ticket-card__advanced"><summary>' . $this->t('Optional ticket settings') . '</summary>',
        '#suffix' => '</details>',
        'ticket_kind' => [
          '#type' => 'hidden',
          '#value' => $kind,
          '#parents' => ['tickets', $ticket_id, 'actions', 'ticket_kind'],
        ],
      ],
    ];
  }

  /**
   * Whether the add-ticket row contains values beyond untouched defaults.
   *
   * @param array<string, mixed> $new
   */
  private function newTicketRowHasIntent(array $new): bool {
    if (trim((string) ($new['title'] ?? '')) !== '') {
      return TRUE;
    }
    $price = trim((string) ($new['price_amount'] ?? ''));
    if ($price !== '' && is_numeric($price) && (float) $price > 0) {
      return TRUE;
    }
    $capacity = trim((string) ($new['capacity'] ?? ''));
    if ($capacity !== '' && is_numeric($capacity) && (int) $capacity > 0) {
      return TRUE;
    }
    $external = trim((string) ($new['external_uri'] ?? ''));
    if ($external !== '') {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Keeps saved ticket names when a row is posted without a title field value.
   *
   * @param array<string, mixed> $row
   *
   * @return array<string, mixed>
   */
  private function normalizeExistingTicketRowInput(TicketTypeInterface $ticket, array $row): array {
    $submittedTitle = array_key_exists('title', $row) ? trim((string) $row['title']) : NULL;
    if ($submittedTitle === NULL) {
      $row['title'] = $ticket->getTitle();
      return $row;
    }
    if ($submittedTitle === '') {
      $row['title'] = '';
      return $row;
    }
    $row['title'] = $submittedTitle;
    return $row;
  }

  private function mapTicketInputExceptionMessage(\InvalidArgumentException $exception): string {
    $message = trim($exception->getMessage());
    if ($message === 'Ticket title is required.') {
      return (string) $this->t('Ticket name is required.');
    }
    return $message !== '' ? $message : (string) $this->t('Ticket row could not be validated.');
  }

  private function ticketValidationErrorElement(int $ticket_id, \InvalidArgumentException $exception): string {
    $message = trim($exception->getMessage());
    if (str_contains($message, 'Best value')) {
      return 'tickets][' . $ticket_id . '][status][best_value';
    }
    return 'tickets][' . $ticket_id . '][title';
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  private function submittedExistingTicketRows(FormStateInterface $form_state): array {
    $rows = $form_state->getValue('tickets') ?? [];
    if (!is_array($rows)) {
      return [];
    }
    $out = [];
    foreach ($rows as $ticket_id => $row) {
      if (!is_array($row) || !is_numeric($ticket_id)) {
        continue;
      }
      $row['id'] = (int) $ticket_id;
      $row['price_amount'] = $row['price_amount'] ?? '';
      $status = is_array($row['status'] ?? NULL) ? $row['status'] : [];
      $row['field_is_best_value'] = !empty($status['best_value']) ? 1 : 0;
      $row['status'] = !empty($status['published']) ? 1 : 0;
      if (isset($row['sales_window']) && is_array($row['sales_window'])) {
        $row['sale_start'] = $row['sales_window']['sale_start'] ?? NULL;
        $row['sale_end'] = $row['sales_window']['sale_end'] ?? NULL;
      }
      if (isset($row['actions']) && is_array($row['actions'])) {
        $row['ticket_kind'] = $row['actions']['ticket_kind'] ?? '';
        $row['archive'] = !empty($row['actions']['archive']) ? 1 : 0;
        $row['duplicate'] = !empty($row['actions']['duplicate']) ? 1 : 0;
      }
      $out[(int) $ticket_id] = $row;
    }
    return $out;
  }

  private function dateFieldDefault(TicketTypeInterface $ticket, string $field_name): ?DrupalDateTime {
    if (!$ticket->hasField($field_name) || $ticket->get($field_name)->isEmpty()) {
      return NULL;
    }
    $value = (string) $ticket->get($field_name)->value;
    return $value !== '' ? new DrupalDateTime($value) : NULL;
  }

  /**
   * @return array<string, string>
   */
  private function visibilityOptions(): array {
    return [
      'public' => (string) $this->t('Public'),
      'hidden' => (string) $this->t('Hidden'),
      'access_code' => (string) $this->t('Access code'),
      'group_only' => (string) $this->t('Group / partner only'),
    ];
  }

  private function buildTicketSalesSummary(TicketTypeInterface $ticket): string {
    if (!$this->ticketTierAnalytics instanceof TicketTierAnalyticsService) {
      return (string) $this->t('Sales: —');
    }
    try {
      $metrics = $this->ticketTierAnalytics->buildTierMetrics($ticket);
      $sold = (int) ($metrics['sold'] ?? 0);
      return (string) $this->formatPlural($sold, 'Sales: 1 sold', 'Sales: @count sold');
    }
    catch (\Throwable $e) {
      $this->logger->error('Ticket sales summary failed for ticket @tid: @message', [
        '@tid' => (string) $ticket->id(),
        '@message' => $e->getMessage(),
      ]);
      return (string) $this->t('Sales: —');
    }
  }

  /**
   * Records VX2 ticket instrumentation hooks for future analytics wiring.
   *
   * @param string $event_name
   *   Analytics event name (for example ticket_created).
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param int $ticket_id
   *   Ticket type entity ID.
   * @param array<string, mixed> $context
   *   Extra logger context.
   */
  private function recordTicketAnalyticsEvent(string $event_name, NodeInterface $event, int $ticket_id, array $context = []): void {
    $this->logger->info('MEL ticket analytics hook @event for event @nid ticket @tid.', [
      '@event' => $event_name,
      '@nid' => (string) $event->id(),
      '@tid' => (string) $ticket_id,
      'mel_analytics_event' => $event_name,
      'event_id' => (int) $event->id(),
      'ticket_id' => $ticket_id,
    ] + $context);
  }

  private function getRouteEvent(?NodeInterface $node = NULL): NodeInterface {
    if ($node instanceof NodeInterface) {
      return $node;
    }
    $route_node = $this->getRouteMatch()->getParameter('node');
    if ($route_node instanceof NodeInterface) {
      return $route_node;
    }
    throw new NotFoundHttpException();
  }

  private function loadSubmittedEvent(FormStateInterface $form_state): ?NodeInterface {
    $event_id = (int) ($form_state->getValue('event_id') ?? 0);
    if ($event_id < 1) {
      return NULL;
    }
    $event = $this->entityTypeManager->getStorage('node')->load($event_id);
    return $event instanceof NodeInterface && $event->bundle() === 'event' ? $event : NULL;
  }

  private function assertCanManageEvent(NodeInterface $event): void {
    if ($event->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }
    if (!$this->currentUser->hasPermission('administer nodes')
      && !$this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($event, $this->currentUser)) {
      throw new AccessDeniedHttpException();
    }
  }

  /**
   * Persists RSVP donation settings (donation fields only).
   */
  private function persistDonationSettingsFromWorkspace(NodeInterface $event): void {
    if (!$event->hasField('field_enable_donations')) {
      return;
    }

    if (!$this->eventSupportsRsvpDonationSettings($event)) {
      return;
    }

    $donationMel = $this->extractDonationMelFromRequest();
    if ($donationMel === []) {
      $draft = $this->autosaveService->getDraft($event, 'tickets');
      $draftMel = is_array($draft['mel'] ?? NULL) ? $draft['mel'] : [];
      $donationMel = array_intersect_key($draftMel, array_flip(self::DONATION_MEL_KEYS));
    }
    if ($donationMel === []) {
      return;
    }

    $payload = $this->buildDonationPayloadFromMel($donationMel);
    $result = $this->saveService->saveDonationSettings($event, $payload, $this->currentUser());
    if ($result['errors'] !== []) {
      foreach ($result['errors'] as $message) {
        $this->messenger()->addError($message);
      }
      return;
    }

    if ($result['node'] instanceof NodeInterface) {
      $this->messenger()->addStatus($this->t('Donation settings saved.'));
    }
  }

  /**
   * RSVP-only donation settings must not be overwritten on non-RSVP booking modes.
   */
  private function eventSupportsRsvpDonationSettings(NodeInterface $event): bool {
    if (!$event->hasField('field_event_type') || $event->get('field_event_type')->isEmpty()) {
      return TRUE;
    }
    return (string) $event->get('field_event_type')->value === 'rsvp';
  }

  /**
   * @param array<string, mixed> $mel
   *
   * @return array<string, mixed>
   */
  private function buildDonationPayloadFromMel(array $mel): array {
    $enabled = !empty($mel['enable_donations']) && (string) ($mel['enable_donations'] ?? '') !== '0';
    return [
      'enable_donations' => $enabled,
      'donation_enabled' => $enabled,
      'donation_amount' => ($mel['donation_amount'] ?? '') !== '' ? (string) $mel['donation_amount'] : NULL,
      'donation_options' => trim((string) ($mel['donation_options'] ?? '')),
      'donation_label' => trim((string) ($mel['donation_label'] ?? 'Support this event')) ?: 'Support this event',
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function extractDonationMelFromRequest(): array {
    $request = $this->getRequest();
    if ($request === NULL) {
      return [];
    }
    $mel = $request->request->all('mel');
    if (!is_array($mel)) {
      return [];
    }
    return array_intersect_key($mel, array_flip(self::DONATION_MEL_KEYS));
  }

}
