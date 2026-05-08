<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelSurfaceId;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Surface-aware interaction density and contract visibility for MELInteractionSystem.
 */
final class MelInteractionManager {

  public function __construct(
    private readonly SurfaceResolver $surfaceResolver,
    private readonly RouteMatchInterface $routeMatch,
    private readonly RequestStack $requestStack,
    private readonly MelInteractionRegistry $interactionRegistry,
    private readonly MelFocusManager $focusManager,
  ) {}

  /**
   * Page-level interaction context for Twig shells and behaviours.
   *
   * @return array<string, mixed>
   */
  public function getPageContext(): array {
    $surface = $this->surfaceResolver->resolve();
    $route_name = $this->routeMatch->getRouteName() ?? '';
    $path = $this->requestStack->getCurrentRequest()?->getPathInfo() ?? '';
    $checkout = $this->isCheckoutTrustContext($route_name, $path);

    $profile = match (TRUE) {
      $checkout => 'checkout_trust',
      $surface === MelSurfaceId::Auth => 'auth_minimal',
      $surface === MelSurfaceId::Vendor => 'vendor_operational',
      $surface === MelSurfaceId::Staff => 'staff_operational',
      $surface === MelSurfaceId::Customer => 'customer_guided',
      default => 'customer_guided',
    };

    return [
      'surface' => $surface->value,
      'profile' => $profile,
      'checkout_trust_flow' => $checkout,
      'overlay_density' => $this->overlayDensity($surface, $checkout),
      'toast_stack_limit' => $this->toastStackLimit($surface, $checkout),
      'minimize_modal_interruptions' => $checkout,
      'contracts_documented' => array_keys($this->interactionRegistry->all()),
      'focus' => [
        'modal' => $this->focusManager->modalFocusHints('mel-modal-dialog'),
        'notification_steal_focus_default' => FALSE,
      ],
    ];
  }

  /**
   * @return array<string, \Drupal\myeventlane_surface\InteractionDefinition>
   */
  public function definitions(): array {
    return $this->interactionRegistry->all();
  }

  /**
   * @param array<string, mixed> $build
   */
  public function applyCacheMetadata(array &$build): void {
    $meta = BubbleableMetadata::createFromRenderArray($build);
    $meta->addCacheContexts(['route', 'user.permissions']);
    $meta->applyTo($build);
  }

  private function isCheckoutTrustContext(string $route_name, string $path): bool {
    if (str_starts_with($path, '/cart') || str_starts_with($path, '/checkout')) {
      return TRUE;
    }
    if (str_starts_with($route_name, 'commerce_checkout.')) {
      return TRUE;
    }
    if (str_contains($route_name, 'commerce_payment')) {
      return TRUE;
    }
    return FALSE;
  }

  private function overlayDensity(MelSurfaceId $surface, bool $checkout): string {
    if ($checkout) {
      return 'low';
    }
    return match ($surface) {
      MelSurfaceId::Vendor => 'high',
      MelSurfaceId::Auth => 'low',
      default => 'medium',
    };
  }

  private function toastStackLimit(MelSurfaceId $surface, bool $checkout): int {
    if ($checkout) {
      return 1;
    }
    return match ($surface) {
      MelSurfaceId::Vendor => 4,
      MelSurfaceId::Auth => 2,
      default => 3,
    };
  }

}
