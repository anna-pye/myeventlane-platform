<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Attribute\AdvancedQueueJobType;
use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Drupal\myeventlane_pro\Service\AbandonedCartTerminalStateResolver;
use Drupal\myeventlane_pro\Service\ProActiveResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends Pro abandoned-cart reminders from tracking rows.
 */
#[AdvancedQueueJobType(
  id: 'pro_abandoned_cart_job',
  label: new TranslatableMarkup('Pro abandoned cart reminder'),
  max_retries: 2,
  retry_delay: 300,
)]
final class ProAbandonedCartJob extends JobTypeBase implements ContainerFactoryPluginInterface {

  private const STATUS_SCHEDULED = 'scheduled';
  private const STATUS_QUEUED = 'queued';
  private const STATUS_SENT = 'sent';
  private const STATUS_SKIPPED = 'skipped';
  private const STATUS_FAILED = 'failed';

  /**
   * Constructs the job type.
   */
  public function __construct(
    array $configuration,
    mixed $plugin_id,
    mixed $plugin_definition,
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly ProActiveResolver $proActiveResolver,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly MailManagerInterface $mailManager,
    private readonly AbandonedCartTerminalStateResolver $terminalStateResolver,
    private readonly ?MessagingManager $messagingManager = NULL,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('datetime.time'),
      $container->get('logger.channel.myeventlane_pro'),
      $container->get('myeventlane_pro.active_resolver'),
      $container->get('config.factory'),
      $container->get('plugin.manager.mail'),
      $container->get('myeventlane_pro.abandoned_cart_terminal_state_resolver'),
      $container->has('myeventlane_messaging.manager') ? $container->get('myeventlane_messaging.manager') : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process(Job $job): JobResult {
    $trackingId = (int) ($job->getPayload()['tracking_id'] ?? 0);
    if ($trackingId <= 0) {
      return JobResult::failure('Missing tracking_id payload.');
    }

    $row = $this->loadTrackingRow($trackingId);
    if ($row === NULL) {
      return JobResult::failure("Tracking row {$trackingId} not found.");
    }

    if (!in_array((string) $row['status'], [self::STATUS_SCHEDULED, self::STATUS_QUEUED], TRUE)) {
      return JobResult::success("Tracking row {$trackingId} already processed.");
    }

    $orderId = (int) ($row['order_id'] ?? 0);
    $order = $this->entityTypeManager->getStorage('commerce_order')->load((int) $row['order_id']);
    if (!$order instanceof OrderInterface) {
      $this->markTrackingRow($trackingId, self::STATUS_FAILED, 'Order missing.');
      return JobResult::failure("Order {$orderId} not found.");
    }

    if ($order->isNew() || !$this->orderHasPurchasableItems($order)) {
      $this->markTrackingRow($trackingId, self::STATUS_SKIPPED, 'Order has no purchasable line items.');
      return JobResult::success("Tracking row {$trackingId} skipped: order has no purchasable line items.");
    }

    $eligibility = $this->validateSendEligibility($order);
    if ($eligibility !== TRUE) {
      $this->markTrackingRow($trackingId, self::STATUS_SKIPPED, $eligibility);
      return JobResult::success("Tracking row {$trackingId} skipped: {$eligibility}");
    }

    $recipient = $this->resolveRecipientEmail($order);
    if ($recipient === NULL) {
      $this->markTrackingRow($trackingId, self::STATUS_FAILED, 'No recipient email.');
      return JobResult::failure("Order {$orderId} has no recipient email.");
    }

    $step = (string) $row['step'];
    $sent = $this->sendReminder($order, $step, $recipient);
    if (!$sent) {
      $this->markTrackingRow($trackingId, self::STATUS_FAILED, 'Reminder dispatch failed.');
      unset($order);
      return JobResult::failure("Failed to send reminder for order {$orderId}.");
    }

    $storeId = (int) ($row['store_id'] ?? 0);
    $this->recordAttribution($orderId, $storeId, $step);
    $this->markTrackingRow($trackingId, self::STATUS_SENT, NULL);

    unset($order);
    return JobResult::success("Sent abandoned cart {$step} reminder for order {$orderId}.");
  }

  /**
   * Re-checks eligibility before dispatching email.
   */
  private function validateSendEligibility(OrderInterface $order): bool|string {
    if ($order->isNew()) {
      return 'Order entity is not persisted.';
    }

    if (!$this->orderHasPurchasableItems($order)) {
      return 'Order has no items.';
    }

    if ($this->terminalStateResolver->isTerminalState($order)) {
      return 'Order no longer abandoned.';
    }

    $state = $order->getState()->getId();

    if (!(bool) $order->get('cart')->value && !in_array($state, ['draft', 'cart'], TRUE)) {
      return 'Order not in abandoned-cart state.';
    }

    $store = $order->getStore();
    if (!$store instanceof StoreInterface) {
      return 'Order has no store.';
    }

    if (!$this->proActiveResolver->isStoreProActive($store)) {
      return 'Store owner is not Pro.';
    }

    return TRUE;
  }

  /**
   * TRUE when the order has at least one line item with quantity greater than zero.
   *
   * Commerce orders do not implement a generic entity isEmpty(); emptiness for carts
   * is determined from order items (matches AbandonedCartScheduler).
   */
  private function orderHasPurchasableItems(OrderInterface $order): bool {
    foreach ($order->getItems() as $orderItem) {
      if ((float) $orderItem->getQuantity() > 0) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Sends reminder via MessagingManager template or fallback mail.
   */
  private function sendReminder(OrderInterface $order, string $step, string $recipient): bool {
    $template = $step === 'w2' ? 'pro_cart_abandoned_w2' : 'pro_cart_abandoned_w1';
    $cartUrl = Url::fromRoute('commerce_cart.page', [], ['absolute' => TRUE])->toString();

    $firstName = 'there';
    $customer = $order->getCustomer();
    if ($customer && $customer->getDisplayName() !== '') {
      $firstName = $customer->getDisplayName();
    }

    $context = [
      'first_name' => $firstName,
      'cart_url' => $cartUrl,
      'order_id' => (int) $order->id(),
      'step' => $step,
      'item_count' => count($order->getItems()),
      'trial_days' => max(0, (int) $this->configFactory
        ->get('commerce_recurring.commerce_billing_schedule.mel_pro_monthly')
        ->get('configuration.trial_interval.number')),
      'monthly_price' => 'A$' . number_format(
        (float) ($this->configFactory->get('myeventlane_pro.settings')->get('pro_price') ?? 49),
        2,
      ),
    ];

    $templateConfig = $this->configFactory->get("myeventlane_messaging.template.{$template}");
    $templateEnabled = (bool) $templateConfig->get('enabled');
    if ($templateEnabled && $this->messagingManager instanceof MessagingManager) {
      $messageId = $this->messagingManager->queue($template, $recipient, $context, [
        'langcode' => $order->language()->getId(),
        'idempotency_key' => sprintf('order:%d:%s', (int) $order->id(), $template),
      ]);
      if ($messageId !== NULL) {
        return TRUE;
      }
    }

    $subject = 'Finish setting up MyEventLane Pro';
    $body = [
      "Hi {$firstName},",
      '',
      'You started setting up MyEventLane Pro but did not finish.',
      sprintf(
        'Pro includes a %d-day free period, then %s per month until cancelled.',
        $context['trial_days'],
        $context['monthly_price'],
      ),
      "Return to your secure Pro checkout: {$cartUrl}",
    ];
    $result = $this->mailManager->mail(
      'myeventlane_pro',
      'myeventlane_pro_abandoned_cart',
      $recipient,
      $order->language()->getId(),
      [
        'subject' => $subject,
        'body' => $body,
      ],
      NULL,
      TRUE,
    );

    return !empty($result['result']);
  }

  /**
   * Resolves recipient email from order mail or customer account.
   */
  private function resolveRecipientEmail(OrderInterface $order): ?string {
    $email = trim((string) $order->getEmail());
    if ($email !== '') {
      return $email;
    }

    $customer = $order->getCustomer();
    if ($customer && $customer->getEmail() !== '') {
      return trim((string) $customer->getEmail());
    }

    return NULL;
  }

  /**
   * Loads one tracking row.
   *
   * @return array<string, mixed>|null
   *   Tracking row or NULL.
   */
  private function loadTrackingRow(int $trackingId): ?array {
    $row = $this->database->select('myeventlane_pro_abandoned_cart', 't')
      ->fields('t')
      ->condition('id', $trackingId)
      ->execute()
      ->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  /**
   * Marks a tracking row as final.
   */
  private function markTrackingRow(int $trackingId, string $status, ?string $message): void {
    $this->database->update('myeventlane_pro_abandoned_cart')
      ->fields([
        'status' => $status,
        'processed' => $this->time->getRequestTime(),
        'message' => $message,
      ])
      ->condition('id', $trackingId)
      ->execute();

    if ($status === self::STATUS_FAILED) {
      $this->logger->error('Pro abandoned cart row @id failed: @message', [
        '@id' => $trackingId,
        '@message' => $message ?? 'Unknown error',
      ]);
    }
  }

  /**
   * Inserts/updates deterministic attribution rows for reminder sends.
   */
  private function recordAttribution(int $orderId, int $storeId, string $trackingStep): void {
    if ($orderId <= 0 || $storeId <= 0) {
      $this->logger->error(
        'Attribution write skipped due to invalid identifiers. order_id=@order_id store_id=@store_id step=@step',
        [
          '@order_id' => $orderId,
          '@store_id' => $storeId,
          '@step' => $trackingStep,
        ],
      );
      return;
    }

    $now = $this->time->getRequestTime();

    try {
      $this->database->merge('myeventlane_pro_recovery_attribution')
        ->keys([
          'order_id' => $orderId,
          'tracking_step' => $trackingStep,
        ])
        ->insertFields([
          'order_id' => $orderId,
          'store_id' => $storeId,
          'tracking_step' => $trackingStep,
          'sent_at' => $now,
          'recovered' => 0,
          'created' => $now,
        ])
        ->updateFields([
          'store_id' => $storeId,
          'sent_at' => $now,
        ])
        ->execute();
    }
    catch (\Throwable $exception) {
      $this->logger->error('Failed to persist recovery attribution for order @order_id: @message', [
        '@order_id' => $orderId,
        '@message' => $exception->getMessage(),
      ]);
    }
  }

}
