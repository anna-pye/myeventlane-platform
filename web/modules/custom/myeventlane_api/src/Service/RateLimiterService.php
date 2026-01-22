<?php

declare(strict_types=1);

namespace Drupal\myeventlane_api\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\HttpFoundation\Request;

/**
 * Service for rate limiting API requests.
 */
final class RateLimiterService {

  /**
   * Rate limit periods (in seconds).
   */
  public const PERIOD_MINUTE = 60;
  public const PERIOD_HOUR = 3600;
  public const PERIOD_DAY = 86400;

  /**
   * Default rate limits.
   */
  // Per minute.
  public const DEFAULT_PUBLIC_LIMIT = 60;
  // Per hour.
  public const DEFAULT_VENDOR_LIMIT = 1000;

  /**
   * Constructs RateLimiterService.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Checks if a request should be rate limited.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   * @param string $identifier
   *   Rate limit identifier (IP address or token/vendor ID).
   * @param int $limit
   *   Maximum number of requests allowed.
   * @param int $period
   *   Time period in seconds.
   *
   * @return array
   *   Array with keys:
   *   - 'allowed': bool - Whether the request is allowed.
   *   - 'remaining': int - Number of requests remaining.
   *   - 'reset': int - Unix timestamp when limit resets.
   */
  public function checkLimit(Request $request, string $identifier, int $limit, int $period = self::PERIOD_MINUTE): array {
    $now = $this->time->getRequestTime();
    $window_start = $now - $period;

    // Clean up old entries (older than the period).
    $this->cleanup($window_start);

    // Count requests in the current window.
    $count = $this->database->select('myeventlane_api_rate_limit', 'rl')
      ->condition('identifier', $identifier)
      ->condition('timestamp', $window_start, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();

    $remaining = max(0, $limit - (int) $count);
    $allowed = $remaining > 0;

    // Record this request attempt.
    if ($allowed) {
      $this->recordRequest($identifier, $now);
    }

    // Calculate reset time (start of next window).
    $reset = (int) (floor($now / $period) + 1) * $period;

    return [
      'allowed' => $allowed,
      'remaining' => $remaining,
      'reset' => $reset,
    ];
  }

  /**
   * Records a request for rate limiting.
   *
   * @param string $identifier
   *   Rate limit identifier.
   * @param int $timestamp
   *   Request timestamp.
   */
  private function recordRequest(string $identifier, int $timestamp): void {
    $this->database->insert('myeventlane_api_rate_limit')
      ->fields([
        'identifier' => $identifier,
        'timestamp' => $timestamp,
      ])
      ->execute();
  }

  /**
   * Cleans up old rate limit records.
   *
   * @param int $before_timestamp
   *   Delete records before this timestamp.
   */
  private function cleanup(int $before_timestamp): void {
    // Only clean up occasionally to avoid overhead.
    // Use a simple probabilistic cleanup (10% chance).
    if (rand(1, 10) === 1) {
      $this->database->delete('myeventlane_api_rate_limit')
        ->condition('timestamp', $before_timestamp, '<')
        ->execute();
    }
  }

  /**
   * Gets the client IP address from request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request.
   *
   * @return string
   *   The IP address.
   */
  public function getClientIp(Request $request): string {
    $ip = $request->getClientIp();
    // Normalize IPv6 to avoid storing full addresses.
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
      // For IPv6, use a hash to avoid storage issues.
      return 'ipv6:' . substr(hash('sha256', $ip), 0, 16);
    }
    return $ip;
  }

}
