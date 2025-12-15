<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Controller;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\myeventlane_core\Service\DomainDetector;
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
    *  - ownership via node owner OR linked vendor entity users
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
        'myeventlane_theme/global',
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

}
