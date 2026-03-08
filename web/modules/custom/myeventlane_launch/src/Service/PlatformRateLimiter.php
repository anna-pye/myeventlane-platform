<?php

declare(strict_types=1);

namespace Drupal\myeventlane_launch\Service;

use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Launch-level rate limiter built on Drupal flood storage.
 */
final class PlatformRateLimiter {

  public function __construct(
    private readonly FloodInterface $flood,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Enforces a flood limit and registers the attempt.
   */
  public function enforce(string $eventName, string $identifier, int $maxAttempts, int $windowSeconds): void {
    if (!$this->flood->isAllowed($eventName, $maxAttempts, $windowSeconds, $identifier)) {
      $this->logger->warning('Rate limit exceeded for event {event} and identifier {identifier}.', [
        'event' => $eventName,
        'identifier' => $identifier,
      ]);
      throw new AccessDeniedHttpException('Too many attempts. Please wait and try again.');
    }

    $this->flood->register($eventName, $windowSeconds, $identifier);
  }

  /**
   * Creates a stable identifier from user ID or request IP.
   */
  public function buildIdentifier(Request $request, string $scope): string {
    if ($this->currentUser->isAuthenticated()) {
      return $scope . ':uid:' . (string) $this->currentUser->id();
    }
    return $scope . ':ip:' . ($request->getClientIp() ?? 'unknown');
  }

}
