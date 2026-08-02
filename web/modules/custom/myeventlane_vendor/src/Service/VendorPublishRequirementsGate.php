<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Drupal\myeventlane_legal\Service\LegalGatekeeper;
use Drupal\myeventlane_vendor\Entity\Vendor;

/**
 * Organiser readiness for publishing events live (profile, terms, Stripe Connect).
 *
 * Event Studio entry stays permissive; this gate runs only when an event would
 * become published (see EventStudioSaveService).
 */
final class VendorPublishRequirementsGate {

  use StringTranslationTrait;

  public function __construct(
    private readonly UserVendorMembershipQuery $userVendorMembershipQuery,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly OnboardingManager $onboardingManager,
    private readonly LegalGatekeeper $legalGatekeeper,
    private readonly VendorEventStudioCreateService $eventStudioCreate,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Human-readable blockers for making an event live (empty = allowed).
   *
   * @return list<string>
   */
  public function getLivePublishDenialReasons(AccountInterface $account): array {
    if ((int) $account->id() === 1 || $account->hasPermission('administer site configuration')) {
      return [];
    }

    $uid = (int) $account->id();
    if ($uid <= 0) {
      return [(string) $this->t('You must be signed in to publish.')];
    }

    $vendorIds = $this->userVendorMembershipQuery->getVendorIdsForUser($uid);
    if ($vendorIds === []) {
      return [(string) $this->t('Complete your organiser profile before publishing.')];
    }

    /** @var \Drupal\myeventlane_vendor\Entity\Vendor|null $vendor */
    $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load(reset($vendorIds));
    if (!$vendor instanceof Vendor) {
      return [(string) $this->t('Complete your organiser profile before publishing.')];
    }

    $reasons = [];

    if (!$this->legalGatekeeper->hasVendorAcceptedTerms()) {
      $reasons[] = (string) $this->t('Accept terms to publish.');
    }

    $state = $this->onboardingManager->loadVendorStateByUid($uid);
    if ($state === NULL || !$this->onboardingManager->isCompleted($state)) {
      $reasons[] = (string) $this->t('Complete your organiser profile before publishing.');
    }

    return $reasons;
  }

  /**
   * Flags for soft warnings in Event Studio (TRUE = satisfied).
   *
   * @return array{terms: bool, profile: bool, stripe: bool}
   */
  public function getReadinessFlags(AccountInterface $account): array {
    if ((int) $account->id() === 1 || $account->hasPermission('administer site configuration')) {
      return [
        'terms' => TRUE,
        'profile' => TRUE,
        'stripe' => TRUE,
      ];
    }

    $uid = (int) $account->id();
    if ($uid <= 0) {
      return [
        'terms' => FALSE,
        'profile' => FALSE,
        'stripe' => FALSE,
      ];
    }

    $terms = $this->legalGatekeeper->hasVendorAcceptedTerms();
    $state = $this->onboardingManager->loadVendorStateByUid($uid);
    $profile = $state !== NULL && $this->onboardingManager->isCompleted($state);

    $stripe = FALSE;
    $vendorIds = $this->userVendorMembershipQuery->getVendorIdsForUser($uid);
    if ($vendorIds !== []) {
      $vendor = $this->entityTypeManager->getStorage('myeventlane_vendor')->load(reset($vendorIds));
      if ($vendor instanceof Vendor) {
        $stripe = $this->eventStudioCreate->isVendorStripeConnected($vendor, $uid, $account);
      }
    }

    return [
      'terms' => $terms,
      'profile' => $profile,
      'stripe' => $stripe,
    ];
  }

}
