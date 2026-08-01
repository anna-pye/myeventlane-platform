<?php

declare(strict_types=1);

namespace Drupal\myeventlane_legal\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;

/**
 * Shared service to assert vendor has accepted vendor terms.
 *
 * Used by CreateEventGatewayController and vendor event entry controllers.
 * to avoid duplication.
 */
final class LegalGatekeeper {

  use StringTranslationTrait;

  /**
   * Constructs the gatekeeper.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LegalSettingsService $legalSettings,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly MessengerInterface $messenger,
    TranslationInterface $stringTranslation,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Asserts the current user's vendor has accepted vendor terms.
   *
   * Admins (uid 1 or administer site configuration) bypass.
   *
   * @throws \Drupal\Core\Form\EnforcedResponseException
   *   Redirect to terms page when vendor has not accepted terms.
   */
  public function assertVendorTermsAccepted(): void {
    if ($this->currentUser->hasPermission('administer site configuration') ||
        (int) $this->currentUser->id() === 1) {
      return;
    }

    $request = \Drupal::request();
    $path = $request->getPathInfo();
    // Do not redirect to terms or block flows while user is on organiser onboarding paths
    // (/vendor/onboard/terms, profile, etc.); terms are collected in that funnel.
    if (str_contains($path, '/vendor/onboard')) {
      return;
    }

    $vendor = $this->getCurrentVendorOrNull();
    if (!$vendor) {
      return;
    }

    if ($this->vendorHasAcceptedTerms($vendor)) {
      return;
    }

    $this->loggerFactory->get('myeventlane_legal')->info(
      'Vendor @vid blocked from event wizard: vendor terms not accepted.',
      ['@vid' => $vendor->id()]
    );
    $this->messenger->addWarning($this->t('You must accept the Vendor Terms of Service before creating events.'));
    $url = Url::fromRoute('myeventlane_legal.vendor_terms');
    throw new EnforcedResponseException(new TrustedRedirectResponse($url->toString()));
  }

  /**
   * Checks whether the current user's vendor has accepted vendor terms.
   */
  public function hasVendorAcceptedTerms(): bool {
    return $this->getVendorTermsAcceptedAt() !== NULL;
  }

  /**
   * Returns the authoritative vendor Terms acceptance timestamp.
   */
  public function getVendorTermsAcceptedAt(): ?int {
    $vendor = $this->getCurrentVendorOrNull();
    if (!$vendor || !$this->vendorHasAcceptedTerms($vendor)) {
      return NULL;
    }

    return (int) $vendor->get('field_vendor_terms_accepted_at')->value;
  }

  /**
   * Checks whether a vendor entity has accepted vendor terms.
   *
   * @param \Drupal\Core\Entity\EntityInterface $vendor
   *   The vendor entity.
   */
  public function vendorHasAcceptedTerms($vendor): bool {
    if (!$vendor->hasField('field_vendor_terms_accepted_at') ||
        $vendor->get('field_vendor_terms_accepted_at')->isEmpty()) {
      return FALSE;
    }
    $timestamp = (int) $vendor->get('field_vendor_terms_accepted_at')->value;
    return $timestamp > 0;
  }

  /**
   * Gets the current user's vendor entity.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   */
  private function getCurrentVendorOrNull(): ?\Drupal\Core\Entity\EntityInterface {
    $uid = (int) $this->currentUser->id();
    if ($uid === 0) {
      return NULL;
    }
    if (!$this->entityTypeManager->hasDefinition('myeventlane_vendor')) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('myeventlane_vendor');
    $owner_ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $uid)
      ->range(0, 1)
      ->execute();

    if (!empty($owner_ids)) {
      $vendor = $storage->load(reset($owner_ids));
      if ($vendor) {
        return $vendor;
      }
    }

    $user_ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_vendor_users', $uid)
      ->range(0, 1)
      ->execute();

    if (!empty($user_ids)) {
      $vendor = $storage->load(reset($user_ids));
      if ($vendor) {
        return $vendor;
      }
    }

    return NULL;
  }

}
