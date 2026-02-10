<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_policy\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles policy trigger actions: create staff review escalation or email staff.
 *
 * Does NOT auto-message vendors or customers.
 * Does NOT suspend vendors.
 * All wording is friendly, neutral, and gender-safe.
 */
final class PolicyActionHandler {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MailManagerInterface $mailManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Handles a policy trigger for a single vendor.
   *
   * @param int $vendor_id
   *   The vendor entity ID.
   * @param string $vendor_name
   *   The vendor display name.
   * @param int $risk_streak
   *   Number of consecutive at-risk weeks.
   * @param string $bucket
   *   Current health bucket.
   * @param array $metrics
   *   Computed metrics from the evaluation window.
   */
  public function handle(int $vendor_id, string $vendor_name, int $risk_streak, string $bucket, array $metrics): void {
    $config = $this->configFactory->get('myeventlane_escalations_policy.settings');
    $action_mode = (string) ($config->get('action_mode') ?? 'create_escalation');

    $summary = $this->buildSummary($vendor_name, $risk_streak, $bucket, $metrics);

    if ($action_mode === 'create_escalation') {
      $this->createReviewEscalation($vendor_id, $vendor_name, $risk_streak, $summary);
    }
    elseif ($action_mode === 'email_only') {
      $this->emailStaff($vendor_name, $risk_streak, $summary);
    }
    else {
      $this->logger->error('Policy: unknown action_mode "{mode}".', ['mode' => $action_mode]);
    }
  }

  /**
   * Creates a staff-only escalation for vendor review.
   */
  private function createReviewEscalation(int $vendor_id, string $vendor_name, int $risk_streak, string $summary): void {
    try {
      $storage = $this->entityTypeManager->getStorage('escalation');

      /** @var \Drupal\myeventlane_escalations\Entity\Escalation $escalation */
      $escalation = $storage->create([
        'subject' => 'Vendor review: ' . $vendor_name,
        'description' => [
          'value' => $summary,
          'format' => 'plain_text',
        ],
        'type' => 'vendor',
        'status' => 'new',
        'priority' => 'high',
        'user_id' => 1,
        'vendor_id' => $vendor_id,
      ]);

      // Set waiting_on to staff if the field exists.
      if ($escalation->hasField('field_waiting_on')) {
        $escalation->set('field_waiting_on', 'staff');
      }

      $escalation->save();

      $this->logger->notice('Policy: created review escalation {eid} for vendor {vendor} (streak: {streak}).', [
        'eid' => $escalation->id(),
        'vendor' => $vendor_name,
        'streak' => $risk_streak,
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->error('Policy: failed to create review escalation for vendor {vendor}: {error}', [
        'vendor' => $vendor_name,
        'error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Emails staff with the vendor review summary.
   */
  private function emailStaff(string $vendor_name, int $risk_streak, string $summary): void {
    $config = $this->configFactory->get('myeventlane_escalations_policy.settings');
    $emails = $config->get('staff_notification_emails') ?? [];

    // Fall back to site mail if no addresses configured.
    if (empty($emails)) {
      $site_mail = $this->configFactory->get('system.site')->get('mail');
      if ($site_mail) {
        $emails = [$site_mail];
      }
    }

    if (empty($emails)) {
      $this->logger->warning('Policy: no staff email addresses configured and no site mail available.');
      return;
    }

    foreach ($emails as $email) {
      try {
        $this->mailManager->mail(
          'myeventlane_escalations_policy',
          'policy_review',
          $email,
          'en',
          [
            'vendor_name' => $vendor_name,
            'risk_streak' => $risk_streak,
            'summary' => $summary,
          ],
        );

        $this->logger->info('Policy: sent review email to {email} for vendor {vendor}.', [
          'email' => $email,
          'vendor' => $vendor_name,
        ]);
      }
      catch (\Throwable $e) {
        $this->logger->error('Policy: failed to send review email to {email}: {error}', [
          'email' => $email,
          'error' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Builds a friendly, neutral summary for staff review.
   */
  private function buildSummary(string $vendor_name, int $risk_streak, string $bucket, array $metrics): string {
    $total = (int) ($metrics['total_escalations'] ?? 0);
    $resolution_rate = (float) ($metrics['resolution_rate'] ?? 0);
    $avg_response = (float) ($metrics['avg_first_response_hours'] ?? 0);
    $avg_resolution = (float) ($metrics['avg_resolution_hours'] ?? 0);

    $lines = [];
    $lines[] = 'This is an automated weekly review notice.';
    $lines[] = '';
    $lines[] = $vendor_name . ' has been flagged as needing attention for ' . $risk_streak . ' consecutive week(s).';
    $lines[] = '';
    $lines[] = 'Current health status: ' . ucfirst($bucket);
    $lines[] = 'Resolution rate: ' . $resolution_rate . '%';
    $lines[] = 'Average first response time: ' . $avg_response . 'h';
    $lines[] = 'Average resolution time: ' . $avg_resolution . 'h';
    $lines[] = 'Total escalations (evaluation window): ' . $total;
    $lines[] = '';
    $lines[] = 'Recommended action: Please review this vendor\'s recent escalation activity and determine if any support or follow-up is needed.';
    $lines[] = '';
    $lines[] = 'This notice was generated automatically by the escalation policy system.';

    return implode("\n", $lines);
  }

}
