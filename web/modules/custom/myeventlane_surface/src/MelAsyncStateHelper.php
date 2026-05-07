<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Core\Template\Attribute;

/**
 * Async UX contracts — aria-busy, skeleton hints; AJAX handlers remain authoritative.
 */
final class MelAsyncStateHelper {

  public function __construct(
    private readonly MelInteractionAccessibilityHelper $accessibilityHelper,
  ) {}

  /**
   * @param array<string, mixed> $variables
   */
  public function applyAsyncChrome(array &$variables, string $kind): void {
    $variables += ['attributes' => new Attribute(), 'message' => '', 'retry_url' => NULL];
    if (!$variables['attributes'] instanceof Attribute) {
      $variables['attributes'] = new Attribute($variables['attributes'] ?? []);
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->addClass('mel-async-state');
    $attributes->addClass('mel-async-state--' . $kind);
    $attributes->setAttribute('data-mel-async-state', $kind);
    $attributes->setAttribute('data-mel-interaction-contract', 'async_' . $kind);

    match ($kind) {
      'loading', 'saving', 'processing', 'queued' => $this->accessibilityHelper->applyBusyRegionSemantics($variables),
      'retry' => $this->accessibilityHelper->applyRetryRegionSemantics($variables),
      'empty_loading' => $this->accessibilityHelper->applySkeletonSemantics($variables),
      default => NULL,
    };
  }

}
