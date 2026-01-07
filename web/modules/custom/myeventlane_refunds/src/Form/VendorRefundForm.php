<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Form;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Access\AccessResult;
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
   */
  public function __construct(
    private readonly RefundAccessResolver $accessResolver,
    private readonly RefundOrderInspector $orderInspector,
    private readonly RefundProcessor $refundProcessor,
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
    $eventId = (int) ($_GET['event'] ?? 0);
    if (!$eventId) {
      return AccessResult::forbidden('Event parameter required.');
    }

    $nodeStorage = \Drupal::entityTypeManager()->getStorage('node');
    $event = $nodeStorage->load($eventId);
    if (!$event instanceof NodeInterface) {
      return AccessResult::forbidden('Event not found.');
    }

    return $this->accessResolver->accessRefundOrder($commerce_order, $event, $this->currentUser());
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, OrderInterface $commerce_order = NULL, NodeInterface $node = NULL): array {
    $eventId = (int) ($_GET['event'] ?? ($node ? $node->id() : 0));
    if (!$eventId) {
      $form['error'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Event parameter required.') . '</p>',
      ];
      return $form;
    }

    $nodeStorage = \Drupal::entityTypeManager()->getStorage('node');
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
      '#states' => [
        'visible' => [
          ':input[name="refund_type"]' => ['value' => 'partial'],
        ],
      ],
    ];

    $form['partial_refund']['amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Refund Amount (AUD)'),
      '#step' => 0.01,
      '#min' => 0.01,
      '#max' => $refundable,
      '#default_value' => $ticketSubtotal,
      '#description' => $this->t('Maximum refundable: $@max', ['@max' => number_format($refundable, 2)]),
      '#states' => [
        'required' => [
          ':input[name="refund_type"]' => ['value' => 'partial'],
        ],
      ],
    ];

    $form['include_donation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include donation in refund'),
      '#description' => $this->t('By default, only tickets are refunded. Check this to also refund the donation amount.'),
      '#default_value' => FALSE,
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
      '#url' => Url::fromRoute('myeventlane_refunds.vendor_orders', ['node' => $eventId]),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $order = $form['#order'];
    $event = $form['#event'];
    $refundType = $form_state->getValue('refund_type');

    if ($refundType === 'partial') {
      $amount = (float) $form_state->getValue('amount');
      if ($amount <= 0) {
        $form_state->setError($form['partial_refund']['amount'], $this->t('Refund amount must be greater than zero.'));
      }

      $refundableCents = $this->orderInspector->calculateRefundableAmountCents($order);
      $refundable = $refundableCents / 100;
      if ($amount > $refundable) {
        $form_state->setError($form['partial_refund']['amount'], $this->t('Refund amount cannot exceed refundable amount ($@max).', ['@max' => number_format($refundable, 2)]));
      }
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
      $amount = (float) $form_state->getValue('amount');
      $refundPayload['amount_cents'] = (int) round($amount * 100);
    }

    try {
      $logId = $this->refundProcessor->requestRefund($order, $event, $account, $refundPayload);
      $this->messenger()->addStatus($this->t('Refund request submitted successfully. Refund ID: @log_id', ['@log_id' => $logId]));
      $form_state->setRedirect('myeventlane_refunds.vendor_orders', ['node' => $event->id()]);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to submit refund request: @message', ['@message' => $e->getMessage()]));
      $this->getLogger('myeventlane_refunds')->error('Refund request failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

}







