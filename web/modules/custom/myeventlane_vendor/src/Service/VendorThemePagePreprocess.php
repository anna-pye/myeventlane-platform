<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\OptionalServiceResolver;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Preprocesses page variables for the vendor theme (sidebar, header, URLs).
 *
 * Extracted from myeventlane_vendor_theme_preprocess_page(). Behaviour and
 * $variables structure are identical to the prior inline implementation.
 */
final class VendorThemePagePreprocess {

  use StringTranslationTrait;

  /**
   * Constructs a VendorThemePagePreprocess.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\myeventlane_core\Service\OptionalServiceResolver $optionalServiceResolver
   *   Resolver for optional services (e.g. domain_detector).
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly RouteMatchInterface $routeMatch,
    private readonly RequestStack $requestStack,
    private readonly OptionalServiceResolver $optionalServiceResolver,
    TranslationInterface $stringTranslation,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Preprocesses page variables for the vendor theme.
   *
   * @param array<string, mixed> $variables
   *   The preprocess variables (passed by reference).
   */
  public function preprocess(array &$variables): void {
    $account = $this->currentUser->getAccount();

    if ($this->currentUser->isAuthenticated()) {
      $variables['page']['user_name'] = $account->getDisplayName();
      $variables['page']['user_initials'] = $this->getInitials($account->getDisplayName());

      $roles = $this->currentUser->getRoles();
      if (in_array('vendor', $roles, TRUE)) {
        $variables['page']['user_role'] = $this->t('Vendor');
      }
      elseif (in_array('administrator', $roles, TRUE)) {
        $variables['page']['user_role'] = $this->t('Administrator');
      }
      else {
        $variables['page']['user_role'] = $this->t('User');
      }
    }

    $variables['page']['workspace_name'] = NULL;
    $node = $this->routeMatch->getParameter('node');
    if ($node instanceof NodeInterface && $node->bundle() === 'event') {
      if ($node->hasField('field_event_vendor') && !$node->get('field_event_vendor')->isEmpty()) {
        $vendor = $node->get('field_event_vendor')->entity;
        if ($vendor) {
          $variables['page']['workspace_name'] = $vendor->label();
        }
      }
    }

    try {
      $domain_detector = $this->optionalServiceResolver->get('myeventlane_core.domain_detector');
      if ($domain_detector !== NULL) {
        $variables['page']['main_site_url'] = $domain_detector->buildDomainUrl('/', 'public');
      }
      else {
        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
          $scheme = $request->getScheme();
          $host = preg_replace('/^(vendor|admin)\./', '', $request->getHost());
          $variables['page']['main_site_url'] = $scheme . '://' . $host . '/';
        }
        else {
          $variables['page']['main_site_url'] = '/';
        }
      }
    }
    catch (\Exception) {
      $variables['page']['main_site_url'] = '/';
    }

    $variables['page']['is_admin'] = FALSE;
    $variables['page']['admin_portal_url'] = NULL;
    if ($this->currentUser->isAuthenticated()) {
      $roles = $this->currentUser->getRoles();
      if (in_array('administrator', $roles, TRUE) || $this->currentUser->id() === 1) {
        $variables['page']['is_admin'] = TRUE;

        try {
          $admin_url = Url::fromRoute('myeventlane_admin_dashboard.overview', [], ['absolute' => TRUE]);
          $variables['page']['admin_portal_url'] = $admin_url->toString(TRUE)->getGeneratedUrl();
        }
        catch (\Exception) {
          try {
            $domain_detector = $this->optionalServiceResolver->get('myeventlane_core.domain_detector');
            if ($domain_detector !== NULL) {
              $variables['page']['admin_portal_url'] = $domain_detector->buildDomainUrl('/admin/myeventlane', 'admin');
            }
          }
          catch (\Exception) {
            // Service not available.
          }

          if (($variables['page']['admin_portal_url'] ?? '') === '') {
            $request = $this->requestStack->getCurrentRequest();
            if ($request) {
              $scheme = $request->getScheme();
              $host = preg_replace('/^vendor\./', 'admin.', $request->getHost());
              if (!str_starts_with($host, 'admin.')) {
                $host = 'admin.' . preg_replace('/^(admin|vendor)\./', '', $host);
              }
              $variables['page']['admin_portal_url'] = $scheme . '://' . $host . '/admin/myeventlane';
            }
            else {
              $variables['page']['admin_portal_url'] = '/admin/myeventlane';
            }
          }
        }
      }
    }

    $route_name = $this->routeMatch->getRouteName();
    $variables['page']['active_section'] = $this->getActiveSection($route_name);

    $variables['page']['logout_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Log out'),
      '#url' => Url::fromRoute('user.logout'),
    ];

    if ($this->currentUser->isAuthenticated()) {
      $user_menu_items = [
        [
          '#type' => 'link',
          '#title' => $this->t('Profile'),
          '#url' => Url::fromRoute('entity.user.canonical', ['user' => $this->currentUser->id()]),
          '#attributes' => ['class' => ['user-menu__item']],
        ],
        [
          '#type' => 'link',
          '#title' => $this->t('Log out'),
          '#url' => Url::fromRoute('user.logout'),
          '#attributes' => ['class' => ['user-menu__item']],
        ],
      ];
      $variables['page']['user_menu'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['user-menu']],
        'user_info' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['user-menu__info']],
          'initials' => [
            '#markup' => '<span class="user-menu__initials">' . ($variables['page']['user_initials'] ?? 'U') . '</span>',
          ],
          'name' => [
            '#markup' => '<span class="user-menu__name">' . ($variables['page']['user_name'] ?? 'User') . '</span>',
          ],
        ],
        'dropdown' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['user-menu__dropdown']],
          'items' => $user_menu_items,
        ],
      ];
    }
    else {
      $variables['page']['user_menu'] = [];
    }

    $variables['page']['quick_actions'] = [];
    if (($variables['page']['is_admin'] ?? FALSE) && !empty($variables['page']['admin_portal_url'])) {
      $variables['page']['quick_actions'][] = [
        '#type' => 'link',
        '#title' => $this->t('Admin Portal'),
        '#url' => Url::fromUri($variables['page']['admin_portal_url']),
        '#attributes' => ['class' => ['mel-btn', 'mel-btn--secondary', 'mel-header__action-btn']],
      ];
    }
    if ($this->currentUser->isAuthenticated()) {
      $variables['page']['quick_actions'][] = [
        '#type' => 'link',
        '#title' => $this->t('+ Create Event'),
        '#url' => Url::fromRoute('myeventlane_event.wizard.create'),
        '#attributes' => ['class' => ['mel-btn', 'mel-btn--primary', 'mel-header__action-btn']],
      ];
    }

    if ($route_name === 'myeventlane_vendor.console.settings' && isset($variables['page']['content']['vendor_profile_settings'])) {
      $form = $variables['page']['content']['vendor_profile_settings'];
      $variables['page']['content']['vendor_profile_settings'] = [
        '#theme' => 'myeventlane_vendor_console_page',
        '#title' => 'Settings',
        '#body' => $form,
        '#attached' => $form['#attached'] ?? [],
      ];
    }
  }

  /**
   * Get user initials from display name.
   */
  private function getInitials(string $name): string {
    $parts = explode(' ', trim($name));
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
      if ($part !== '') {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
      }
    }
    return $initials !== '' ? $initials : 'U';
  }

  /**
   * Determine active section from route name.
   */
  private function getActiveSection(?string $route_name): string {
    if ($route_name === NULL || $route_name === '') {
      return 'dashboard';
    }
    $mapping = [
      'myeventlane_vendor.console.dashboard' => 'dashboard',
      'myeventlane_vendor.console.events' => 'events',
      'myeventlane_vendor.console.events_add' => 'events',
      'myeventlane_vendor.console.event_overview' => 'events',
      'myeventlane_vendor.console.event_tickets' => 'events',
      'myeventlane_vendor.console.event_attendees' => 'events',
      'myeventlane_vendor.console.event_rsvps' => 'events',
      'myeventlane_vendor.console.event_analytics' => 'events',
      'myeventlane_vendor.console.event_settings' => 'events',
      'myeventlane_analytics.dashboard' => 'analytics',
      'myeventlane_analytics.event' => 'analytics',
      'myeventlane_donations.vendor_list' => 'donations',
      'myeventlane_donations.platform' => 'donations',
      'myeventlane_vendor.console.payouts' => 'payouts',
      'myeventlane_vendor.console.boost' => 'boost',
      'myeventlane_vendor.console.audience' => 'audience',
      'myeventlane_vendor.console.settings' => 'settings',
      'myeventlane_vendor.dashboard' => 'dashboard',
      'myeventlane_vendor.console.dashboard' => 'dashboard',
    ];
    return $mapping[$route_name] ?? 'dashboard';
  }

}
