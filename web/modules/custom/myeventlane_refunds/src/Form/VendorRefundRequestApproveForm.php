<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Form;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Policy\DirectChargeCopy;
use Drupal\myeventlane_refunds\Service\RefundAccessResolver;
use Drupal\myeventlane_refunds\Service\RefundOrderInspector;
use Drupal\myeventlane_refunds\Service\RefundProcessor;
use Drupal\myeventlane_refunds\Service\RefundRequestStorage;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Form for vendor to approve a buyer refund request.
 */
final class VendorRefundRequestApproveForm extends FormBase {

  /**
   * The refund processor.
   */
  protected RefundProcessor $refundProcessor;

  /**
   * The refund request storage service.
   */
  protected RefundRequestStorage $refundRequestStorage;

  /**
   * The refund access resolver.
   */
  protected RefundAccessResolver $accessResolver;

  /**
   * The refund order inspector.
   */
  protected RefundOrderInspector $orderInspector;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs VendorRefundRequestApproveForm.
   */
  public function __construct(
    RefundProcessor $refundProcessor,
    RefundRequestStorage $refundRequestStorage,
    RefundAccessResolver $accessResolver,
    RefundOrderInspector $orderInspector,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->refundProcessor = $refundProcessor;
    $this->refundRequestStorage = $refundRequestStorage;
    $this->accessResolver = $accessResolver;
    $this->orderInspector = $orderInspector;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_refunds.processor'),
      $container->get('myeventlane_refunds.refund_request_storage'),
      $container->get('myeventlane_refunds.access_resolver'),
      $container->get('myeventlane_refunds.order_inspector'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_refunds_organiser_approve_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'myeventlane_refunds/mel_refund_ui';
    $form['#attributes']['class'][] = 'mel-refund-decision-form';
    $req = $this->getRefundRequest();
    if (!$req || $req['status'] !== RefundRequestStorage::STATUS_REQUESTED) {
      $form['error'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Refund request not found or no longer waiting for approval.') . '</p>',
      ];
      return $form;
    }

    $event = $this->getEventFromRequest($req);
    $order = $this->loadOrder((int) $req['order_id']);
    // Route-event / refund-request / order binding failures: hard deny.
    if (!$event instanceof NodeInterface || !$order instanceof OrderInterface) {
      throw new AccessDeniedHttpException();
    }

    if (!$this->accessResolver->vendorCanManageEvent($event, $this->currentUser())) {
      throw new AccessDeniedHttpException();
    }

    $form_state->set('refund_request', $req);
    $form['#refund_request'] = $req;
    $form['#event'] = $event;
    $form['#order'] = $order;

    $requestedAmount = $this->formatMoney((int) ($req['amount_cents'] ?? 0), (string) ($req['currency'] ?? 'aud'));
    $attendeeOptions = $this->buildAttendeeOptions($req);
    $selectedAttendeeIds = $this->extractSelectedAttendeeIds($form_state);

    $form['summary'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Refund decision'),
    ];
    $form['summary']['help'] = [
      '#weight' => -20,
      '#markup' => '<p class="mel-refund-form-help">' . $this->t('Check the request against the order, then select only the tickets that should be refunded. Approval is the step that can return money through the original payment method.') . '</p>',
    ];
    $form['summary']['request_amount'] = [
      '#type' => 'markup',
      '#markup' => '<p><strong>' . $this->t('Buyer requested:') . '</strong> ' . $requestedAmount . '</p>',
    ];
    $form['summary']['decision_copy'] = [
      '#markup' => '<p>' . $this->t('Choose which tickets to refund. MyEventLane will calculate the exact amount before processing.') . '</p><p><strong>' . $this->t(DirectChargeCopy::REFUND) . '</strong></p>',
    ];
    $form['summary']['ticket_state'] = [
      '#markup' => '<p><strong>' . count($attendeeOptions) . ' tickets available for refund</strong></p>',
    ];

    if (empty($attendeeOptions)) {
      $form['no_attendees'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('No refundable ticket attendees are currently available for this refund request.') . '</p>',
      ];
    }
    else {
      $form['attendee_selection'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Select tickets to refund'),
        '#options' => $attendeeOptions,
        '#required' => FALSE,
      ];
    }

    $form['refund_preview'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'mel-refund-preview-wrapper',
      ],
    ];
    $form['refund_preview']['content'] = $this->buildRefundPreview($req, $selectedAttendeeIds);

    $form['actions'] = [
      '#type' => 'actions',
    ];

    if (!empty($attendeeOptions)) {
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Approve refund'),
        '#button_type' => 'primary',
      ];
    }

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $this->getCancelUrl(),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $selected = array_filter((array) $form_state->getValue('attendee_selection'));

    if (empty($selected)) {
      $form_state->setErrorByName('attendee_selection', $this->t('Select at least one ticket to refund.'));
      return;
    }

    $selectedIds = array_values(array_map('intval', $selected));

    $form_state->set('selected_attendee_ids', $selectedIds);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $req = $form_state->get('refund_request') ?? $form['#refund_request'] ?? NULL;
    if (!is_array($req)) {
      $this->getLogger('myeventlane_refunds')->error('Vendor refund approve submit: missing refund request context.');
      $this->messenger()->addError($this->t('Refund could not be started. Please refresh and try again.'));
      return;
    }

    $order = $form['#order'] ?? $this->loadOrder((int) ($req['order_id'] ?? 0));
    $event = $form['#event'] ?? $this->getEventFromRequest($req);
    if (!$order instanceof OrderInterface || !$event instanceof NodeInterface) {
      $this->getLogger('myeventlane_refunds')->error('Vendor refund approve submit: missing order or event for refund_request_id=@rid.', [
        '@rid' => (int) ($req['id'] ?? 0),
      ]);
      $this->messenger()->addError($this->t('Refund could not be started. Please refresh and try again.'));
      return;
    }

    $selectedAttendeeIds = (array) $form_state->get('selected_attendee_ids');
    if (empty($selectedAttendeeIds)) {
      $this->messenger()->addError($this->t('No tickets selected.'));
      return;
    }

    try {
      $amountCents = $this->orderInspector->calculateSelectedAttendeeRefundCents(
        $order,
        (int) $event->id(),
        $selectedAttendeeIds
      );

      $logId = $this->refundProcessor->requestRefund(
        $order,
        $event,
        $this->currentUser(),
        [
          'amount_cents' => $amountCents,
          'currency' => strtoupper((string) ($req['currency'] ?? 'aud')),
          'refund_type' => 'partial',
          'refund_scope' => 'tickets_only',
          'attendee_ids' => $selectedAttendeeIds,
          'reason' => 'Buyer self-service refund (vendor approved by attendee selection)',
          'refund_request_id' => (int) $req['id'],
        ]
      );

      $requestStatus = (string) (($this->refundRequestStorage->load((int) $req['id']))['status'] ?? '');
      if ($requestStatus === RefundRequestStorage::STATUS_COMPLETED) {
        $this->refundRequestStorage->update((int) $req['id'], [
          'refund_log_id' => $logId,
          'amount_cents' => $amountCents,
          'attendee_ids_json' => json_encode($selectedAttendeeIds, JSON_UNESCAPED_SLASHES),
        ]);
      }
      else {
        $this->refundRequestStorage->update((int) $req['id'], [
          'status' => RefundRequestStorage::STATUS_APPROVED,
          'refund_log_id' => $logId,
          'amount_cents' => $amountCents,
          'attendee_ids_json' => json_encode($selectedAttendeeIds, JSON_UNESCAPED_SLASHES),
        ]);
      }

      $log = $this->refundProcessor->loadRefundLog($logId);
      $this->addRefundStatusMessage((string) ($log['status'] ?? RefundProcessor::STATUS_PROCESSING));
    }
    catch (\Throwable $e) {
      $this->getLogger('myeventlane_refunds')->error('Vendor refund approve failed for request @rid: @message', [
        '@rid' => (int) ($req['id'] ?? 0),
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Refund failed — no money has been returned. You can retry safely.'));
      return;
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * Ajax callback to refresh the refund preview.
   */
  public function refreshRefundPreview(array $form, FormStateInterface $form_state): array {
    return $form['refund_preview'];
  }

  /**
   * Builds attendee checkbox labels for the request.
   */
  private function buildAttendeeOptions(array $req): array {
    $orderId = (int) ($req['order_id'] ?? 0);
    $eventId = (int) ($req['event_id'] ?? 0);

    $order = $this->loadOrder($orderId);
    if (!$order instanceof OrderInterface) {
      return [];
    }

    $attendees = $this->orderInspector
      ->loadRefundableTicketAttendeesForOrderEvent($order, $eventId);

    $options = [];

    foreach ($attendees as $attendee) {
      if (!$attendee instanceof \Drupal\myeventlane_event_attendees\Entity\EventAttendee) {
        continue;
      }

      $amountCents = (int) $this->orderInspector
        ->calculateSelectedAttendeeRefundCents($order, $eventId, [$attendee->id()]);

      $label = trim((string) $attendee->label());
      if ($label === '') {
        $label = 'Attendee';
      }

      $options[(int) $attendee->id()] = sprintf(
        "%s (%s)\n$%s • %s",
        $label,
        $attendee->getEmail(),
        number_format($amountCents / 100, 2),
        $attendee->getTicketCode() ?: 'Ticket'
      );
    }

    return $options;
  }

  /**
   * Builds the live refund preview block.
   */
  private function buildRefundPreview(array $req, array $selectedAttendeeIds): array {
    $order = $this->loadOrder((int) $req['order_id']);
    $eventId = (int) $req['event_id'];

    if (!$order || empty($selectedAttendeeIds)) {
      return [
        '#markup' => '<div class="mel-refund-preview">Select tickets to see refund amount</div>',
      ];
    }

    $amountCents = $this->orderInspector
      ->calculateSelectedAttendeeRefundCents($order, $eventId, $selectedAttendeeIds);

    $count = count($selectedAttendeeIds);

    return [
      '#markup' => '
      <div class="mel-refund-preview">
        <strong>$' . number_format($amountCents / 100, 2) . '</strong>
        <div>' . $count . ' ticket' . ($count > 1 ? 's' : '') . ' selected</div>
      </div>
    ',
    ];
  }

  /**
   * Extracts selected attendee IDs from form values.
   *
   * @return int[]
   *   Selected attendee IDs.
   */
  private function extractSelectedAttendeeIds(FormStateInterface $form_state): array {
    $selectedValues = (array) $form_state->getValue('attendee_selection', []);

    return array_values(array_filter(
      array_map('intval', $selectedValues),
      fn($v) => $v > 0
    ));
  }

  /**
   * Adds the MEL refund state message that matches the log status.
   */
  private function addRefundStatusMessage(string $status): void {
    switch ($status) {
      case RefundProcessor::STATUS_COMPLETED:
        $this->messenger()->addStatus($this->t('Refund complete — the money is on its way back now.'));
        return;

      case RefundProcessor::STATUS_PARTIAL:
        $this->messenger()->addWarning($this->t('Refund in progress — this usually takes a few minutes.'));
        return;

      case RefundProcessor::STATUS_PENDING_CONFIRMATION:
        $this->messenger()->addStatus($this->t('Refund in progress — this usually takes a few minutes.'));
        return;

      case RefundProcessor::STATUS_PROCESSING:
      default:
        $this->messenger()->addStatus($this->t('Refund in progress — this usually takes a few minutes.'));
    }
  }

  /**
   * Gets the refund request from route.
   */
  private function getRefundRequest(): ?array {
    $reqId = (int) $this->getRouteMatch()->getParameter('refund_request');
    return $this->refundRequestStorage->load($reqId);
  }

  /**
   * Gets the verified event from the refund request and route.
   */
  private function getEventFromRequest(array $req): ?NodeInterface {
    $event = $this->entityTypeManager->getStorage('node')->load((int) ($req['event_id'] ?? 0));
    $routeNode = $this->getRouteMatch()->getParameter('node');
    $routeNodeId = $routeNode instanceof NodeInterface ? $routeNode->id() : (is_numeric($routeNode) ? (int) $routeNode : NULL);

    if (!$event instanceof NodeInterface || $routeNodeId === NULL || (int) $event->id() !== (int) $routeNodeId) {
      return NULL;
    }

    return $event;
  }

  /**
   * Loads the order for the refund request.
   */
  private function loadOrder(int $orderId): ?OrderInterface {
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($orderId);
    return $order instanceof OrderInterface ? $order : NULL;
  }

  /**
   * Gets the event list redirect URL.
   */
  private function getCancelUrl(): Url {
    $event = $this->getEventFromRequest($this->getRefundRequest() ?? []);
    return $event
      ? Url::fromRoute('myeventlane_refunds.vendor_refund_requests', ['node' => $event->id()])
      : Url::fromRoute('<front>');
  }

  /**
   * Formats cents as a money string.
   */
  private function formatMoney(int $amountCents, string $currency): string {
    return strtoupper($currency) . ' ' . number_format($amountCents / 100, 2);
  }

}
