<?php

declare(strict_types=1);

namespace Drupal\myeventlane_support\Service;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Applies public support-search limits and records privacy-safe usage events.
 */
final class SupportSearchRequestGuard {

  private const BURST_EVENT = 'myeventlane_support.search_burst';

  private const HOURLY_EVENT = 'myeventlane_support.search_hourly';

  private const BURST_LIMIT = 60;

  private const BURST_WINDOW = 60;

  private const HOURLY_LIMIT = 600;

  private const HOURLY_WINDOW = 3600;

  public function __construct(
    private readonly FloodInterface $flood,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns whether this client may perform another search.
   */
  public function isAllowed(Request $request): bool {
    $identifier = $this->identifier($request);

    if (!$this->flood->isAllowed(self::BURST_EVENT, self::BURST_LIMIT, self::BURST_WINDOW, $identifier)) {
      return FALSE;
    }
    if (!$this->flood->isAllowed(self::HOURLY_EVENT, self::HOURLY_LIMIT, self::HOURLY_WINDOW, $identifier)) {
      return FALSE;
    }

    $this->flood->register(self::BURST_EVENT, self::BURST_WINDOW, $identifier);
    $this->flood->register(self::HOURLY_EVENT, self::HOURLY_WINDOW, $identifier);
    return TRUE;
  }

  /**
   * Records an aggregate-friendly event without query text or client identity.
   */
  public function record(string $outcome, int $resultCount = 0): void {
    $this->logger->info('Legacy support search API request: {outcome}; results: {result_count}.', [
      'outcome' => $outcome,
      'result_count' => max(0, $resultCount),
    ]);
  }

  /**
   * Builds an installation-specific, non-reversible flood identifier.
   */
  private function identifier(Request $request): string {
    $clientIp = $request->getClientIp() ?? 'unknown';
    return Crypt::hmacBase64('support-search|' . $clientIp, Settings::getHashSalt());
  }

}
