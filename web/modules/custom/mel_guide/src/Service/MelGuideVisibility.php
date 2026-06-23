<?php

declare(strict_types=1);

namespace Drupal\mel_guide\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\MelSurfaceId;
use Drupal\myeventlane_surface\MelFormClassification;
use Drupal\myeventlane_surface\MelFormClassificationResolver;
use Drupal\myeventlane_surface\SurfaceResolver;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Determines whether the MEL Guide should render on the current request.
 */
final class MelGuideVisibility {

  /**
   * Drupal roles treated as organisers for guide targeting.
   *
   * @var list<string>
   */
  private const ORGANISER_ROLES = [
    'vendor',
    'mel_pro',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly SurfaceResolver $surfaceResolver,
    private readonly MelFormClassificationResolver $formClassificationResolver,
    private readonly RouteMatchInterface $routeMatch,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Whether the guide may attach on this request.
   */
  public function shouldAttach(): bool {
    $config = $this->configFactory->get('mel_guide.settings');

    if (!$config->get('enabled')) {
      return FALSE;
    }

    if ($config->get('debug_force_display')) {
      return $this->passesDeviceGate() && $this->passesAudienceGate();
    }

    if (!$this->passesDeviceGate()) {
      return FALSE;
    }

    if (!$this->passesAudienceGate()) {
      return FALSE;
    }

    if ($this->isExcludedRoute()) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Whether the current device class is enabled in configuration.
   */
  public function passesDeviceGate(): bool {
    $config = $this->configFactory->get('mel_guide.settings');
    $is_mobile = $this->isMobileRequest();

    if ($is_mobile && !$config->get('enabled_mobile')) {
      return FALSE;
    }

    if (!$is_mobile && !$config->get('enabled_desktop')) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Device class used for visibility and cache variation.
   */
  public function getDeviceClass(): string {
    return $this->isMobileRequest() ? 'mobile' : 'desktop';
  }

  /**
   * Whether the current user matches audience targeting rules.
   */
  public function passesAudienceGate(): bool {
    $config = $this->configFactory->get('mel_guide.settings');
    $account = $this->currentUser;

    if ($account->isAnonymous()) {
      return (bool) $config->get('show_for_anonymous');
    }

    if (!$config->get('show_for_authenticated')) {
      return FALSE;
    }

    $roles = $account->getRoles(TRUE);
    $is_organiser = array_intersect(self::ORGANISER_ROLES, $roles) !== [];

    if ($is_organiser) {
      return (bool) $config->get('show_for_organisers');
    }

    return (bool) $config->get('show_for_customers');
  }

  /**
   * Whether the active route is excluded from guide display.
   */
  private function isExcludedRoute(): bool {
    $surface = $this->surfaceResolver->resolve();
    if ($surface === MelSurfaceId::Staff || $surface === MelSurfaceId::Vendor) {
      return TRUE;
    }

    if ($surface === MelSurfaceId::Auth) {
      return TRUE;
    }

    $route_name = $this->routeMatch->getRouteName() ?? '';
    $path = $this->requestStack->getCurrentRequest()?->getPathInfo() ?? '';

    if (str_starts_with($path, '/admin') || str_starts_with($path, '/vendor')) {
      return TRUE;
    }

    if (str_starts_with($path, '/dashboard')) {
      return TRUE;
    }

    if (str_starts_with($path, '/onboard') || str_starts_with($path, '/vendor/onboard')) {
      return TRUE;
    }

    $blocked_route_prefixes = [
      'myeventlane_event_studio.',
      'myeventlane_vendor.',
      'myeventlane_help_assistant.',
    ];
    foreach ($blocked_route_prefixes as $prefix) {
      if (str_starts_with($route_name, $prefix)) {
        return TRUE;
      }
    }

    $classification = $this->formClassificationResolver->resolve();
    if ($classification === MelFormClassification::Checkout) {
      return !$this->isCheckoutCompleteStep();
    }

    if ($classification === MelFormClassification::Support) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Whether the active Commerce checkout step is the completion step.
   */
  public function isCheckoutCompleteStep(): bool {
    if ($this->routeMatch->getRouteName() !== 'commerce_checkout.form') {
      return FALSE;
    }

    $step = $this->routeMatch->getParameter('step');
    return is_string($step) && $step === 'complete';
  }

  /**
   * Lightweight mobile detection for server-side device gating.
   */
  private function isMobileRequest(): bool {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return FALSE;
    }

    $ua = $request->headers->get('User-Agent', '');
    if ($ua === '') {
      return FALSE;
    }

    return (bool) preg_match('/android|iphone|ipod|mobile|webos|blackberry|iemobile|opera mini/i', $ua);
  }

}
