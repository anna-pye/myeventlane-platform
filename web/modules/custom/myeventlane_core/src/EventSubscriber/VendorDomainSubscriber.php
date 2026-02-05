<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Event subscriber to enforce domain-based routing.
 *
 * Redirects vendor routes to vendor domain and public routes to public domain.
 */
final class VendorDomainSubscriber implements EventSubscriberInterface {

  /**
   * Vendor route patterns that must be on vendor domain.
   *
   * @var array<string>
   */
  private const VENDOR_ROUTE_PATTERNS = [
    'myeventlane_vendor.',
    'myeventlane_vendor.console.dashboard',
    'myeventlane_boost.',
    'myeventlane_event_attendees.vendor',
    'myeventlane_commerce.stripe',
    'entity.node.add_form',
    'entity.node.edit_form',
  ];

  /**
   * Public route patterns that must be on public domain.
   *
   * @var array<string>
   */
  private const PUBLIC_ROUTE_PATTERNS = [
    'view.upcoming_events',
    'view.new_events',
    'view.featured_events',
    'myeventlane_commerce.event_book',
    'myeventlane_rsvp.',
    'myeventlane_dashboard.customer',
    'commerce_cart.',
    'commerce_checkout.',
  ];

  /**
   * Routes that are allowed on both domains.
   *
   * @var array<string>
   */
  private const ALLOWED_ON_BOTH = [
    'system.404',
    'system.403',
    'user.login',
    'user.logout',
    'user.register',
    'user.pass',
    'user.reset',
    'myeventlane_boost.performance_guide_pdf',
    'entity.myeventlane_vendor.canonical',
    'myeventlane_vendor.public_list',
    'myeventlane_vendor.organisers',
    'system.form_action',
  ];

  /**
   * Constructs a VendorDomainSubscriber object.
   *
   * @param \Drupal\myeventlane_core\Service\DomainDetector $domainDetector
   *   The domain detector service.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The route match service.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    private readonly DomainDetector $domainDetector,
    private readonly RouteMatchInterface $routeMatch,
    private readonly AccountProxyInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Run after routing so _route is available for exclusions/guards.
      KernelEvents::REQUEST => ['onRequest', 0],
    ];
  }

  /**
   * Handles the request event.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onRequest(RequestEvent $event): void {
    // Skip for sub-requests.
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    $path = $request->getPathInfo();
    $route_name = (string) $request->attributes->get('_route');
    if ($route_name === '') {
      return;
    }

    // Never domain-switch during vendor onboarding.
    // Onboarding must remain on the public domain to preserve session.
    if (is_string($route_name) && str_starts_with($route_name, 'myeventlane_vendor.onboard')) {
      return;
    }

    // Never redirect AJAX/XHR requests.
    if ($request->isXmlHttpRequest()) {
      return;
    }

    // Explicit route exclusions (mandatory).
    // These must never trigger redirects, to prevent redirect loops and to avoid
    // interfering with authentication and onboarding flows.
    $excluded_routes = [
      'user.login',
      'user.logout',
      'user.pass',
      'user.reset',
      // Core AJAX endpoints.
      'system.ajax',
      'ajax_form',
      'views.ajax',
      // Vendor onboarding flow.
      'myeventlane_vendor.onboard',
      'myeventlane_vendor.onboard.account',
      'myeventlane_vendor.onboard.profile',
      'myeventlane_vendor.onboard.stripe',
      'myeventlane_vendor.onboard.branding',
      'myeventlane_vendor.onboard.first_event',
      'myeventlane_vendor.onboard.boost',
      'myeventlane_vendor.onboard.complete',
    ];
    if (in_array($route_name, $excluded_routes, TRUE)) {
      return;
    }

    // HARD EXCLUSION: Never vendor-intent redirect onboarding itself.
    // This prevents redirect loops during onboarding step transitions.
    if (str_starts_with($path, '/vendor/onboard')) {
      return;
    }

    $is_vendor_domain = $this->domainDetector->isVendorDomain();

    // Redirect vendor domain root to vendor dashboard (always, regardless of
    // force_redirects).
    if ($is_vendor_domain && ($path === '/' || $path === '' || $request->attributes->get('_route') === '<front>')) {
      // If user is anonymous, redirect to login first, then to dashboard.
      if ($this->currentUser->isAnonymous()) {
        try {
          $login_url = $this->domainDetector->buildDomainUrl('/user/login?destination=/vendor/dashboard', 'vendor');
        }
        catch (\Throwable $e) {
          $this->loggerFactory->get('vendor_domain_diagnostic')->error('Vendor domain redirect failed: @m', [
            '@m' => $e->getMessage(),
          ]);
          return;
        }
        if ($request->getUri() === $login_url) {
          return;
        }
        $this->loggerFactory->get('vendor_domain_diagnostic')->debug('Vendor domain redirect', [
          'uid' => $this->currentUser->id(),
          'route' => $route_name,
          'current_domain' => $request->getHost(),
          'target_domain' => (string) parse_url($login_url, PHP_URL_HOST),
          'redirect' => $login_url,
        ]);
        $event->setResponse(new TrustedRedirectResponse($login_url, 302));
        return;
      }

      try {
        $dashboard_url = $this->domainDetector->buildDomainUrl('/vendor/dashboard', 'vendor');
      }
      catch (\Throwable $e) {
        $this->loggerFactory->get('vendor_domain_diagnostic')->error('Vendor domain redirect failed: @m', [
          '@m' => $e->getMessage(),
        ]);
        return;
      }
      if ($request->getUri() === $dashboard_url) {
        return;
      }
      $this->loggerFactory->get('vendor_domain_diagnostic')->debug('Vendor domain redirect', [
        'uid' => $this->currentUser->id(),
        'route' => $route_name,
        'current_domain' => $request->getHost(),
        'target_domain' => (string) parse_url($dashboard_url, PHP_URL_HOST),
        'redirect' => $dashboard_url,
      ]);
      $event->setResponse(new TrustedRedirectResponse($dashboard_url, 302));
      return;
    }

    // Check if redirects are enabled for other redirects.
    $config = $this->configFactory->get('myeventlane_core.domain_settings');
    if (!$config->get('force_redirects')) {
      return;
    }

    // Allow form action paths on both domains (Drupal form submission tokens).
    // These can appear as /form_action_... or /vendor/form_action_... depending
    // on context.
    if (str_starts_with($path, '/form_action_') || str_contains($path, '/form_action_')) {
      return;
    }

    // Use path-based matching first (works before route matching).
    $is_vendor_path = str_starts_with($path, '/vendor/');
    $is_public_path = $this->isPublicPath($path);

    // If we have a route name, use it for more precise matching.
    if ($route_name) {
      // Skip admin routes - they use Gin theme regardless of domain.
      if ($this->isAdminRoute($route_name)) {
        return;
      }

      // Check if route is allowed on both domains - skip redirect.
      if ($this->isAllowedOnBoth($route_name)) {
        return;
      }

      $is_vendor_route = $this->isVendorRoute($route_name);
      $is_public_route = $this->isPublicRoute($route_name);
    }
    else {
      // Fallback to path-based matching when route name not available.
      // Skip root path and paths that might be allowed on both (like
      // /user/login).
      if ($path === '/' || $path === '' || str_starts_with($path, '/user/')) {
        return;
      }

      $is_vendor_route = $is_vendor_path;
      $is_public_route = $is_public_path;
    }

    // Redirect vendor routes from public domain to vendor domain.
    if ($is_vendor_route && !$is_vendor_domain) {
      // Only redirect vendor users; avoid redirecting anonymous/unknown users.
      if (!$this->currentUser->isAuthenticated() || !$this->currentUser->hasPermission('access vendor console')) {
        return;
      }

      try {
        $vendor_url = $this->domainDetector->buildDomainUrl($request->getRequestUri(), 'vendor');
      }
      catch (\Throwable $e) {
        $this->loggerFactory->get('vendor_domain_diagnostic')->error('Vendor domain redirect failed: @m', [
          '@m' => $e->getMessage(),
        ]);
        return;
      }
      $current_domain = $request->getHost();
      $target_domain = (string) parse_url($vendor_url, PHP_URL_HOST);
      if ($target_domain === '' || $current_domain === $target_domain) {
        return;
      }
      if ($request->getUri() === $vendor_url) {
        return;
      }
      $this->loggerFactory->get('vendor_domain_diagnostic')->debug('Vendor domain redirect', [
        'uid' => $this->currentUser->id(),
        'route' => $route_name,
        'current_domain' => $current_domain,
        'target_domain' => $target_domain,
        'redirect' => $vendor_url,
      ]);
      $event->setResponse(new TrustedRedirectResponse($vendor_url, 301));
      return;
    }

    // Redirect public routes from vendor domain to public domain.
    if ($is_public_route && $is_vendor_domain) {
      // Only redirect vendor users; avoid redirecting anonymous/unknown users.
      if (!$this->currentUser->isAuthenticated() || !$this->currentUser->hasPermission('access vendor console')) {
        return;
      }

      try {
        $public_url = $this->domainDetector->buildDomainUrl($request->getRequestUri(), 'public');
      }
      catch (\Throwable $e) {
        $this->loggerFactory->get('vendor_domain_diagnostic')->error('Vendor domain redirect failed: @m', [
          '@m' => $e->getMessage(),
        ]);
        return;
      }
      $current_domain = $request->getHost();
      $target_domain = (string) parse_url($public_url, PHP_URL_HOST);
      if ($target_domain === '' || $current_domain === $target_domain) {
        return;
      }
      if ($request->getUri() === $public_url) {
        return;
      }
      $this->loggerFactory->get('vendor_domain_diagnostic')->debug('Vendor domain redirect', [
        'uid' => $this->currentUser->id(),
        'route' => $route_name,
        'current_domain' => $current_domain,
        'target_domain' => $target_domain,
        'redirect' => $public_url,
      ]);
      $event->setResponse(new TrustedRedirectResponse($public_url, 301));
      return;
    }

    // Enforce vendor login on vendor domain for vendor routes.
    // Check by path first, then by route name.
    $is_vendor_path_check = str_starts_with($path, '/vendor/');
    if ($is_vendor_domain && ($is_vendor_route || $is_vendor_path_check) && $this->currentUser->isAnonymous()) {
      // Allow login/register pages.
      if (in_array($route_name, ['user.login', 'user.register'], TRUE) || str_starts_with($path, '/user/')) {
        return;
      }

      // Redirect to vendor login with destination parameter.
      try {
        $login_url = $this->domainDetector->buildDomainUrl('/user/login?destination=' . urlencode($path), 'vendor');
      }
      catch (\Throwable $e) {
        $this->loggerFactory->get('vendor_domain_diagnostic')->error('Vendor domain redirect failed: @m', [
          '@m' => $e->getMessage(),
        ]);
        return;
      }
      if ($request->getUri() === $login_url) {
        return;
      }
      $this->loggerFactory->get('vendor_domain_diagnostic')->debug('Vendor domain redirect', [
        'uid' => $this->currentUser->id(),
        'route' => $route_name,
        'current_domain' => $request->getHost(),
        'target_domain' => (string) parse_url($login_url, PHP_URL_HOST),
        'redirect' => $login_url,
      ]);
      $event->setResponse(new TrustedRedirectResponse($login_url, 302));
      return;
    }

    // On vendor domain, force event add form through the vendor gateway to
    // create-and-redirect to the vendor edit journey.
    if ($is_vendor_domain && $route_name === 'entity.node.add_form') {
      try {
        $node_type = $this->routeMatch->getParameter('node_type');
        $bundle = NULL;
        if ($node_type) {
          $bundle = is_string($node_type) ? $node_type : $node_type->id();
        }
        if ($bundle === 'event') {
          // Redirect directly to wizard (gateway will handle auth/onboarding if
          // needed).
          $wizard_url = Url::fromRoute('myeventlane_event.wizard.create')->toString();
          $event->setResponse(new TrustedRedirectResponse($wizard_url, 302));
          return;
        }
      }
      catch (\Exception $e) {
        // If parameter resolution fails, do nothing.
      }
    }

    // On vendor domain, redirect event edit forms to the wizard.
    // This ensures vendors never see the default Drupal node edit form.
    if ($is_vendor_domain && $route_name === 'entity.node.edit_form') {
      try {
        $node = $this->routeMatch->getParameter('node');
        if ($node && method_exists($node, 'bundle') && $node->bundle() === 'event') {
          // Check if user owns the event or is admin.
          if ($this->currentUser->hasPermission('administer nodes') ||
              (int) $node->getOwnerId() === (int) $this->currentUser->id()) {
            $wizard_url = Url::fromRoute('myeventlane_event.wizard.edit', ['node' => $node->id()])->toString();
            $event->setResponse(new TrustedRedirectResponse($wizard_url, 302));
            return;
          }
        }
      }
      catch (\Exception $e) {
        // If parameter resolution fails, do nothing.
      }
    }
  }

  /**
   * Checks if a route is a vendor route.
   *
   * @param string $route_name
   *   The route name.
   *
   * @return bool
   *   TRUE if vendor route, FALSE otherwise.
   */
  private function isVendorRoute(string $route_name): bool {
    foreach (self::VENDOR_ROUTE_PATTERNS as $pattern) {
      if (str_starts_with($route_name, $pattern)) {
        return TRUE;
      }
    }

    // Special case: node add/edit forms for events.
    if (in_array($route_name, ['entity.node.add_form', 'entity.node.edit_form'], TRUE)) {
      try {
        $node_type = $this->routeMatch->getParameter('node_type');
        if ($node_type) {
          $bundle = is_string($node_type) ? $node_type : $node_type->id();
          if ($bundle === 'event') {
            return TRUE;
          }
        }
        // Also check node parameter for edit forms.
        $node = $this->routeMatch->getParameter('node');
        if ($node && method_exists($node, 'bundle') && $node->bundle() === 'event') {
          return TRUE;
        }
      }
      catch (\Exception $e) {
        // If parameter access fails, skip this check.
      }
    }

    return FALSE;
  }

  /**
   * Checks if a route is a public route.
   *
   * @param string $route_name
   *   The route name.
   *
   * @return bool
   *   TRUE if public route, FALSE otherwise.
   */
  private function isPublicRoute(string $route_name): bool {
    foreach (self::PUBLIC_ROUTE_PATTERNS as $pattern) {
      if (str_contains($route_name, $pattern)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks if a route is allowed on both domains.
   *
   * @param string $route_name
   *   The route name.
   *
   * @return bool
   *   TRUE if allowed on both, FALSE otherwise.
   */
  private function isAllowedOnBoth(string $route_name): bool {
    return in_array($route_name, self::ALLOWED_ON_BOTH, TRUE);
  }

  /**
   * Checks if a route is an admin route.
   *
   * @param string $route_name
   *   The route name.
   *
   * @return bool
   *   TRUE if admin route, FALSE otherwise.
   */
  private function isAdminRoute(string $route_name): bool {
    return str_starts_with($route_name, 'system.') ||
           (str_starts_with($route_name, 'entity.') && str_contains($route_name, '.collection')) ||
           str_starts_with($this->routeMatch->getRouteObject()?->getPath() ?? '', '/admin');
  }

  /**
   * Checks if a path is a public path.
   *
   * @param string $path
   *   The path (e.g., '/events').
   *
   * @return bool
   *   TRUE if public path, FALSE otherwise.
   */
  private function isPublicPath(string $path): bool {
    // Public paths that should not be on vendor domain.
    $public_paths = [
      '/events',
      '/categories',
      '/vendors',
      '/organisers',
      '/my-events',
      '/user/login',
      '/user/register',
      '/cart',
      '/checkout',
    ];

    foreach ($public_paths as $public_path) {
      if ($path === $public_path || str_starts_with($path, $public_path . '/')) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
