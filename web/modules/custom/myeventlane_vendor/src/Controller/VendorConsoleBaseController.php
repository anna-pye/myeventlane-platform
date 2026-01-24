<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
use Symfony\Component\HttpFoundation\TrustedRedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Base controller for vendor console pages.
 */
abstract class VendorConsoleBaseController {

  /**
   * Constructs the base controller.
   */
  public function __construct(
    protected readonly DomainDetector $domainDetector,
    protected readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Ensures user and domain are allowed for vendor console.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   When access is denied.
   */
  protected function assertVendorAccess(): void {
    // Administrators always have access.
    if ($this->currentUser->hasPermission('administer site configuration') ||
        $this->currentUser->id() === 1) {
      return;
    }

    // Check permission - vendors with access vendor console permission.
    if (!$this->currentUser->hasPermission('access vendor console')) {
      throw new AccessDeniedHttpException('You are not authorized to access this page.');
    }

    // If on vendor domain, allow access.
    if ($this->domainDetector->isVendorDomain()) {
      return;
    }

    // User has permission but is on main domain - still allow access
    // (theme negotiator will handle theme switching, but we prefer vendor domain).
    // For now, we allow access from both domains if user has permission.
    return;
  }

  /**
   * Ensures the current user can manage the given event.
   *
   * This checks:
   *  - vendor domain
   *  - vendor console permission
   *  - ownership via node owner OR linked vendor entity users.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   When access is denied.
   */
  protected function assertEventOwnership(NodeInterface $event): void {
    $this->assertVendorAccess();

    if ($this->currentUser->hasPermission('administer nodes')) {
      return;
    }

    // Owner check.
    if ((int) $event->getOwnerId() === (int) $this->currentUser->id()) {
      return;
    }

    // Vendor membership check via field_event_vendor -> field_vendor_users.
    if ($event->hasField('field_event_vendor') && !$event->get('field_event_vendor')->isEmpty()) {
      $vendor = $event->get('field_event_vendor')->entity;
      if ($vendor && $vendor->hasField('field_vendor_users')) {
        foreach ($vendor->get('field_vendor_users')->getValue() as $item) {
          if (isset($item['target_id']) && (int) $item['target_id'] === (int) $this->currentUser->id()) {
            return;
          }
        }
      }
    }

    throw new AccessDeniedHttpException();
  }

  /**
   * Builds a vendor render array with the vendor theme attached.
   *
   * @param string $theme_hook
   *   The theme hook to use.
   * @param array $variables
   *   Additional variables for the theme.
   *
   * @return array
   *   The render array.
   */
  protected function buildVendorPage(string $theme_hook, array $variables = []): array {
    $this->assertVendorAccess();

    $base_attached = [
      'library' => [
        'myeventlane_vendor_theme/global-styling',
      ],
    ];

    // Merge in additional attachments provided by controllers.
    if (isset($variables['#attached']) && is_array($variables['#attached'])) {
      $extra = $variables['#attached'];
      unset($variables['#attached']);

      // Merge libraries.
      if (!empty($extra['library'])) {
        $base_attached['library'] = array_values(array_unique(array_merge($base_attached['library'], $extra['library'])));
      }

      // Merge drupalSettings.
      if (!empty($extra['drupalSettings'])) {
        $base_attached['drupalSettings'] = array_replace_recursive($base_attached['drupalSettings'] ?? [], $extra['drupalSettings']);
      }
    }

    // Build render array with proper variable keys for theme system.
    $render = [
      '#theme' => $theme_hook,
      '#attached' => $base_attached,
    ];

    // Add variables - these become available in the template.
    foreach ($variables as $key => $value) {
      // Theme variables use # prefix in render arrays.
      if (!str_starts_with((string) $key, '#')) {
        $render['#' . $key] = $value;
      }
      else {
        $render[$key] = $value;
      }
    }

    return $render;
  }

  /**
   * Gets the current vendor entity for the user.
   *
   * @return \Drupal\myeventlane_vendor\Entity\Vendor|null
   *   The vendor entity, or NULL if not found.
   */
  protected function getCurrentVendorOrNull() {
    $uid = (int) $this->currentUser->id();
    if ($uid === 0) {
      return NULL;
    }

    // Try to use entityTypeManager from child class if available.
    $entityTypeManager = $this->entityTypeManager ?? \Drupal::entityTypeManager();

    $storage = $entityTypeManager->getStorage('myeventlane_vendor');

    // First, try to find vendor where user is the owner.
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

    // If not found, check vendors where user is in field_vendor_users.
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

  /**
   * Asserts that the vendor has Stripe Connect configured.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   When Stripe is not connected.
   * @throws \Symfony\Component\HttpFoundation\TrustedRedirectResponse
   *   Redirects to Stripe onboarding if not connected.
   */
  protected function assertStripeConnected(): void {
    // Administrators bypass this check.
    if ($this->currentUser->hasPermission('administer site configuration') ||
        $this->currentUser->id() === 1) {
      return;
    }

    $vendor = $this->getCurrentVendorOrNull();
    if (!$vendor || !$vendor->hasField('field_vendor_store') || $vendor->get('field_vendor_store')->isEmpty()) {
      // No vendor or no store - redirect to onboarding.
      $this->getMessenger()->addError($this->t('You must connect your Stripe account before setting up events.'));
      $url = Url::fromRoute('myeventlane_vendor.onboard.stripe');
      throw new TrustedRedirectResponse($url->toString());
    }

    $store = $vendor->get('field_vendor_store')->entity;
    if (!$store) {
      $this->getMessenger()->addError($this->t('You must connect your Stripe account before setting up events.'));
      $url = Url::fromRoute('myeventlane_vendor.onboard.stripe');
      throw new TrustedRedirectResponse($url->toString());
    }

    // Check if Stripe is connected.
    $connected = FALSE;
    if ($store->hasField('field_stripe_connected') && !$store->get('field_stripe_connected')->isEmpty()) {
      $connected = (bool) $store->get('field_stripe_connected')->value;
    }

    // Also check charges_enabled as a stronger indicator.
    if (!$connected && $store->hasField('field_stripe_charges_enabled') && !$store->get('field_stripe_charges_enabled')->isEmpty()) {
      $connected = (bool) $store->get('field_stripe_charges_enabled')->value;
    }

    if (!$connected) {
      $this->getMessenger()->addError($this->t('You must connect your Stripe account before setting up events.'));
      $url = Url::fromRoute('myeventlane_vendor.onboard.stripe');
      throw new TrustedRedirectResponse($url->toString());
    }
  }

  /**
   * Gets the messenger service.
   *
   * @return \Drupal\Core\Messenger\MessengerInterface
   *   The messenger service.
   */
  protected function getMessenger(): MessengerInterface {
    if (!isset($this->messenger)) {
      $this->messenger = \Drupal::service('messenger');
    }
    return $this->messenger;
  }

  /**
   * Gets the translation service.
   *
   * @return \Drupal\Core\StringTranslation\TranslationInterface
   *   The translation service.
   */
  protected function t($string, array $args = [], array $options = []) {
    return \Drupal::translation()->translate($string, $args, $options);
  }

}
