<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_sla\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Checks SLA breaches, bumps escalation level, triggers breach emails.
 */
final class SlaEnforcer {

  public function __construct(
    private readonly SlaPolicy $policy,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MailManagerInterface $mailManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Enforces SLA policy on a single escalation.
   *
   * @param int $escalation_id
   *   The escalation entity ID.
   */
  public function enforce(int $escalation_id): void {
    if (!$this->policy->isEnabled()) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('escalation');
    /** @var \Drupal\myeventlane_escalations\Entity\Escalation|null $escalation */
    $escalation = $storage->load($escalation_id);

    if (!$escalation) {
      return;
    }

    // Skip resolved/closed escalations.
    $status = (string) $escalation->get('status')->value;
    if (in_array($status, ['resolved', 'closed'], TRUE)) {
      return;
    }

    $now = time();
    $changed = FALSE;

    // Check first response SLA breach.
    if ($escalation->hasField('field_sla_first_due')
      && !$escalation->get('field_sla_first_due')->isEmpty()
      && $escalation->hasField('field_sla_first_breached')) {

      $first_due = (int) $escalation->get('field_sla_first_due')->value;
      $already_breached = (bool) ($escalation->get('field_sla_first_breached')->value ?? 0);

      // Only flag breach if waiting_on is vendor (vendor's turn to respond).
      $waiting_on = $escalation->hasField('field_waiting_on') ? (string) $escalation->get('field_waiting_on')->value : '';

      if (!$already_breached && $first_due > 0 && $now > $first_due && $waiting_on === 'vendor') {
        $escalation->set('field_sla_first_breached', 1);
        $changed = TRUE;

        $this->logger->warning('SLA first response breached for escalation {id}', ['id' => $escalation_id]);

        if ($this->policy->breachEmailsEnabled()) {
          $this->sendBreachEmail($escalation, 'first_response');
        }
      }
    }

    // Check resolution SLA breach.
    if ($escalation->hasField('field_sla_resolution_due')
      && !$escalation->get('field_sla_resolution_due')->isEmpty()
      && $escalation->hasField('field_sla_resolution_breached')) {

      $resolution_due = (int) $escalation->get('field_sla_resolution_due')->value;
      $already_breached = (bool) ($escalation->get('field_sla_resolution_breached')->value ?? 0);

      if (!$already_breached && $resolution_due > 0 && $now > $resolution_due) {
        $escalation->set('field_sla_resolution_breached', 1);
        $changed = TRUE;

        $this->logger->warning('SLA resolution breached for escalation {id}', ['id' => $escalation_id]);

        if ($this->policy->breachEmailsEnabled()) {
          $this->sendBreachEmail($escalation, 'resolution');
        }
      }
    }

    // Bump escalation level based on thresholds.
    if ($escalation->hasField('field_sla_resolution_due')
      && !$escalation->get('field_sla_resolution_due')->isEmpty()
      && $escalation->hasField('field_escalation_level')) {

      $resolution_due = (int) $escalation->get('field_sla_resolution_due')->value;
      $current_level = (int) ($escalation->get('field_escalation_level')->value ?? 0);

      if ($resolution_due > 0 && $now > $resolution_due) {
        $hours_past = ($now - $resolution_due) / 3600;
        $thresholds = $this->policy->getEscalationThresholds();

        $new_level = 0;
        foreach ($thresholds as $threshold) {
          if ($hours_past >= ($threshold['hours_past_due'] ?? 0)) {
            $new_level = (int) ($threshold['level'] ?? 0);
          }
        }

        if ($new_level > $current_level) {
          $escalation->set('field_escalation_level', $new_level);
          $changed = TRUE;

          $this->logger->notice('Escalation {id} bumped to level {level}', [
            'id' => $escalation_id,
            'level' => $new_level,
          ]);
        }
      }
    }

    if ($changed) {
      $escalation->save();
    }
  }

  /**
   * Sends a breach notification email to the site admin.
   */
  private function sendBreachEmail(object $escalation, string $breach_type): void {
    $site_mail = $this->configFactory->get('system.site')->get('mail');
    if (empty($site_mail)) {
      return;
    }

    $subject_text = (string) $escalation->get('subject')->value;
    $escalation_id = (int) $escalation->id();

    $this->mailManager->mail(
      'myeventlane_escalations_sla',
      'sla_breach',
      $site_mail,
      'en',
      [
        'escalation_id' => $escalation_id,
        'subject' => $subject_text,
        'breach_type' => $breach_type,
      ],
    );
  }

}
