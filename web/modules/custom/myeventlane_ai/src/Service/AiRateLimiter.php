<?php

declare(strict_types=1);

namespace Drupal\myeventlane_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Psr\Log\LoggerInterface;

/**
 * Rate limiting via Flood API.
 */
final class AiRateLimiter {

  public function __construct(
    private readonly FloodInterface $flood,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Checks and records a per-user request.
   *
   * @param int $uid
   *   The user ID to rate-limit.
   *
   * @return bool
   *   TRUE if the request is allowed, FALSE if rate-limited.
   */
  public function checkAndRecordUser(int $uid): bool {
    $config = $this->configFactory->get('myeventlane_ai.settings');
    if ($config->get('rate_limit_enabled') === FALSE) {
      return TRUE;
    }

    $limit = (int) $config->get('rate_limit.per_user_per_hour') ?: 10;

    $name = 'myeventlane_ai.user';
    $identifier = 'uid:' . $uid;

    if (!$this->flood->isAllowed($name, $limit, 3600, $identifier)) {
      $this->logger->notice('AI rate limit hit for {id}', ['id' => $identifier]);
      return FALSE;
    }

    $this->flood->register($name, 3600, $identifier);
    return TRUE;
  }

  /**
   * Checks and records a scoped request (e.g. per escalation).
   *
   * @param string $scope_id
   *   A scope identifier like "escalation:42".
   *
   * @return bool
   *   TRUE if the request is allowed, FALSE if rate-limited.
   */
  public function checkAndRecordScope(string $scope_id): bool {
    $config = $this->configFactory->get('myeventlane_ai.settings');
    if ($config->get('rate_limit_enabled') === FALSE) {
      return TRUE;
    }

    $limit = (int) $config->get('rate_limit.per_scope_per_10_min') ?: 5;

    $name = 'myeventlane_ai.scope';
    $identifier = 'scope:' . $scope_id;

    if (!$this->flood->isAllowed($name, $limit, 600, $identifier)) {
      $this->logger->notice('AI scope rate limit hit for {id}', ['id' => $identifier]);
      return FALSE;
    }

    $this->flood->register($name, 600, $identifier);
    return TRUE;
  }

}
