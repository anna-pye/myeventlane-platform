<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Attribute\AdvancedQueueJobType;
use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\DeclineException;
use Drupal\commerce_recurring\RecurringOrderManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\myeventlane_pro\Service\ProPaymentRecoveryService;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Performs one organiser- or staff-requested Pro recovery attempt.
 */
#[AdvancedQueueJobType(
  id: 'myeventlane_pro_payment_retry',
  label: new TranslatableMarkup('Retry MEL Pro payment'),
  max_retries: 1,
  retry_delay: 300,
  allow_duplicates: FALSE,
)]
final class ProPaymentRetryJob extends JobTypeBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    mixed $plugin_id,
    mixed $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RecurringOrderManagerInterface $recurringOrderManager,
    private readonly ProPaymentRecoveryService $recoveryService,
    private readonly LoggerInterface $logger,
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
      $container->get('entity_type.manager'),
      $container->get('commerce_recurring.order_manager'),
      $container->get('myeventlane_pro.payment_recovery'),
      $container->get('logger.channel.myeventlane_pro'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process(Job $job): JobResult {
    $orderId = (int) ($job->getPayload()['order_id'] ?? 0);
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($orderId);
    if (!$order instanceof OrderInterface) {
      return JobResult::failure('The Pro renewal order was not found.');
    }
    if ($order->isPaid() || in_array($order->getState()->getId(), ['canceled', 'completed'], TRUE)) {
      return JobResult::success('The Pro renewal order no longer needs payment.');
    }

    $organiser = $order->getCustomer();
    if (!$organiser instanceof UserInterface || !$this->recoveryService->isInActiveGracePeriod($organiser)) {
      return JobResult::success('The organiser is no longer in an active Pro grace period.');
    }

    try {
      // This is the same Commerce service used by the recurring close job, so
      // successful payment still emits the normal order lifecycle events.
      $this->recurringOrderManager->closeOrder($order);
    }
    catch (DeclineException $exception) {
      // The normal recurring job retains ownership of the retry schedule and
      // dunning sequence. This manual attempt must not create a second cycle.
      $this->logger->notice('Manual MEL Pro payment retry declined for order @oid: @message', [
        '@oid' => (string) $orderId,
        '@message' => $exception->getMessage(),
      ]);
      return JobResult::success('The saved payment method was declined. Normal dunning remains scheduled.');
    }

    return JobResult::success('The Pro payment retry completed.');
  }

}
