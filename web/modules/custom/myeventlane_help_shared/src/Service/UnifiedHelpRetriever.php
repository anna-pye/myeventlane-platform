<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_shared\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\myeventlane_help_assistant\Service\HelpRetriever;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Wraps HelpRetriever with explicit account context and a policy validation pass.
 */
final class UnifiedHelpRetriever {

  public function __construct(
    private readonly HelpRetriever $helpRetriever,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Retrieves help_article rows for a specific user context.
   *
   * Switches the active account so HelpRetriever applies the correct audience
   * and access checks, then re-validates each hit against MEL policy.
   *
   * @return array<int, array<string, mixed>>
   *   Same shape as HelpRetriever::retrieve().
   */
  public function retrieveForUser(string $query, AccountInterface $user, int $limit = 3): array {
    $this->accountSwitcher->switchTo($user);
    try {
      $rows = $this->helpRetriever->retrieve($query, $limit);
    }
    finally {
      $this->accountSwitcher->switchBack();
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $filtered = [];
    foreach ($rows as $row) {
      $nid = (int) ($row['nid'] ?? 0);
      if ($nid < 1) {
        continue;
      }
      $node = $storage->load($nid);
      if (!$node instanceof NodeInterface || $node->bundle() !== 'help_article') {
        continue;
      }
      $failureReason = NULL;
      if (!$this->validateNode($node, $user, $failureReason)) {
        if ($failureReason !== NULL) {
          $this->logPolicyMismatch($node, $failureReason, $user);
        }
        continue;
      }
      $filtered[] = $row;
    }

    return $filtered;
  }

  /**
   * Enforces MEL help policy independently of HelpRetriever (defence in depth).
   *
   * @param string|null $failureReason
   *   Populated with a machine reason when this returns FALSE.
   */
  private function validateNode(NodeInterface $node, AccountInterface $user, ?string &$failureReason): bool {
    $failureReason = NULL;
    if (!$node->isPublished()) {
      $failureReason = 'not_published';
      return FALSE;
    }
    if (!$node->access('view', $user)) {
      $failureReason = 'access_denied';
      return FALSE;
    }
    if (!$node->hasField('field_help_ai_allowed') || $node->get('field_help_ai_allowed')->isEmpty()
      || !(bool) $node->get('field_help_ai_allowed')->value) {
      $failureReason = 'ai_not_allowed';
      return FALSE;
    }
    if ($node->hasField('field_help_status') && !$node->get('field_help_status')->isEmpty()) {
      $status = (string) $node->get('field_help_status')->value;
      if (!in_array($status, ['published', 'approved'], TRUE)) {
        $failureReason = 'status_invalid';
        return FALSE;
      }
    }
    $audienceReason = NULL;
    if (!$this->nodeAudienceAllowed($node, $user, $audienceReason)) {
      $failureReason = $audienceReason ?? 'audience_mismatch';
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Mirrors HelpRetriever audience rules using the explicit account.
   *
   * @param string|null $audienceReason
   *   Set when returning FALSE (staff_audience or audience_mismatch).
   */
  private function nodeAudienceAllowed(NodeInterface $node, AccountInterface $user, ?string &$audienceReason): bool {
    $audienceReason = NULL;
    if (!$node->hasField('field_audience') || $node->get('field_audience')->isEmpty()) {
      $audienceReason = 'audience_mismatch';
      return FALSE;
    }
    foreach ($node->get('field_audience') as $item) {
      $value = (string) $item->value;
      if ($value === 'staff') {
        $audienceReason = 'staff_audience';
        return FALSE;
      }
      if (!in_array($value, ['public', 'vendor'], TRUE)) {
        $audienceReason = 'audience_mismatch';
        return FALSE;
      }
    }
    $allowed = $user->isAuthenticated() ? ['public', 'vendor'] : ['public'];
    foreach ($node->get('field_audience') as $item) {
      $value = (string) $item->value;
      if (in_array($value, $allowed, TRUE)) {
        return TRUE;
      }
    }
    $audienceReason = 'audience_mismatch';
    return FALSE;
  }

  private function logPolicyMismatch(NodeInterface $node, string $reason, AccountInterface $user): void {
    $userType = $user->isAuthenticated() ? 'authenticated' : 'anonymous';
    $this->logger->notice('Unified help retrieval policy filter: nid=@nid title=@title reason=@reason user_type=@user_type', [
      '@nid' => (string) $node->id(),
      '@title' => $node->label(),
      '@reason' => $reason,
      '@user_type' => $userType,
    ]);
  }

}
