<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_core\VendorConsoleTrust;
use Drupal\myeventlane_vendor\Service\EventVendorAccessCheckerInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Base controller for vendor console pages.
 */
abstract class VendorConsoleBaseController {

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected readonly MessengerInterface $messenger;

  /**
   * Canonical ownership checker (optional for subclass ctor compat).
   */
  private readonly ?EventVendorAccessCheckerInterface $eventVendorAccessChecker;

  /**
   * Constructs the base controller.
   *
   * The fourth argument is optional so existing subclass constructors that
   * pass only domain detector / current user / messenger keep working. When
   * omitted, the checker is resolved from the container on first assert.
   */
  public function __construct(
    protected readonly DomainDetector $domainDetector,
    protected readonly AccountProxyInterface $currentUser,
    MessengerInterface $messenger,
    ?EventVendorAccessCheckerInterface $eventVendorAccessChecker = NULL,
  ) {
    $this->messenger = $messenger;
    $this->eventVendorAccessChecker = $eventVendorAccessChecker;
  }

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

    // Permission or organiser role (aligned with VendorConsoleAccess::access).
    if (!VendorConsoleTrust::accountIsTrustedForVendorConsole($this->currentUser)) {
      throw new AccessDeniedHttpException('You are not authorized to access this page.');
    }

    // If on vendor domain, allow access.
    if ($this->domainDetector->isVendorDomain()) {
      return;
    }

    // User has permission but is on main domain - still allow access
    // (theme negotiator handles theme; vendor domain preferred).
    // Allow access from both domains if user has permission.
  }

  /**
   * Ensures the current user can manage the given event.
   *
   * This checks:
   *  - vendor domain
   *  - vendor console permission
   *  - workspace parity via EventVendorAccessChecker
   *  - administer nodes staff bypass (caller-side; not in the checker)
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   When access is denied.
   */
  protected function assertEventOwnership(NodeInterface $event): void {
    $this->assertVendorAccess();

    if ($this->currentUser->hasPermission('administer nodes')) {
      return;
    }

    // Canonical ownership API — thin wrapper (Workstream 1).
    $checker = $this->getEventVendorAccessChecker();
    if ($checker->accountHasWorkspaceParityForEvent($event, $this->currentUser)) {
      return;
    }

    throw new AccessDeniedHttpException();
  }

  /**
   * Resolves the canonical event ownership checker.
   */
  protected function getEventVendorAccessChecker(): EventVendorAccessCheckerInterface {
    if ($this->eventVendorAccessChecker instanceof EventVendorAccessCheckerInterface) {
      return $this->eventVendorAccessChecker;
    }

    // Matches other optional resolutions in this base
    // (entityTypeManager, request).
    $checker = \Drupal::service('myeventlane_vendor.event_access_checker');
    if (!$checker instanceof EventVendorAccessCheckerInterface) {
      throw new \RuntimeException(
        'Service myeventlane_vendor.event_access_checker must implement EventVendorAccessCheckerInterface.',
      );
    }
    return $checker;
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

    // AJAX form callbacks must receive the inner render array only; wrapping in
    // the vendor console theme would return full HTML and break AjaxResponse.
    $http_request = \Drupal::request();
    if ($http_request->isXmlHttpRequest()) {
      $inner = $variables['content'] ?? $variables['body'] ?? NULL;
      if ($inner !== NULL) {
        return is_array($inner) ? $inner : ['#markup' => (string) $inner];
      }
    }

    $base_attached = [
      'library' => [
        'myeventlane_vendor_theme/global-styling',
      ],
    ];

    if ($theme_hook === 'mel_event_workspace') {
      $base_attached['library'][] = 'myeventlane_vendor_theme/event_mission_control';
    }

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

    // Legacy callers pass main column as `body`; prefer `content`.
    if (!isset($render['#content']) && isset($render['#body'])) {
      $render['#content'] = $render['#body'];
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
   * @throws \Drupal\Core\Form\EnforcedResponseException
   *   Redirect to Stripe onboarding if not connected.
   */
  protected function assertStripeConnected(): void {
    // Administrators bypass this check.
    if ($this->currentUser->hasPermission('administer site configuration') ||
        $this->currentUser->id() === 1) {
      return;
    }

    $vendor = $this->getCurrentVendorOrNull();
    $http_request = \Drupal::request();
    $destination = $http_request instanceof Request
      && $http_request->getPathInfo() !== ''
      ? (string) $http_request->getPathInfo()
      : '/create-event';
    $stripeConnectUrl = Url::fromRoute('myeventlane_vendor.stripe_connect', [], [
      'query' => ['destination' => $destination],
    ]);

    if (!$vendor) {
      $this->getMessenger()->addError($this->t('You must connect your Stripe account before setting up events.'));
      throw new EnforcedResponseException(new TrustedRedirectResponse($stripeConnectUrl->toString()));
    }

    $store = NULL;
    if ($vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
      $store = $vendor->get('field_vendor_store')->entity;
    }
    if (!$store) {
      $entityTypeManager = $this->entityTypeManager ?? \Drupal::entityTypeManager();
      $storeStorage = $entityTypeManager->getStorage('commerce_store');
      $storeIds = $storeStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', (int) $this->currentUser->id())
        ->range(0, 1)
        ->execute();
      if (!empty($storeIds)) {
        $store = $storeStorage->load(reset($storeIds));
        if ($store && $vendor->hasField('field_vendor_store')) {
          $vendor->set('field_vendor_store', $store->id());
          $vendor->save();
        }
      }
    }
    if (!$store) {
      $this->getMessenger()->addError($this->t('You must connect your Stripe account before setting up events.'));
      throw new EnforcedResponseException(new TrustedRedirectResponse($stripeConnectUrl->toString()));
    }

    // Check if Stripe is connected.
    $connected = FALSE;
    if ($store->hasField('field_stripe_connected') && !$store->get('field_stripe_connected')->isEmpty()) {
      $connected = (bool) $store->get('field_stripe_connected')->value;
    }

    if (!$connected && $store->hasField('field_stripe_charges_enabled') && !$store->get('field_stripe_charges_enabled')->isEmpty()) {
      $connected = (bool) $store->get('field_stripe_charges_enabled')->value;
    }

    if (!$connected) {
      $this->getMessenger()->addError($this->t('You must connect your Stripe account before setting up events.'));
      throw new EnforcedResponseException(new TrustedRedirectResponse($stripeConnectUrl->toString()));
    }
  }

  /**
   * Gets the messenger service.
   *
   * @return \Drupal\Core\Messenger\MessengerInterface
   *   The messenger service.
   */
  protected function getMessenger(): MessengerInterface {
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
