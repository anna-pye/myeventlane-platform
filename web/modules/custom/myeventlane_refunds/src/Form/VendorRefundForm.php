<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Form;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_refunds\Service\RefundAccessResolver;
use Drupal\myeventlane_refunds\Service\RefundOrderInspector;
use Drupal\myeventlane_refunds\Service\RefundProcessor;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for vendors to refund orders.
 */
final class VendorRefundForm extends FormBase {

  /**
   * Constructs VendorRefundForm.
   *
   * @param \Drupal\myeventlane_refunds\Service\RefundAccessResolver $accessResolver
   *   The access resolver.
   * @param \Drupal\myeventlane_refunds\Service\RefundOrderInspector $orderInspector
   *   The order inspector.
   * @param \Drupal\myeventlane_refunds\Service\RefundProcessor $refundProcessor
   *   The refund processor.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly RefundAccessResolver $accessResolver,
    private readonly RefundOrderInspector $orderInspector,
    private readonly RefundProcessor $refundProcessor,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_refunds.access_resolver'),
      $container->get('myeventlane_refunds.order_inspector'),
      $container->get('myeventlane_refunds.processor'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_refunds_vendor_refund_form';
  }

  /**
   * Access callback.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $commerce_order
   *   The order.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  public function access(OrderInterface $commerce_order): AccessResult {
    $eventId = (int) $this->getRequest()->query->get('event', 0);
    if (!$eventId) {
      return AccessResult::forbidden('Event parameter required.');
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $event = $nodeStorage->load($eventId);
    if (!$event instanceof NodeInterface) {
      return AccessResult::forbidden('Event not found.');
    }

    return $this->accessResolver->accessRefundOrder($commerce_order, $event, $this->currentUser());
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?OrderInterface $commerce_order = NULL, ?NodeInterface $node = NULL): array {
    $form['#attached']['library'][] = 'myeventlane_refunds/mel_refund_ui';
    $form['#attributes']['class'][] = 'mel-refund-decision-form';
    $form_state->set('event_id', (int) $this->getRequest()->query->get('event', 0));
    $form_state->set('order_id', (int) $this->getRequest()->query->get('order', 0));
    $order = $commerce_order;
    $event = $node instanceof NodeInterface ? $node : NULL;

    if (!$order) {
      $orderId = (int) $this->getRequest()->query->get('order', 0);
      if ($orderId > 0) {
        $loadedOrder = $this->entityTypeManager->getStorage('commerce_order')->load($orderId);
        if ($loadedOrder instanceof OrderInterface) {
          $order = $loadedOrder;
        }
      }
    }

    $eventId = (int) $this->getRequest()->query->get('event', $event?->id() ?? 0);
    if ($eventId > 0 && !$event) {
      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $loadedEvent = $nodeStorage->load($eventId);
      if ($loadedEvent instanceof NodeInterface) {
        $event = $loadedEvent;
      }
    }

    if (!$order) {
      $form['error'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Order parameter required.') . '</p>',
      ];
      return $form;
    }

    if (!$eventId) {
      $form['error'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Event parameter required.') . '</p>',
      ];
      return $form;
    }

    if (!$event instanceof NodeInterface) {
      $form['error'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Event not found.') . '</p>',
      ];
      return $form;
    }

    $form['#order'] = $order;
    $form['#event'] = $event;

    $form['guidance'] = [
      '#type' => 'container',
      '#weight' => -30,
      '#attributes' => ['class' => ['mel-refund-form-help']],
      'title' => ['#markup' => '<h2>' . $this->t('Refund this order') . '</h2>'],
      'copy' => ['#markup' => '<p>' . $this->t('Choose the exact scope and check the refundable amount before continuing. Processing a confirmed refund can return money through the original payment method and cancel the selected tickets.') . '</p>'],
    ];

    $timelineRows = $this->refundProcessor->getRefundTimelineForOrder((int) $order->id());
    $forEvent = array_values(array_filter(
      array_values($timelineRows),
      static function ($row) use ($eventId): bool {
        return is_object($row) && isset($row->event_id) && (int) $row->event_id === $eventId;
      }
    ));
    if ($forEvent !== []) {
      $recent = array_slice($forEvent, -2);
      $form['refund_history'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-refund-form-history']],
        '#weight' => -20,
        'intro' => [
          '#type' => 'markup',
          '#markup' => '<p class="mel-text--muted">' . $this->t('Recent refund activity for this order.') . '</p>',
        ],
        'timeline' => [
          '#theme' => 'mel_refund_timeline',
          '#items' => $recent,
          '#variant' => 'compact',
        ],
      ];
      $form['#attached']['library'] = array_merge($form['#attached']['library'] ?? [], ['myeventlane_refunds/mel_refund_ui']);
    }

    // Calculate amounts.
    $ticketSubtotalCents = $this->orderInspector->calculateTicketSubtotalCents($order, $eventId);
    $donationTotalCents = $this->orderInspector->calculateDonationTotalCents($order);
    $refundableCents = $this->orderInspector->calculateRefundableAmountCents($order);

    $ticketSubtotal = $ticketSubtotalCents / 100;
    $donationTotal = $donationTotalCents / 100;
    $refundable = $refundableCents / 100;

    $form['summary'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Order Summary'),
    ];

    $form['summary']['ticket_subtotal'] = [
      '#type' => 'markup',
      '#markup' => '<p><strong>' . $this->t('Ticket subtotal (this event):') . '</strong> $' . number_format($ticketSubtotal, 2) . '</p>',
    ];

    if ($donationTotal > 0) {
      $form['summary']['donation_total'] = [
        '#type' => 'markup',
        '#markup' => '<p><strong>' . $this->t('Donation total:') . '</strong> $' . number_format($donationTotal, 2) . '</p>',
      ];
    }

    $form['summary']['refundable'] = [
      '#type' => 'markup',
      '#markup' => '<p><strong>' . $this->t('Refundable amount:') . '</strong> $' . number_format($refundable, 2) . '</p>',
    ];
    $form['summary']['refund_policy'] = [
      '#type' => 'markup',
      '#markup' => '<p><strong>' . $this->t('Refund policy:') . '</strong> ' . $this->orderInspector->getEventRefundPolicyMessage($event) . '</p>',
    ];

    $form['refund_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Refund Type'),
      '#options' => [
        'full' => $this->t('Full ticket refund for this event'),
        'partial' => $this->t('Partial refund'),
      ],
      '#default_value' => 'full',
      '#required' => TRUE,
    ];

    $form['partial_refund'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Partial Refund Details'),
      '#tree' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="refund_type"]' => ['value' => 'partial'],
        ],
      ],
    ];

    $partialAttendees = $this->orderInspector->getRefundableTicketAttendeeBreakdown($order, $eventId);
    $attendeeOptions = [];
    foreach ($partialAttendees as $attendeeId => $entry) {
      $attendee = $entry['attendee'];
      $amountCents = (int) ($entry['amount_cents'] ?? 0);
      $ticketCode = (string) ($attendee->getTicketCode() ?? '');
      $attendeeName = trim((string) ($entry['display_name'] ?? ''));
      if ($attendeeName === '') {
        $attendeeName = (string) $this->t('Unnamed attendee');
      }
      $attendeeOptions[(int) $attendeeId] = trim(sprintf(
        '%s (%s) - %s%s',
        $attendeeName,
        $attendee->getEmail(),
        '$' . number_format($amountCents / 100, 2),
        $ticketCode !== '' ? ' - ' . $ticketCode : ''
      ));
    }

    if (empty($attendeeOptions)) {
      $form['partial_refund']['none_available'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('No active ticket attendees are available for partial refund on this order.') . '</p>',
      ];
    }
    else {
      $form['partial_refund']['attendee_ids'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Select attendee(s) to refund'),
        '#options' => $attendeeOptions,
        '#description' => $this->t('Refund amount is automatically calculated from selected ticket attendee(s).'),
      ];

      $form['partial_refund']['amount_preview'] = [
        '#type' => 'markup',
        '#markup' => '<p><strong>' . $this->t('Maximum partial refund from selection:') . '</strong> $' . number_format($ticketSubtotal, 2) . '</p>',
      ];
    }

    $form['partial_refund']['partial_policy'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('On success, only selected attendee ticket(s) are cancelled.') . '</p>',
    ];

    $form['include_donation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include donation in refund'),
      '#description' => $this->t('By default, only tickets are refunded. Check this to also refund the donation amount (full refunds only).'),
      '#default_value' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="refund_type"]' => ['value' => 'full'],
        ],
      ],
    ];

    $operationalItems = $this->orderInspector->getRefundableOperationalItemBreakdown($order, $eventId);
    if ($operationalItems !== []) {
      $options = [];
      foreach ($operationalItems as $itemId => $entry) {
        $options[$itemId] = $this->t('@label — @quantity × units — $@amount', [
          '@label' => $entry['label'],
          '@quantity' => $entry['quantity'],
          '@amount' => number_format($entry['amount_cents'] / 100, 2),
        ]);
      }
      $form['operational_item_ids'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Also refund uncollected event extras'),
        '#options' => $options,
        '#description' => $this->t('Only whole, uncollected add-on lines can be refunded here. Refunded units return to available stock after Stripe confirms the refund.'),
        '#states' => [
          'visible' => [
            ':input[name="refund_type"]' => ['value' => 'full'],
          ],
        ],
      ];
    }

    $form['reason'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reason for Refund'),
      '#description' => $this->t('Optional: Provide a reason for this refund.'),
      '#rows' => 3,
    ];

    $form['confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I confirm that I want to process this refund.'),
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Process refund'),
      '#button_type' => 'primary',
      '#submit' => ['::submitForm'],
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('myeventlane_event_studio.workspace_orders', ['node' => $eventId]),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $order = $form['#order'];
    $refundType = $form_state->getValue('refund_type');

    if ($refundType === 'partial') {
      if ((bool) $form_state->getValue('include_donation')) {
        $form_state->setErrorByName('include_donation', $this->t('Donation inclusion is only supported for full refunds.'));
        $this->messenger()->addError($this->t('Donation inclusion is only supported for full refunds.'));
      }

      $selectedValues = (array) $form_state->getValue(['partial_refund', 'attendee_ids'], []);
      if (empty($selectedValues)) {
        $selectedValues = (array) $form_state->getValue('attendee_ids', []);
      }
      $selectedAttendeeIds = array_values(array_filter(array_map('intval', $selectedValues)));
      if (empty($selectedAttendeeIds)) {
        $form_state->setErrorByName('partial_refund][attendee_ids', $this->t('Select at least one attendee for partial refund.'));
        $this->messenger()->addError($this->t('Select at least one attendee for partial refund.'));
        return;
      }

      try {
        $amountCents = $this->orderInspector->calculateSelectedAttendeeRefundCents(
          $order,
          (int) $form_state->get('event_id'),
          $selectedAttendeeIds
        );
      }
      catch (\InvalidArgumentException $e) {
        $form_state->setErrorByName('partial_refund][attendee_ids', $this->t($e->getMessage()));
        $this->messenger()->addError($this->t($e->getMessage()));
        return;
      }

      if ($amountCents <= 0) {
        $form_state->setErrorByName('partial_refund][attendee_ids', $this->t('Selected attendees resolve to zero refund amount.'));
        $this->messenger()->addError($this->t('Selected attendees resolve to zero refund amount.'));
      }

      $refundableCents = $this->orderInspector->calculateRefundableAmountCents($order);
      if ($amountCents > $refundableCents) {
        $refundable = $refundableCents / 100;
        $form_state->setErrorByName('partial_refund][attendee_ids', $this->t('Selected attendees exceed refundable amount ($@max).', ['@max' => number_format($refundable, 2)]));
        $this->messenger()->addError($this->t('Selected attendees exceed refundable amount ($@max).', ['@max' => number_format($refundable, 2)]));
      }

      $form_state->set('partial_refund_amount_cents', $amountCents);
      $form_state->set('partial_refund_attendee_ids', $selectedAttendeeIds);
    }

    $selectedOperationalIds = array_values(array_filter(array_map(
      'intval',
      (array) $form_state->getValue('operational_item_ids', []),
    )));
    if ($refundType !== 'full' && $selectedOperationalIds !== []) {
      $form_state->setErrorByName('operational_item_ids', $this->t('Event extras can only be included with a full ticket refund.'));
      return;
    }
    if ($selectedOperationalIds !== []) {
      $breakdown = $this->orderInspector->getRefundableOperationalItemBreakdown(
        $order,
        (int) $form_state->get('event_id'),
      );
      $quantities = [];
      foreach ($selectedOperationalIds as $itemId) {
        if (!isset($breakdown[$itemId])) {
          $form_state->setErrorByName('operational_item_ids', $this->t('One or more event extras are no longer refundable.'));
          return;
        }
        $quantities[$itemId] = (int) $breakdown[$itemId]['quantity'];
      }
      $form_state->set('operational_refund_quantities', $quantities);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $order = $form['#order'];
    $event = $form['#event'];
    $refundType = $form_state->getValue('refund_type');
    $includeDonation = (bool) $form_state->getValue('include_donation');
    $reason = trim((string) ($form_state->getValue('reason') ?? ''));
    $eventId = (int) $event->id();
    $amountCents = 0;

    if ($refundType === 'partial') {
      $amountCents = (int) $form_state->get('partial_refund_amount_cents');
    }
    else {
      $amountCents = $this->orderInspector->calculateTicketSubtotalCents($order, $eventId);
      if ($includeDonation) {
        $amountCents += $this->orderInspector->calculateDonationTotalCents($order);
      }
    }

    $operationalQuantities = (array) ($form_state->get('operational_refund_quantities') ?? []);
    if ($operationalQuantities !== []) {
      $amountCents += $this->orderInspector->calculateSelectedOperationalRefundCents(
        $order,
        $eventId,
        $operationalQuantities,
      );
    }

    $totalPrice = $order->getTotalPrice();
    $currency = $totalPrice ? strtoupper($totalPrice->getCurrencyCode()) : 'AUD';
    $payload = [
      'amount_cents' => $amountCents,
      'currency' => $currency,
      'refund_type' => $refundType,
      'refund_scope' => $includeDonation ? 'tickets_and_donation' : 'tickets_only',
      'include_donation' => $includeDonation,
      'attendee_ids' => (array) ($form_state->get('partial_refund_attendee_ids') ?? []),
      'operational_item_quantities' => $operationalQuantities,
      'reason' => $reason,
    ];

    try {
      $logId = $this->refundProcessor->requestRefund(
        $order,
        $event,
        $this->currentUser(),
        $payload
      );
      $log = $this->refundProcessor->loadRefundLog($logId);
      $status = (string) ($log['status'] ?? RefundProcessor::STATUS_PROCESSING);
      switch ($status) {
        case RefundProcessor::STATUS_COMPLETED:
          $this->messenger()->addStatus($this->t('Refund complete — the money is on its way back now.'));
          break;

        case RefundProcessor::STATUS_PARTIAL:
          $this->messenger()->addWarning($this->t('Refund in progress — this usually takes a few minutes.'));
          break;

        case RefundProcessor::STATUS_PENDING_CONFIRMATION:
          $this->messenger()->addStatus($this->t('Refund in progress — this usually takes a few minutes.'));
          break;

        case RefundProcessor::STATUS_PROCESSING:
        default:
          $this->messenger()->addStatus($this->t('Refund in progress — this usually takes a few minutes.'));
          break;
      }
      $form_state->setRedirect('myeventlane_event_studio.workspace_orders', ['node' => $event->id()]);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Refund failed — no money has been returned. You can retry safely.'));
      $this->getLogger('myeventlane_refunds')->error('Refund request failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
