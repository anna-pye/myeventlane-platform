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
    $form_state->set('event_id', (int) $this->getRequest()->query->get('event', 0));
    $form_state->set('order_id', (int) $this->getRequest()->query->get('order', 0));

    if (!$commerce_order) {
      $orderId = (int) $this->getRequest()->query->get('order', 0);
      if ($orderId > 0) {
        $loadedOrder = $this->entityTypeManager->getStorage('commerce_order')->load($orderId);
        if ($loadedOrder instanceof OrderInterface) {
          $commerce_order = $loadedOrder;
        }
      }
    }

    if (!$commerce_order) {
      $form['error'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Order parameter required.') . '</p>',
      ];
      return $form;
    }

    $eventId = (int) $this->getRequest()->query->get('event', $node?->id() ?? 0);
    if (!$eventId) {
      $form['error'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Event parameter required.') . '</p>',
      ];
      return $form;
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $event = $nodeStorage->load($eventId);
    if (!$event instanceof NodeInterface) {
      $form['error'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Event not found.') . '</p>',
      ];
      return $form;
    }

    $form['#order'] = $commerce_order;
    $form['#event'] = $event;

    // Calculate amounts.
    $ticketSubtotalCents = $this->orderInspector->calculateTicketSubtotalCents($commerce_order, $eventId);
    $donationTotalCents = $this->orderInspector->calculateDonationTotalCents($commerce_order);
    $refundableCents = $this->orderInspector->calculateRefundableAmountCents($commerce_order);

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
        'full' => $this->t('Full refund (tickets for this event)'),
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

    $partialAttendees = $this->orderInspector->getRefundableTicketAttendeeBreakdown($commerce_order, $eventId);
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
        '#states' => [
          'required' => [
            ':input[name="refund_type"]' => ['value' => 'partial'],
          ],
        ],
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
      '#value' => $this->t('Refund Now'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('myeventlane_vendor.console.event_orders', ['event' => $eventId]),
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
      }

      $selectedValues = (array) $form_state->getValue(['partial_refund', 'attendee_ids'], []);
      if (empty($selectedValues)) {
        $selectedValues = (array) $form_state->getValue('attendee_ids', []);
      }
      $selectedAttendeeIds = array_values(array_filter(array_map('intval', $selectedValues)));
      if (empty($selectedAttendeeIds)) {
        $form_state->setErrorByName('partial_refund][attendee_ids', $this->t('Select at least one attendee for partial refund.'));
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
        return;
      }

      if ($amountCents <= 0) {
        $form_state->setErrorByName('partial_refund][attendee_ids', $this->t('Selected attendees resolve to zero refund amount.'));
      }

      $refundableCents = $this->orderInspector->calculateRefundableAmountCents($order);
      if ($amountCents > $refundableCents) {
        $refundable = $refundableCents / 100;
        $form_state->setErrorByName('partial_refund][attendee_ids', $this->t('Selected attendees exceed refundable amount ($@max).', ['@max' => number_format($refundable, 2)]));
      }

      $form_state->set('partial_refund_amount_cents', $amountCents);
      $form_state->set('partial_refund_attendee_ids', $selectedAttendeeIds);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $order = $form['#order'];
    $event = $form['#event'];
    $account = $this->currentUser();

    $refundType = $form_state->getValue('refund_type');
    $includeDonation = (bool) $form_state->getValue('include_donation');
    $reason = $form_state->getValue('reason') ?? '';

    $refundScope = 'tickets_only';
    if ($includeDonation) {
      $refundScope = 'tickets_and_donation';
    }

    $refundPayload = [
      'refund_type' => $refundType,
      'refund_scope' => $refundScope,
      'include_donation' => $includeDonation,
      'reason' => $reason,
    ];

    if ($refundType === 'partial') {
      $refundPayload['amount_cents'] = (int) $form_state->get('partial_refund_amount_cents');
      $refundPayload['attendee_ids'] = (array) $form_state->get('partial_refund_attendee_ids');
    }

    try {
      $logId = $this->refundProcessor->requestRefund($order, $event, $account, $refundPayload);
      $this->messenger()->addStatus($this->t('Refund request submitted successfully. Refund ID: @log_id', ['@log_id' => $logId]));
      $form_state->setRedirect('myeventlane_vendor.console.event_orders', ['event' => $event->id()]);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to submit refund request: @message', ['@message' => $e->getMessage()]));
      $this->getLogger('myeventlane_refunds')->error('Refund request failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
