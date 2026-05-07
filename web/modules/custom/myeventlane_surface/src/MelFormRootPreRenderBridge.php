<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Core\Security\TrustedCallbackInterface;

/**
 * Bridges Drupal Render API pre_render to injected services (element_info_alter).
 */
final class MelFormRootPreRenderBridge implements TrustedCallbackInterface {

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['preRender'];
  }

  /**
   * @param array<string, mixed> $element
   *
   * @return array<string, mixed>
   */
  public static function preRender(array $element): array {
    if (!\Drupal::hasService('myeventlane_surface.form_preprocess')) {
      return $element;
    }
    /** @var \Drupal\myeventlane_surface\MelFormPreprocess $preprocess */
    $preprocess = \Drupal::service('myeventlane_surface.form_preprocess');
    return $preprocess->alterRenderElementForm($element);
  }

}
