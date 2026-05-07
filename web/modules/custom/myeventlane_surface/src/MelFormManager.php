<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Core\Theme\ThemeManagerInterface;

/**
 * Facade for when MELFormSystem chrome applies and which classification is active.
 */
final class MelFormManager {

  /**
   * @var list<string>
   */
  private const GOVERNED_THEMES = [
    'myeventlane_theme',
    'myeventlane_vendor_theme',
  ];

  public function __construct(
    private readonly MelFormClassificationResolver $classificationResolver,
    private readonly SurfaceResolver $surfaceResolver,
    private readonly ThemeManagerInterface $themeManager,
  ) {}

  /**
   * Product chrome applies only on public/vendor storefront themes, never Gin/admin.
   */
  public function shouldGovernForms(): bool {
    if ($this->surfaceResolver->resolve() === MelSurfaceId::Staff) {
      return FALSE;
    }

    $name = $this->themeManager->getActiveTheme()->getName();
    return in_array($name, self::GOVERNED_THEMES, TRUE);
  }

  public function getClassification(): MelFormClassification {
    return $this->classificationResolver->resolve();
  }

}
