<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_state\Form;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_refunds\Service\RefundProcessor;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to request refunds for one or all orders in an event.
 */
final class EventRefundRequestForm extends FormBase {

  /**
   * Constructs the form.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RefundProcessor $refundProcessor,
    private readonly LoggerChannelFactoryInterface $eventStateLoggerFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('myeventlane_refunds.processor'),
      $container->get('logger.factory'),
    );
  }

  /**
   * Gets module logger.
   */
  private function eventLogger(): LoggerInterface {
    return $this->eventStateLoggerFactory->get('myeventlane_event_state');
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_event_state_refund_request_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL, ?int $order = NULL): array {
    if (!$node) {
      $form['error'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Event not found.') . '</p>',
      ];
      return $form;
    }

    if ((int) $node->getOwnerId() !== (int) $this->currentUser()->id()) {
      $form['error'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Access denied.') . '</p>',
      ];
      return $form;
    }

    $selectedOrderId = $order ? (int) $order : (int) $this->getRequest()->query->get('order', 0);

    $form['#node'] = $node;
    $form['#order'] = $selectedOrderId > 0 ? $selectedOrderId : NULL;

    $form['warning'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--warning">' . $this->t('Submitting this form creates real refund jobs and will process refunds for eligible paid orders.') . '</div>',
    ];

    $scopeText = $selectedOrderId > 0
      ? $this->t('Target scope: Order #@order for this event only.', ['@order' => $selectedOrderId])
      : $this->t('Target scope: All eligible paid orders for this event.');
    $form['scope'] = [
      '#type' => 'markup',
      '#markup' => '<p><strong>' . $scopeText . '</strong></p>',
    ];

    $form['refund_reason'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Refund Reason'),
      '#description' => $this->t('Reason stored on each refund log entry.'),
      '#required' => TRUE,
      '#rows' => 5,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Queue Refunds'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('myeventlane_event_state.refunds', ['node' => $node->id()]),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $node = $form['#node'] ?? NULL;
    $orderId = isset($form['#order']) ? (int) $form['#order'] : 0;
    $reason = (string) $form_state->getValue('refund_reason');

    if (!$node instanceof NodeInterface) {
      $this->messenger()->addError($this->t('Refund queueing failed: event not found.'));
      $this->eventLogger()->error('Refund queueing failed: missing event entity in form submit.');
      return;
    }

    if ((int) $node->getOwnerId() !== (int) $this->currentUser()->id()) {
      $this->messenger()->addError($this->t('Refund queueing failed: access denied.'));
      $this->eventLogger()->error('Refund queueing denied for event @event_id by user @uid.', [
        '@event_id' => (int) $node->id(),
        '@uid' => (int) $this->currentUser()->id(),
      ]);
      return;
    }

    $orders = $this->loadRefundableOrdersForEvent($node, $orderId > 0 ? $orderId : NULL);
    if (empty($orders)) {
      $this->messenger()->addWarning($this->t('No eligible paid orders found to refund for this event.'));
      $this->eventLogger()->warning('No eligible paid orders found for refund queueing. event=@event_id order_scope=@order_scope', [
        '@event_id' => (int) $node->id(),
        '@order_scope' => $orderId > 0 ? $orderId : 'all',
      ]);
      $form_state->setRedirect('myeventlane_event_state.refunds', ['node' => $node->id()]);
      return;
    }

    $success = 0;
    $failed = 0;

    foreach ($orders as $order) {
      try {
        $this->refundProcessor->requestRefund($order, $node, $this->currentUser(), [
          'refund_type' => 'full',
          'refund_scope' => 'tickets_only',
          'include_donation' => FALSE,
          'reason' => $reason,
        ]);
        $success++;
      }
      catch (\Exception $e) {
        $failed++;
        $this->eventLogger()->error(
          'Failed to queue refund for event @event_id order @order_id: @message',
          [
            '@event_id' => (int) $node->id(),
            '@order_id' => (int) $order->id(),
            '@message' => $e->getMessage(),
          ]
        );
      }
    }

    if ($success > 0) {
      $this->messenger()->addStatus($this->t('Queued @count refund(s) for processing.', ['@count' => $success]));
    }
    if ($failed > 0) {
      $this->messenger()->addError($this->t('Failed to queue @count refund(s). Check logs for details.', ['@count' => $failed]));
    }

    $this->eventLogger()->notice(
      'Refund queueing complete for event @event_id. success=@success failed=@failed order_scope=@order_scope.',
      [
        '@event_id' => (int) $node->id(),
        '@success' => $success,
        '@failed' => $failed,
        '@order_scope' => $orderId > 0 ? $orderId : 'all',
      ]
    );

    $form_state->setRedirect('myeventlane_event_state.refunds', ['node' => $node->id()]);
  }

  /**
   * Loads refundable orders for an event, optionally scoped to one order.
   *
   * @param \Drupal\node\NodeInterface $event
   *   Event node.
   * @param int|null $targetOrderId
   *   Optional target order ID.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface[]
   *   Refundable orders keyed by order ID.
   */
  private function loadRefundableOrdersForEvent(NodeInterface $event, ?int $targetOrderId = NULL): array {
    $orderItemStorage = $this->entityTypeManager->getStorage('commerce_order_item');
    $query = $orderItemStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_target_event', (int) $event->id());

    if ($targetOrderId !== NULL && $targetOrderId > 0) {
      $query->condition('order_id', $targetOrderId);
    }

    $orderItemIds = $query->execute();
    if (empty($orderItemIds)) {
      return [];
    }

    $orderItems = $orderItemStorage->loadMultiple($orderItemIds);
    $orders = [];
    $orderStorage = $this->entityTypeManager->getStorage('commerce_order');

    foreach ($orderItems as $item) {
      if (!$item->hasField('order_id') || $item->get('order_id')->isEmpty()) {
        continue;
      }
      $orderId = (int) $item->get('order_id')->target_id;
      if ($orderId <= 0 || isset($orders[$orderId])) {
        continue;
      }

      $order = $orderStorage->load($orderId);
      if (!$order instanceof OrderInterface) {
        continue;
      }

      $state = $order->getState()->getId();
      if (!in_array($state, ['completed', 'fulfilled', 'placed'], TRUE)) {
        continue;
      }

      $orders[$orderId] = $order;
    }

    return $orders;
  }

}
