<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_core\Entity\OnboardingStateInterface;
use Drupal\myeventlane_core\Service\OnboardingManager;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves workflow contracts from surface, route, and lightweight signals.
 *
 * Does not replace onboarding entities, Commerce checkout, or Drupal Workflow.
 */
final class MelWorkflowResolver {

  public function __construct(
    private readonly SurfaceResolver $surfaceResolver,
    private readonly RouteMatchInterface $routeMatch,
    private readonly RequestStack $requestStack,
    private readonly AccountInterface $currentUser,
    private readonly SurfaceAccessHelper $accessHelper,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly OnboardingManager $onboardingManager,
    private readonly PathMatcherInterface $pathMatcher,
  ) {}

  /**
   * Builds the behavioural context for the current request.
   */
  public function buildContext(): MelWorkflowContext {
    $surface = $this->surfaceResolver->resolve();
    $route_name = $this->routeMatch->getRouteName() ?? '';
    $path = $this->requestStack->getCurrentRequest()?->getPathInfo() ?? '';
    $checkout_sensitive = $this->accessHelper->isCheckoutSensitiveRequest();

    $signals = $this->buildBaseSignals($surface, $route_name, $path, $checkout_sensitive);
    $vendor_state = $this->appendVendorSignals($surface, $signals);
    $this->appendCustomerSignals($surface, $signals);

    $this->moduleHandler->alter('mel_workflow_signals', $signals, $this->currentUser, $route_name, $path);

    return new MelWorkflowContext(
      surface: $surface,
      routeName: $route_name,
      path: $path,
      account: $this->currentUser,
      checkoutSensitive: $checkout_sensitive,
      signals: $signals,
      vendorOnboardingState: $vendor_state,
    );
  }

  /**
   * Workflows considered active for behavioural orchestration on this request.
   *
   * @return list<\Drupal\myeventlane_surface\WorkflowDefinition>
   */
  public function resolveActiveWorkflows(MelWorkflowRegistry $registry, MelWorkflowContext $context): array {
    $active = [];
    foreach ($registry->all() as $definition) {
      if ($this->isWorkflowActive($definition, $context)) {
        $active[] = $definition;
      }
    }
    return $active;
  }

  /**
   * Whether completion UX should dominate prompts for this workflow.
   */
  public function isCompletionMoment(WorkflowDefinition $definition, MelWorkflowContext $context): bool {
    foreach ($definition->completionExactRoutes as $route) {
      if ($route !== '' && $route === $context->routeName) {
        return TRUE;
      }
    }
    if ($definition->completionSignals === []) {
      return FALSE;
    }
    foreach ($definition->completionSignals as $signal_key) {
      if (empty($context->signals[$signal_key])) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * @param array<string, bool|int|string> $signals
   */
  private function appendVendorSignals(MelSurfaceId $surface, array &$signals): ?object {
    if ($surface !== MelSurfaceId::Vendor || !$this->currentUser->isAuthenticated()) {
      return NULL;
    }

    $state = $this->onboardingManager->loadVendorStateByUid((int) $this->currentUser->id());
    if ($state === NULL) {
      $signals['vendor.guidance.needs_resume'] = TRUE;
      $signals['vendor.surface.active'] = TRUE;
      return NULL;
    }

    $flags = $state->getFlags();
    foreach ([
      'vendor.flags.has_vendor' => !empty($flags['has_vendor']),
      'vendor.flags.has_store' => !empty($flags['has_store']),
      'vendor.flags.has_events' => !empty($flags['has_events']),
      'vendor.flags.has_tickets' => !empty($flags['has_tickets']),
      'vendor.flags.stripe_connected' => !empty($flags['stripe_connected']),
    ] as $key => $value) {
      $signals[$key] = $value;
    }

    $signals['vendor.onboarding.completed'] = $state->isCompleted();
    $signals['vendor.onboarding.invite_ready'] = $this->previewVendorInviteReady($state);
    $signals['vendor.guidance.needs_resume'] = !$state->isCompleted() && !$signals['vendor.onboarding.invite_ready'];
    $signals['vendor.surface.active'] = TRUE;

    return $state;
  }

  /**
   * Customer onboarding resume signal (read-only; no entity creation).
   *
   * @param array<string, bool|int|string> $signals
   */
  private function appendCustomerSignals(MelSurfaceId $surface, array &$signals): void {
    if ($surface !== MelSurfaceId::Customer || !$this->currentUser->isAuthenticated()) {
      return;
    }
    $state = $this->onboardingManager->loadCustomerStateByUid((int) $this->currentUser->id());
    if ($state === NULL) {
      $signals['customer.guidance.needs_resume'] = FALSE;
      return;
    }
    $signals['customer.guidance.needs_resume'] = !$state->isCompleted();
  }

  /**
   * Read-only preview aligned with OnboardingManager::isInviteReady without saving.
   */
  private function previewVendorInviteReady(OnboardingStateInterface $state): bool {
    if ($state->isCompleted()) {
      return TRUE;
    }
    if ($state->getTrack() !== OnboardingStateInterface::TRACK_VENDOR) {
      return FALSE;
    }
    $flags = $state->getFlags();
    $stage = $state->getStage();
    if (in_array($stage, ['probe', 'present'], TRUE)) {
      return FALSE;
    }
    return !empty($flags['has_events'])
      && !empty($flags['has_tickets'])
      && !empty($flags['stripe_connected']);
  }

  /**
   * @return array<string, bool|int|string>
   */
  private function buildBaseSignals(MelSurfaceId $surface, string $route_name, string $path, bool $checkout_sensitive): array {
    $signals = [];
    $signals['route.site_front'] = $this->pathMatcher->isFrontPage();
    $signals['surface.' . $surface->value] = TRUE;
    $signals['route.checkout_sensitive'] = $checkout_sensitive;
    $signals['route.commerce_checkout'] = $route_name !== '' && str_starts_with($route_name, 'commerce_checkout');
    $signals['route.auth_login'] = $route_name === 'user.login';
    $signals['route.auth_register'] = $route_name === 'user.register';
    $signals['route.my_account_dashboard'] = $route_name === 'myeventlane_account.dashboard';
    $signals['route.my_settings'] = $route_name === 'myeventlane_account.settings';
    $signals['route.my_tickets'] = $route_name === 'myeventlane_checkout_flow.my_tickets';
    $signals['route.order_detail'] = $route_name === 'myeventlane_checkout_flow.order_detail';
    $signals['route.rsvp_thankyou'] = $route_name === 'myeventlane_rsvp.thankyou';
    $signals['customer.flags.has_orders'] = FALSE;
    if ($this->currentUser->isAuthenticated()) {
      $narrow_orders_probe = $surface === MelSurfaceId::Customer
        || str_starts_with($path, '/my-')
        || $checkout_sensitive
        || str_starts_with($route_name, 'myeventlane_account.');
      if ($narrow_orders_probe) {
        $signals['customer.flags.has_orders'] = $this->onboardingManager->customerHasCompletedOrders((int) $this->currentUser->id());
      }
    }
    return $signals;
  }

  private function isWorkflowActive(WorkflowDefinition $definition, MelWorkflowContext $context): bool {
    if ($definition->requireAuthentication && !$context->account->isAuthenticated()) {
      return FALSE;
    }

    $surface_ok = in_array($context->surface->value, $definition->surfaceOwnership, TRUE);
    if (!$surface_ok && $definition->domain === 'checkout' && $context->checkoutSensitive) {
      $surface_ok = TRUE;
    }
    if (!$surface_ok) {
      return FALSE;
    }

    foreach ($definition->activationRequireAllSignals as $key) {
      if (empty($context->signals[$key])) {
        return FALSE;
      }
    }

    foreach ($definition->requiredSignals as $key) {
      if (empty($context->signals[$key])) {
        return FALSE;
      }
    }

    $has_route_criteria = $definition->activationExactRoutes !== []
      || $definition->activationRoutePrefixes !== []
      || $definition->activationPathPrefixes !== [];

    if (!$has_route_criteria) {
      return $definition->activationRequireAllSignals !== [];
    }

    if (in_array($context->routeName, $definition->activationExactRoutes, TRUE)) {
      return TRUE;
    }
    foreach ($definition->activationRoutePrefixes as $prefix) {
      if ($context->routeName !== '' && str_starts_with($context->routeName, $prefix)) {
        return TRUE;
      }
    }
    foreach ($definition->activationPathPrefixes as $prefix) {
      if (str_starts_with($context->path, $prefix)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
