<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\commerce_recurring\Entity\SubscriptionInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\user\UserInterface;

/**
 * Returns a structured Pro subscription status for UI and access decisions.
 *
 * Reads from the canonical model: commerce_subscription, field_pro_*,
 * vendor.is_pro. No new Pro tables.
 */
final class ProSubscriptionStatusService {

  use StringTranslationTrait;

  private const BILLING_SCHEDULE = 'mel_pro_monthly';
  private const MANAGED_FIELD = 'field_pro_subscription_managed';
  private const GRACE_FIELD = 'field_pro_grace_expires';
  private const PRO_ROLE = 'mel_pro';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ProSubscriptionStateResolver $stateResolver,
    private readonly ProActiveResolver $activeResolver,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly TimeInterface $time,
    private readonly DateFormatterInterface $dateFormatter,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Gets the full Pro status for a user.
   *
   * @return array{
   *   is_pro: bool,
   *   is_subscription_managed: bool,
   *   has_active_subscription: bool,
   *   subscription_state: string,
   *   billing_schedule: string,
   *   grace_expires: int|null,
   *   grace_expires_label: string|null,
   *   grace_period_days: int,
   *   is_in_grace: bool,
   *   is_manual_pro: bool,
   *   can_manage_billing: bool,
   *   can_cancel: bool,
   *   status_label: string,
   *   status_message: string,
   *   state: string,
   *   plan_label: string,
   *   renews_at: int|null,
   *   renews_label: string|null,
   *   is_trial: bool,
   *   trial_ends_at: int|null,
   *   cancel_at_period_end: bool,
   *   ends_at: int|null,
   * }
   */
  public function getStatusForUser(UserInterface $user): array {
    $graceDays = (int) ($this->configFactory->get('myeventlane_pro.settings')->get('grace_days') ?? 7);
    $graceDays = max(1, $graceDays);

    $defaults = [
      'is_pro' => FALSE,
      'is_subscription_managed' => FALSE,
      'has_active_subscription' => FALSE,
      'subscription_state' => '',
      'billing_schedule' => self::BILLING_SCHEDULE,
      'grace_expires' => NULL,
      'grace_expires_label' => NULL,
      'grace_period_days' => $graceDays,
      'is_in_grace' => FALSE,
      'is_manual_pro' => FALSE,
      'can_manage_billing' => FALSE,
      'can_cancel' => FALSE,
      'status_label' => 'Inactive',
      'status_message' => 'Upgrade to MEL Pro to unlock advanced organiser tools.',
      'state' => 'inactive',
      'plan_label' => 'MEL Pro',
      'renews_at' => NULL,
      'renews_label' => NULL,
      'is_trial' => FALSE,
      'trial_ends_at' => NULL,
      'cancel_at_period_end' => FALSE,
      'ends_at' => NULL,
    ];

    if ($user->isAnonymous()) {
      return $defaults;
    }

    $subscription = $this->loadLatestProSubscription($user);
    $isManaged = $user->hasField(self::MANAGED_FIELD) && (bool) ($user->get(self::MANAGED_FIELD)->value ?? FALSE);
    $graceExpires = $user->hasField(self::GRACE_FIELD)
      ? (int) ($user->get(self::GRACE_FIELD)->value ?? 0)
      : 0;
    $now = $this->time->getRequestTime();
    $isInGrace = $graceExpires > 0 && $graceExpires >= $now;
    $hasRole = $user->hasRole(self::PRO_ROLE);
    $isPro = $this->activeResolver->isUserProActive($user);
    $hasActiveSubscription = $subscription !== NULL && $this->stateResolver->isActive($subscription);
    $isTrial = $subscription !== NULL && $this->stateResolver->isTrial($subscription);
    $cancelAtPeriodEnd = $subscription !== NULL && $subscription->hasScheduledChange('state', 'canceled');
    $trialEndsAt = $isTrial ? (int) $subscription->getTrialEndTime() : 0;
    $endsAt = $subscription !== NULL ? (int) $subscription->getEndTime() : 0;

    $subscriptionState = '';
    if ($subscription instanceof SubscriptionInterface) {
      $state = $subscription->getState();
      $subscriptionState = $state->getId() ?? '';
    }

    $isManualPro = $isPro && !$isManaged && !$hasActiveSubscription;

    $canManageBilling = $isPro && ($hasActiveSubscription || $isInGrace);
    // Cancellation requests are enabled by default in install config. Preserve
    // that contract for existing sites where the key has not yet been saved.
    $cancelRequestEnabled = (bool) ($this->configFactory->get('myeventlane_pro.settings')->get('cancel_request_enabled') ?? TRUE);
    $canCancel = $canManageBilling && $isManaged && $hasActiveSubscription && !$isInGrace && $cancelRequestEnabled;

    $label = 'Inactive';
    $message = 'Upgrade to MEL Pro to unlock advanced organiser tools.';
    $uiState = 'inactive';

    if ($isManualPro) {
      $label = 'Manual Pro';
      $message = 'You have manual MEL Pro access.';
      $uiState = 'active';
    }
    elseif ($isTrial) {
      $label = 'Trial';
      $message = 'Your MEL Pro trial is active.';
      $uiState = 'active';
    }
    elseif ($isInGrace) {
      $label = 'Grace period';
      $message = $this->formatGraceMessage($graceDays);
      $uiState = 'grace';
    }
    elseif ($hasActiveSubscription && $this->stateResolver->isActive($subscription)) {
      $label = 'Active';
      $message = 'Your MEL Pro subscription is active.';
      $uiState = 'active';
    }
    elseif ($subscription instanceof SubscriptionInterface && $subscriptionState !== '' && $this->stateResolver->isExpired($subscription)) {
      $label = 'Expired';
      $message = 'Your Pro subscription has expired.';
      $uiState = 'inactive';
    }
    elseif ($subscription instanceof SubscriptionInterface && $subscriptionState !== '' && $this->stateResolver->isCancelled($subscription)) {
      $label = 'Cancelled';
      $message = 'Your Pro subscription has been cancelled.';
      $uiState = 'inactive';
    }
    elseif ($subscription instanceof SubscriptionInterface && $subscriptionState !== '' && $this->stateResolver->isPaymentFailure($subscription)) {
      $label = 'Payment failed';
      $message = 'Your payment failed. Please update your payment method.';
      $uiState = $isPro ? 'grace' : 'inactive';
    }
    elseif ($isPro) {
      $label = 'Active';
      $message = 'Your MEL Pro subscription is active.';
      $uiState = 'active';
    }

    $graceExpiresLabel = NULL;
    if ($graceExpires > 0) {
      $graceExpiresLabel = $this->dateFormatter->format($graceExpires, 'custom', 'j M');
    }

    // Canonical renewal date: only the active subscription's next renewal time.
    // NULL when there is no active subscription (manual Pro, grace, etc.) — no
    // date is invented.
    $renewsAt = NULL;
    $renewsLabel = NULL;
    if ($hasActiveSubscription && !$isTrial) {
      $nextRenewal = $this->activeResolver->getUserActiveEndTimestamp($user);
      if (is_int($nextRenewal) && $nextRenewal > 0) {
        $renewsAt = $nextRenewal;
        $renewsLabel = $this->dateFormatter->format($nextRenewal, 'custom', 'j M Y');
      }
    }

    return [
      'is_pro' => $isPro,
      'is_subscription_managed' => $isManaged,
      'has_active_subscription' => $hasActiveSubscription,
      'subscription_state' => $subscriptionState,
      'billing_schedule' => self::BILLING_SCHEDULE,
      'grace_expires' => $graceExpires > 0 ? $graceExpires : NULL,
      'grace_expires_label' => $graceExpiresLabel,
      'grace_period_days' => $graceDays,
      'is_in_grace' => $isInGrace,
      'is_manual_pro' => $isManualPro,
      'can_manage_billing' => $canManageBilling,
      'can_cancel' => $canCancel,
      'status_label' => $label,
      'status_message' => $message,
      'state' => $uiState,
      'plan_label' => 'MEL Pro',
      'renews_at' => $renewsAt,
      'renews_label' => $renewsLabel,
      'is_trial' => $isTrial,
      'trial_ends_at' => $trialEndsAt > 0 ? $trialEndsAt : NULL,
      'cancel_at_period_end' => $cancelAtPeriodEnd,
      'ends_at' => $endsAt > 0 ? $endsAt : NULL,
    ];
  }

  /**
   * Gets Pro status for the current user.
   */
  public function getStatusForCurrentUser(): array {
    $graceDays = (int) ($this->configFactory->get('myeventlane_pro.settings')->get('grace_days') ?? 7);
    $graceDays = max(1, $graceDays);

    $empty = [
      'is_pro' => FALSE,
      'is_subscription_managed' => FALSE,
      'has_active_subscription' => FALSE,
      'subscription_state' => '',
      'billing_schedule' => self::BILLING_SCHEDULE,
      'grace_expires' => NULL,
      'grace_expires_label' => NULL,
      'grace_period_days' => $graceDays,
      'is_in_grace' => FALSE,
      'is_manual_pro' => FALSE,
      'can_manage_billing' => FALSE,
      'can_cancel' => FALSE,
      'status_label' => 'Inactive',
      'status_message' => 'Upgrade to MEL Pro to unlock advanced organiser tools.',
      'state' => 'inactive',
      'plan_label' => 'MEL Pro',
      'renews_at' => NULL,
      'renews_label' => NULL,
      'is_trial' => FALSE,
      'trial_ends_at' => NULL,
      'cancel_at_period_end' => FALSE,
      'ends_at' => NULL,
    ];

    $account = $this->currentUser;
    if ($account->isAnonymous()) {
      return $empty;
    }

    $user = $this->entityTypeManager->getStorage('user')->load((int) $account->id());
    if (!$user instanceof UserInterface) {
      return $empty;
    }
    return $this->getStatusForUser($user);
  }

  /**
   * Formats the standard grace-period user-facing sentence.
   */
  private function formatGraceMessage(int $graceDays): string {
    return (string) $this->t(
      'Your payment needs attention. Your Pro access is in a @days-day grace period.',
      ['@days' => (string) $graceDays],
    );
  }

  /**
   * Loads the user's latest Pro subscription.
   */
  private function loadLatestProSubscription(UserInterface $user): ?SubscriptionInterface {
    $ids = $this->entityTypeManager->getStorage('commerce_subscription')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', (int) $user->id())
      ->condition('billing_schedule', self::BILLING_SCHEDULE)
      ->sort('subscription_id', 'DESC')
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return NULL;
    }

    $subscription = $this->entityTypeManager
      ->getStorage('commerce_subscription')
      ->load(reset($ids));

    return $subscription instanceof SubscriptionInterface ? $subscription : NULL;
  }

}
