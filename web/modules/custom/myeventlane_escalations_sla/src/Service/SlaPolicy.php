<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_sla\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Reads SLA policy config and returns hours per priority.
 */
final class SlaPolicy {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns first response SLA hours for the given priority.
   */
  public function getFirstResponseHours(string $priority): int {
    $config = $this->configFactory->get('myeventlane_escalations_sla.settings');
    return (int) ($config->get('sla_hours.' . $priority . '.first_response') ?? $this->getDefaultFirstResponseHours($priority));
  }

  /**
   * Returns resolution SLA hours for the given priority.
   */
  public function getResolutionHours(string $priority): int {
    $config = $this->configFactory->get('myeventlane_escalations_sla.settings');
    return (int) ($config->get('sla_hours.' . $priority . '.resolution') ?? $this->getDefaultResolutionHours($priority));
  }

  /**
   * Returns escalation thresholds sorted by hours_past_due ascending.
   *
   * @return array<int, array{hours_past_due: int, level: int}>
   */
  public function getEscalationThresholds(): array {
    $config = $this->configFactory->get('myeventlane_escalations_sla.settings');
    $thresholds = (array) ($config->get('escalation_thresholds') ?? []);
    usort($thresholds, static fn(array $a, array $b): int => ($a['hours_past_due'] ?? 0) <=> ($b['hours_past_due'] ?? 0));
    return $thresholds;
  }

  /**
   * Whether SLA enforcement is enabled.
   */
  public function isEnabled(): bool {
    return (bool) $this->configFactory->get('myeventlane_escalations_sla.settings')->get('enabled');
  }

  /**
   * Whether breach emails are enabled.
   */
  public function breachEmailsEnabled(): bool {
    return (bool) $this->configFactory->get('myeventlane_escalations_sla.settings')->get('breach_emails.enabled');
  }

  private function getDefaultFirstResponseHours(string $priority): int {
    return match ($priority) {
      'urgent' => 1,
      'high' => 4,
      'normal' => 8,
      'low' => 24,
      default => 8,
    };
  }

  private function getDefaultResolutionHours(string $priority): int {
    return match ($priority) {
      'urgent' => 4,
      'high' => 24,
      'normal' => 72,
      'low' => 168,
      default => 72,
    };
  }

}
