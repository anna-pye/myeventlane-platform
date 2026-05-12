<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_event\Service\TicketTierLifecycleService;
use Drupal\myeventlane_event_studio\Service\EventStudioAutosaveService;
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

  private EntityTypeManagerInterface $studioEntityTypeManager;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    private readonly AccountProxyInterface $currentUser,
    private readonly EventVendorAccessChecker $eventVendorAccessChecker,
    private readonly TicketTierLifecycleService $ticketTierLifecycle,
    private readonly EventStudioAutosaveService $autosaveService,
    private readonly LoggerInterface $logger,
  ) {
    $this->studioEntityTypeManager = $entity_type_manager;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('myeventlane_vendor.event_access_checker'),
      $container->get('myeventlane_event.ticket_tier_lifecycle'),
      $container->get('myeventlane_event_studio.autosave'),
      $container->get('logger.factory')->get('myeventlane_event_studio'),
    );
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
        '#value' => $this->t('Ticket operations'),
        '#attributes' => ['class' => ['mel-es-card__title']],
      ],
      'copy' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Manage ticket rows independently. Saves here update MEL ticket types and let the existing lifecycle service sync Commerce projections.'),
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
        '#attributes' => ['class' => ['mel-event-studio-ticket-empty']],
        'copy' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('No tickets yet. Add a row below and save tickets.'),
        ],
      ];
    }

    $form['new_ticket'] = [
      '#type' => 'details',
      '#title' => $this->t('Add ticket'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['mel-es-card', 'mel-event-studio-ticket-add']],
      'ticket_kind' => [
        '#type' => 'select',
        '#title' => $this->t('Type'),
        '#options' => [
          'paid' => $this->t('Paid'),
          'rsvp' => $this->t('RSVP'),
          'external' => $this->t('External'),
        ],
        '#default_value' => 'paid',
      ],
      'title' => [
        '#type' => 'textfield',
        '#title' => $this->t('Ticket name'),
        '#maxlength' => 255,
      ],
      'price_amount' => [
        '#type' => 'number',
        '#title' => $this->t('Price'),
        '#min' => 0,
        '#step' => 0.01,
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
        '#description' => $this->t('Leave empty for unlimited.'),
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
        '#title' => $this->t('Visibility'),
        '#options' => $this->visibilityOptions(),
        '#default_value' => 'public',
      ],
      'status' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Active'),
        '#default_value' => TRUE,
      ],
      'field_is_best_value' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Best value'),
        '#description' => $this->t('Use when there is more than one paid or RSVP ticket.'),
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save and sync tickets'),
        '#button_type' => 'primary',
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
      if (!empty($row['archive'])) {
        continue;
      }
      $ticket = $this->ticketTierLifecycle->loadWritableTicketForEvent($event, $ticket_id, $this->currentUser());
      if (!$ticket instanceof TicketTypeInterface) {
        $form_state->setErrorByName('tickets][' . $ticket_id, $this->t('Ticket data could not be matched to this event. Reload and try again.'));
        continue;
      }
      try {
        $this->ticketTierLifecycle->buildTicketUpdateValuesFromInput($event, $ticket, $this->currentUser(), $row);
      }
      catch (\InvalidArgumentException $e) {
        $form_state->setErrorByName('tickets][' . $ticket_id, $e->getMessage());
      }
    }

    $new = $form_state->getValue('new_ticket');
    if (is_array($new) && trim((string) ($new['title'] ?? '')) !== '') {
      try {
        $this->ticketTierLifecycle->buildTicketValuesFromInput($event, $this->currentUser(), $new);
      }
      catch (\InvalidArgumentException $e) {
        $form_state->setErrorByName('new_ticket', $e->getMessage());
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
        $ticket = $this->ticketTierLifecycle->loadWritableTicketForEvent($event, $ticket_id, $this->currentUser());
        if (!$ticket instanceof TicketTypeInterface) {
          continue;
        }
        if (!empty($row['archive'])) {
          $this->ticketTierLifecycle->archiveTicketOnEvent($event, $ticket);
          continue;
        }
        $values = $this->ticketTierLifecycle->buildTicketUpdateValuesFromInput($event, $ticket, $this->currentUser(), $row);
        $this->ticketTierLifecycle->updateTicketType($ticket, $event, $values);
      }

      $new = $form_state->getValue('new_ticket');
      if (is_array($new) && trim((string) ($new['title'] ?? '')) !== '') {
        $values = $this->ticketTierLifecycle->buildTicketValuesFromInput($event, $this->currentUser(), $new);
        $this->ticketTierLifecycle->createAttachAndSync($event, $values);
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

    $this->messenger()->addStatus($this->t('Tickets saved and synced.'));
    $form_state->setRebuild(TRUE);
  }

  /**
   * @return array<string, mixed>
   */
  private function buildExistingTicketRow(TicketTypeInterface $ticket): array {
    $kind = $ticket->getTicketKind();
    $price = $ticket->toPriceValue();
    $status = $ticket->isPublished() ? $this->t('Active') : $this->t('Inactive');
    if ($ticket->isArchived()) {
      $status = $this->t('Archived');
    }

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
      'title' => [
        '#type' => 'textfield',
        '#title' => $this->t('Ticket Name'),
        '#default_value' => $ticket->getTitle(),
        '#maxlength' => 255,
        '#wrapper_attributes' => ['class' => ['mel-event-studio-ticket-card__identity']],
      ],
      'price_amount' => [
        '#type' => 'number',
        '#title' => $this->t('Price'),
        '#min' => 0,
        '#step' => 0.01,
        '#default_value' => $price?->getNumber() ?? '',
        '#disabled' => $kind !== 'paid',
        '#wrapper_attributes' => ['class' => ['mel-event-studio-ticket-card__pricing']],
      ],
      'capacity' => [
        '#type' => 'number',
        '#title' => $this->t('Capacity'),
        '#min' => 1,
        '#step' => 1,
        '#default_value' => $ticket->get('capacity')->isEmpty() ? '' : (string) $ticket->get('capacity')->value,
        '#wrapper_attributes' => ['class' => ['mel-event-studio-ticket-card__capacity']],
      ],
      'sales_window' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-studio-ticket-card__sales-window']],
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
        ],
        'sale_end' => [
          '#type' => 'datetime',
          '#title' => $this->t('Sales end'),
          '#default_value' => $this->dateFieldDefault($ticket, 'sale_end'),
        ],
      ],
      'visibility_mode' => [
        '#type' => 'select',
        '#title' => $this->t('Visibility'),
        '#options' => $this->visibilityOptions(),
        '#default_value' => $ticket->hasField('visibility_mode') && !$ticket->get('visibility_mode')->isEmpty()
          ? (string) $ticket->get('visibility_mode')->value
          : 'public',
        '#wrapper_attributes' => ['class' => ['mel-event-studio-ticket-card__visibility']],
      ],
      'status' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-studio-ticket-card__status']],
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
        ],
        'best_value' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Best value'),
          '#default_value' => $ticket->hasField('field_is_best_value') && $ticket->isBestValueTicket(),
          '#disabled' => $ticket->isArchived() || !$ticket->hasField('field_is_best_value'),
          '#wrapper_attributes' => ['class' => ['mel-event-studio-ticket-card__checkbox']],
        ],
      ],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-event-studio-ticket-card__actions']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h4',
          '#value' => $this->t('Actions'),
          '#attributes' => ['class' => ['mel-event-studio-ticket-card__group-title']],
        ],
        'ticket_kind' => [
          '#type' => 'hidden',
          '#value' => $kind,
        ],
        'archive' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Archive'),
          '#disabled' => $ticket->isArchived(),
          '#wrapper_attributes' => ['class' => ['mel-event-studio-ticket-card__checkbox']],
        ],
      ],
    ];
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
      $row['status'] = !empty($row['status']['published']) ? 1 : 0;
      $row['field_is_best_value'] = !empty($row['status']['best_value']) ? 1 : 0;
      if (isset($row['sales_window']) && is_array($row['sales_window'])) {
        $row['sale_start'] = $row['sales_window']['sale_start'] ?? NULL;
        $row['sale_end'] = $row['sales_window']['sale_end'] ?? NULL;
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
    $event = $this->studioEntityTypeManager->getStorage('node')->load($event_id);
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

}
