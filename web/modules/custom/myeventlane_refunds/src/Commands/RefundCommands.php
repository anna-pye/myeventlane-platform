<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Commands;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for refund status and Stripe verification.
 */
final class RefundCommands extends DrushCommands {

  /**
   * Constructs RefundCommands.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Show refund log status and payment IDs for Stripe verification.
   *
   * Use --order=ORDER_ID to show refunds for an order, or --log=LOG_ID for a
   * single refund log. Shows payment remote_id (Stripe Charge/PaymentIntent ID)
   * so you can verify in Stripe Dashboard → Payments.
   *
   * @param array $options
   *   Command options.
   *
   * @command mel:refund-status
   * @aliases mel-refund-status
   * @option order Order ID (commerce_order id)
   * @option log Refund log ID (myeventlane_refund_log id)
   * @usage drush mel:refund-status --order=236
   *   Show refunds and payment IDs for order 236.
   * @usage drush mel:refund-status --log=5
   *   Show refund log 5 and its order's payment IDs.
   */
  public function status(array $options = [
    'order' => NULL,
    'log' => NULL,
  ]): void {
    $orderId = $options['order'] ?? NULL;
    $logId = $options['log'] ?? NULL;

    if (!$orderId && !$logId) {
      $this->logger()->warning('Provide --order=ORDER_ID or --log=LOG_ID.');
      return;
    }

    $query = $this->database->select('myeventlane_refund_log', 'r')
      ->fields('r', [
        'id',
        'order_id',
        'event_id',
        'vendor_uid',
        'refund_type',
        'refund_scope',
        'amount_cents',
        'currency',
        'donation_refunded',
        'stripe_refund_id',
        'status',
        'reason',
        'error_message',
        'created',
        'completed',
      ]);

    if ($logId) {
      $query->condition('r.id', (int) $logId);
    }
    if ($orderId) {
      $query->condition('r.order_id', (int) $orderId);
    }

    $query->orderBy('r.id', 'DESC');
    $rows = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    if (empty($rows)) {
      $this->logger()->notice(
        $logId
          ? 'No refund log found with id %s.'
          : 'No refund logs found for order %s.',
        [$logId ?: $orderId]
      );
      return;
    }

    $this->output()->writeln('');
    $this->output()->writeln('<info>Refund log(s)</info>');
    $this->io()->table(
      ['id', 'order_id', 'status', 'amount', 'stripe_refund_id', 'created', 'completed'],
      array_map(static function (array $r): array {
        $created = $r['created'] ? date('Y-m-d H:i', (int) $r['created']) : '-';
        $completed = !empty($r['completed']) ? date('Y-m-d H:i', (int) $r['completed']) : '-';
        return [
          $r['id'],
          $r['order_id'],
          $r['status'],
          ($r['amount_cents'] / 100) . ' ' . strtoupper($r['currency']),
          $r['stripe_refund_id'] ?: '-',
          $created,
          $completed,
        ];
      }, $rows)
    );

    foreach ($rows as $r) {
      if (!empty($r['error_message'])) {
        $this->logger()->warning('Log id %s error: %s', [$r['id'], $r['error_message']]);
      }
    }

    $orderIds = array_unique(array_column($rows, 'order_id'));
    foreach ($orderIds as $oid) {
      $order = $this->entityTypeManager->getStorage('commerce_order')->load($oid);
      if (!$order instanceof OrderInterface) {
        continue;
      }

      $paymentStorage = $this->entityTypeManager->getStorage('commerce_payment');
      $paymentIds = $paymentStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('order_id', $oid)
        ->execute();

      if (empty($paymentIds)) {
        $this->output()->writeln(sprintf(
          '<comment>Order %s: no payments found.</comment>',
          $oid
        ));
        continue;
      }

      $payments = $paymentStorage->loadMultiple($paymentIds);
      $paymentRows = [];
      foreach ($payments as $p) {
        $paymentRows[] = [
          $p->id(),
          $p->getRemoteId() ?: '-',
          $p->getState()->getId(),
          $p->getAmount() ? $p->getAmount()->getNumber() . ' ' . $p->getAmount()->getCurrencyCode() : '-',
          $p->getRefundedAmount() && !$p->getRefundedAmount()->isZero()
            ? $p->getRefundedAmount()->getNumber() . ' ' . $p->getRefundedAmount()->getCurrencyCode()
            : '-',
        ];
      }

      $this->output()->writeln(sprintf('<info>Order %s – payments (use remote_id in Stripe Dashboard)</info>', $oid));
      $this->io()->table(
        ['payment_id', 'remote_id (Stripe)', 'state', 'amount', 'refunded'],
        $paymentRows
      );
    }

    $this->output()->writeln('');
    $this->output()->writeln('<comment>To verify in Stripe:</comment> Dashboard → Payments → search by Charge ID or PaymentIntent ID (remote_id above).');
    $this->output()->writeln('status=completed means our code called the payment gateway (Stripe) and it did not throw; the refund was submitted to Stripe.');
    $this->output()->writeln('');
  }

}
