<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\myeventlane_core\MelSurfaceId;

use Drupal\Core\Render\BubbleableMetadata;

/**
 * Surface-aware component behaviour (density, modifiers) for MELComponentSystem.
 */
final class MelComponentManager {

  public function __construct(
    private readonly SurfaceResolver $surfaceResolver,
    private readonly MelComponentRegistry $componentRegistry,
  ) {}

  /**
   * Exposes stable page-level component context for Twig shells.
   *
   * @return array<string, mixed>
   */
  public function getPageContext(): array {
    $surface = $this->surfaceResolver->resolve();
    return [
      'surface' => $surface->value,
      'density' => $this->getDensity($surface),
      'card_modifier_classes' => $this->getCardModifierClasses($surface),
      'dashboard_section_classes' => $this->getDashboardSectionClasses($surface),
    ];
  }

  public function getDensity(MelSurfaceId $surface): string {
    return match ($surface) {
      MelSurfaceId::Vendor => 'dense',
      MelSurfaceId::Public => 'comfortable',
      MelSurfaceId::Auth => 'compact',
      MelSurfaceId::Customer => 'comfortable',
      MelSurfaceId::Staff => 'compact',
    };
  }

  /**
   * @return list<string>
   */
  public function getCardModifierClasses(MelSurfaceId $surface): array {
    $classes = ['mel-card--governed'];
    return match ($surface) {
      MelSurfaceId::Vendor => array_merge($classes, ['mel-card--compact']),
      MelSurfaceId::Auth => array_merge($classes, ['mel-card--compact', 'mel-card--bordered']),
      MelSurfaceId::Customer => array_merge($classes, ['mel-card--surface']),
      MelSurfaceId::Public => array_merge($classes, ['mel-card--hover']),
      MelSurfaceId::Staff => $classes,
    };
  }

  /**
   * @return list<string>
   */
  public function getDashboardSectionClasses(MelSurfaceId $surface): array {
    $base = ['mel-dashboard-section', 'mel-component'];
    return match ($surface) {
      MelSurfaceId::Vendor => array_merge($base, ['mel-dashboard-section--dense']),
      default => array_merge($base, ['mel-dashboard-section--comfortable']),
    };
  }

  /**
   * @return array<string, \Drupal\myeventlane_surface\ComponentDefinition>
   */
  public function definitions(): array {
    return $this->componentRegistry->all();
  }

  /**
   * Merges cache metadata required for surface-sensitive components.
   *
   * @param array<string, mixed> $build
   */
  public function applyCacheMetadata(array &$build): void {
    $meta = BubbleableMetadata::createFromRenderArray($build);
    $meta->addCacheContexts(['route', 'user.permissions']);
    $meta->applyTo($build);
  }

}
