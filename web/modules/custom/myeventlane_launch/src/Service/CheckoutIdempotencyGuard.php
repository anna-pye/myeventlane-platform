<?php

declare(strict_types=1);

namespace Drupal\myeventlane_launch\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Prevents duplicate checkout completion processing.
 */
final class CheckoutIdempotencyGuard {

  private const COLLECTION = 'myeventlane_checkout_sessions';

  public function __construct(
    private readonly KeyValueFactoryInterface $keyValueFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Builds a stable checkout session key.
   */
  public function buildSessionId(OrderInterface $order, ?SessionInterface $session = NULL): string {
    return 'checkout:order:' . (string) $order->uuid();
  }

  /**
   * Returns TRUE if checkout session has already completed.
   */
  public function isCompleted(string $sessionId): bool {
    $record = $this->getStore()->get($sessionId);
    return is_array($record) && ($record['status'] ?? NULL) === 'completed';
  }

  /**
   * Throws when checkout session is already completed.
   */
  public function assertNotCompleted(string $sessionId): void {
    if ($this->isCompleted($sessionId)) {
      $this->logger->warning('Duplicate checkout attempt blocked for session {session_id}.', [
        'session_id' => $sessionId,
      ]);
      throw new AccessDeniedHttpException('This checkout session is already completed.');
    }
  }

  /**
   * Marks checkout session as completed.
   */
  public function markCompleted(string $sessionId, array $context = []): void {
    $this->getStore()->set($sessionId, [
      'status' => 'completed',
      'completed_at' => time(),
      'context' => $context,
    ]);
  }

  /**
   * Returns internal key-value store.
   */
  private function getStore(): \Drupal\Core\KeyValueStore\KeyValueStoreInterface {
    return $this->keyValueFactory->get(self::COLLECTION);
  }

}
