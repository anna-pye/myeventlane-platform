<?php

declare(strict_types=1);

namespace Drupal\myeventlane_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Circuit breaker for AI provider: trips on failures or cost ceiling.
 *
 * When tripped: AiManager returns error without calling provider.
 * Uses State API for persistence. Keys:
 * - myeventlane_ai.cb.{provider}.failures = JSON array of timestamps
 * - myeventlane_ai.cb.{provider}.tripped_until = Unix timestamp
 * - myeventlane_ai.cb.{provider}.daily_cost = float (reset at midnight)
 * - myeventlane_ai.cb.{provider}.cost_date = YYYYMMDD
 */
final class AiCircuitBreaker {

  public function __construct(
    private readonly StateInterface $state,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Checks if circuit is tripped.
   */
  public function isTripped(string $provider = 'openai'): bool {
    $key_tripped = 'myeventlane_ai.cb.' . $provider . '.tripped_until';
    $tripped_until = $this->state->get($key_tripped, 0);
    if ($tripped_until > time()) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Records a provider failure. May trip the circuit.
   */
  public function recordFailure(string $provider = 'openai'): void {
    $config = $this->configFactory->get('myeventlane_ai.settings');
    $threshold = (int) $config->get('cb_failure_threshold') ?: 10;
    $window = (int) $config->get('cb_window_seconds') ?: 600;

    $key_failures = 'myeventlane_ai.cb.' . $provider . '.failures';
    $failures = $this->state->get($key_failures, []);
    if (!is_array($failures)) {
      $failures = [];
    }

    $now = time();
    $cutoff = $now - $window;
    $failures[] = $now;
    $failures = array_values(array_filter($failures, fn ($t) => $t >= $cutoff));

    $this->state->set($key_failures, $failures);

    if (count($failures) >= $threshold) {
      $this->trip($provider, 'failures', $window);
    }
  }

  /**
   * Records cost and checks daily ceiling.
   *
   * When ceiling is hit, circuit trips for cb_cost_trip_seconds (default 24h).
   * This is distinct from cb_window_seconds (transient failures) so we do not
   * repeatedly re-trip the same day.
   */
  public function recordCost(string $provider, float $cost_usd): void {
    $config = $this->configFactory->get('myeventlane_ai.settings');
    $ceiling = (float) $config->get('daily_cost_ceiling_usd') ?: 10;
    $cost_trip_seconds = (int) $config->get('cb_cost_trip_seconds') ?: 86400;

    $today = date('Ymd', time());
    $key_cost = 'myeventlane_ai.cb.' . $provider . '.daily_cost';
    $key_date = 'myeventlane_ai.cb.' . $provider . '.cost_date';

    $stored_date = $this->state->get($key_date, '');
    $current_cost = (float) $this->state->get($key_cost, 0);

    if ($stored_date !== $today) {
      $current_cost = 0;
      $this->state->set($key_date, $today);
    }

    $current_cost += $cost_usd;
    $this->state->set($key_cost, $current_cost);

    if ($current_cost >= $ceiling) {
      $this->trip($provider, 'cost_ceiling', $cost_trip_seconds);
    }
  }

  /**
   * Trips the circuit for the configured window.
   */
  public function tripManually(string $provider, string $reason, int $window_seconds = 600): void {
    $this->trip($provider, $reason, $window_seconds);
  }

  private function trip(string $provider, string $reason, int $window_seconds): void {
    $key_tripped = 'myeventlane_ai.cb.' . $provider . '.tripped_until';
    $tripped_until = time() + $window_seconds;
    $this->state->set($key_tripped, $tripped_until);
    $this->logger->notice('AI circuit breaker tripped: provider={provider} reason={reason} until={until}', [
      'provider' => $provider,
      'reason' => $reason,
      'until' => date('c', $tripped_until),
    ]);
  }

  /**
   * Resets failure count (e.g. after successful call). Optional.
   */
  public function recordSuccess(string $provider = 'openai'): void {
    $key_failures = 'myeventlane_ai.cb.' . $provider . '.failures';
    $this->state->set($key_failures, []);
  }

}
