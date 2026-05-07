<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Core\Template\Attribute;

/**
 * Notification timing/stacking contracts — presentation only.
 */
final class MelNotificationHelper {

  public function __construct(
    private readonly MelInteractionAccessibilityHelper $accessibilityHelper,
  ) {}

  /**
   * @param array<string, mixed> $variables
   */
  public function primeToastVariables(array &$variables): void {
    $variables += [
      'variant' => 'info',
      'dismissible' => TRUE,
      'duration_ms' => 8000,
      'stack_priority' => 0,
      'title' => '',
      'content' => '',
      'actions' => '',
    ];
  }

  /**
   * @param array<string, mixed> $variables
   */
  public function applyToastChrome(array &$variables): void {
    $this->primeToastVariables($variables);
    $variables += ['attributes' => new Attribute()];
    if (!$variables['attributes'] instanceof Attribute) {
      $variables['attributes'] = new Attribute($variables['attributes'] ?? []);
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->addClass('mel-toast');
    $attributes->addClass('mel-interaction-toast');
    $attributes->setAttribute('data-mel-notification', 'toast');
    $attributes->setAttribute('data-mel-toast-duration', (string) (int) $variables['duration_ms']);
    $attributes->setAttribute('data-mel-toast-priority', (string) (int) $variables['stack_priority']);
    $variant = (string) $variables['variant'];
    $attributes->addClass('mel-toast--' . $variant);
    $this->accessibilityHelper->applyToastSemantics($variables, $variant);
  }

  /**
   * @param array<string, mixed> $variables
   */
  public function applyBannerChrome(array &$variables, string $banner_kind): void {
    $variables += ['attributes' => new Attribute()];
    if (!$variables['attributes'] instanceof Attribute) {
      $variables['attributes'] = new Attribute($variables['attributes'] ?? []);
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->addClass('mel-banner');
    $attributes->addClass('mel-interaction-banner');
    $attributes->addClass('mel-banner--' . $banner_kind);
    $attributes->setAttribute('data-mel-notification', 'banner');
    $attributes->setAttribute('data-mel-banner-kind', $banner_kind);
    $this->accessibilityHelper->applyBannerSemantics($variables, $banner_kind);
  }

}
