<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;

/**
 * Centralises Pro logic and feature gating.
 *
 * Uses mel_pro role exclusively for access. ProEntitlementReconciler keeps
 * the role in sync with subscription state.
 */
final class ProAccessService {

  /**
   * The canonical Pro role ID.
   */
  private const PRO_ROLE = 'mel_pro';

  /**
   * Feature keys that require Pro.
   */
  private const PRO_FEATURES = [
    'advanced_analytics',
    'audience_messaging',
    'boost_priority',
    'event_immersive_style',
  ];

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ProSubscriptionStatusService $statusService,
  ) {}

  /**
   * Checks whether the account has Pro access.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return bool
   *   TRUE if the user has the mel_pro role.
   */
  public function isPro(AccountInterface $account): bool {
    return $account->hasRole(self::PRO_ROLE);
  }

  /**
   * Whether the account has manual (admin-assigned) Pro access.
   */
  public function isManualPro(AccountInterface $account): bool {
    $status = $this->getProStatus($account);
    return $status['is_manual_pro'] ?? FALSE;
  }

  /**
   * Whether the account is in a grace period after payment failure.
   */
  public function isInGrace(AccountInterface $account): bool {
    $status = $this->getProStatus($account);
    return $status['is_in_grace'] ?? FALSE;
  }

  /**
   * Gets the full Pro status for UI and access decisions.
   *
   * @return array<string, mixed>
   *   Status array from ProSubscriptionStatusService.
   */
  public function getProStatus(AccountInterface $account): array {
    if ($account->isAnonymous()) {
      return [
        'is_pro' => FALSE,
        'is_manual_pro' => FALSE,
        'is_in_grace' => FALSE,
        'status_label' => 'Inactive',
        'status_message' => 'Upgrade to MEL Pro to unlock advanced organiser tools.',
      ];
    }

    $user = $this->entityTypeManager->getStorage('user')->load((int) $account->id());
    return $user instanceof UserInterface
      ? $this->statusService->getStatusForUser($user)
      : [
        'is_pro' => $this->isPro($account),
        'is_manual_pro' => FALSE,
        'is_in_grace' => FALSE,
        'status_label' => $this->isPro($account) ? 'Active' : 'Inactive',
        'status_message' => $this->isPro($account)
          ? 'Your MEL Pro subscription is active.'
          : 'Upgrade to MEL Pro to unlock advanced organiser tools.',
      ];
  }

  /**
   * Ensures the canonical MEL Pro role exists.
   *
   * Call during module install or when hardening the system.
   */
  public function ensureCanonicalRole(): void {
    $storage = $this->entityTypeManager->getStorage('user_role');
    if ($storage->load(self::PRO_ROLE) !== NULL) {
      return;
    }
    $role = $storage->create([
      'id' => self::PRO_ROLE,
      'label' => 'MEL Pro',
    ]);
    $role->save();
  }

  /**
   * Checks whether the account has access to a given feature.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   * @param string $feature
   *   Feature key (e.g. advanced_analytics, audience_messaging, boost_priority).
   *
   * @return bool
   *   TRUE if the user has access (Pro or feature is not Pro-gated).
   */
  public function hasFeature(AccountInterface $account, string $feature): bool {
    return $this->canAccessFeature($account, $feature);
  }

  /**
   * Whether the account can access a Pro-gated feature.
   *
   * Pro (active, grace, or manual) can access. Inactive cannot.
   */
  public function canAccessFeature(AccountInterface $account, string $feature): bool {
    if ($this->isPro($account)) {
      return TRUE;
    }
    return !in_array($feature, self::PRO_FEATURES, TRUE);
  }

}
