<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Core\Template\Attribute;

/**
 * WCAG 2.1 AA semantics for MELInteractionSystem (additive to components/forms).
 */
final class MelInteractionAccessibilityHelper {

  /**
   * @param array<string, mixed> $variables
   */
  public function applyModalDialogSemantics(array &$variables): void {
    $title_id = (string) $variables['title_id'];
    $description_id = (string) $variables['description_id'];
    if (!isset($variables['dialog_attributes']) || !$variables['dialog_attributes'] instanceof Attribute) {
      return;
    }
    /** @var \Drupal\Core\Template\Attribute $dialog */
    $dialog = $variables['dialog_attributes'];
    $dialog->setAttribute('role', 'dialog');
    $dialog->setAttribute('aria-modal', 'true');
    if ($title_id !== '') {
      $dialog->setAttribute('aria-labelledby', $title_id);
    }
    if ($description_id !== '') {
      $dialog->setAttribute('aria-describedby', $description_id);
    }
  }

  /**
   * @param array<string, mixed> $variables
   */
  public function applyDrawerSemantics(array &$variables): void {
    if (!isset($variables['panel_attributes']) || !$variables['panel_attributes'] instanceof Attribute) {
      return;
    }
    /** @var \Drupal\Core\Template\Attribute $panel */
    $panel = $variables['panel_attributes'];
    $panel->setAttribute('role', 'dialog');
    $panel->setAttribute('aria-modal', 'true');
    $label_id = (string) ($variables['label_id'] ?? '');
    if ($label_id !== '') {
      $panel->setAttribute('aria-labelledby', $label_id);
    }
  }

  /**
   * @param array<string, mixed> $variables
   */
  public function applyToastSemantics(array &$variables, string $variant): void {
    if (!isset($variables['attributes']) || !$variables['attributes'] instanceof Attribute) {
      return;
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    if ($variant === 'error') {
      $attributes->setAttribute('role', 'alert');
      $attributes->setAttribute('aria-live', 'assertive');
    }
    else {
      $attributes->setAttribute('role', 'status');
      $attributes->setAttribute('aria-live', 'polite');
    }
  }

  /**
   * @param array<string, mixed> $variables
   */
  public function applyBannerSemantics(array &$variables, string $kind): void {
    if (!isset($variables['attributes']) || !$variables['attributes'] instanceof Attribute) {
      return;
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $live = match ($kind) {
      'error' => 'assertive',
      'warning', 'confirmation', 'processing' => 'polite',
      default => 'polite',
    };
    $attributes->setAttribute('role', $kind === 'error' ? 'alert' : 'status');
    $attributes->setAttribute('aria-live', $live);
  }

  /**
   * @param array<string, mixed> $variables
   */
  public function applyBusyRegionSemantics(array &$variables): void {
    if (!isset($variables['attributes']) || !$variables['attributes'] instanceof Attribute) {
      return;
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->setAttribute('role', 'status');
    $attributes->setAttribute('aria-live', 'polite');
    $attributes->setAttribute('aria-busy', 'true');
  }

  /**
   * @param array<string, mixed> $variables
   */
  public function applyRetryRegionSemantics(array &$variables): void {
    if (!isset($variables['attributes']) || !$variables['attributes'] instanceof Attribute) {
      return;
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->setAttribute('role', 'alert');
    $attributes->setAttribute('aria-live', 'assertive');
    $attributes->setAttribute('aria-busy', 'false');
  }

  /**
   * @param array<string, mixed> $variables
   */
  public function applySkeletonSemantics(array &$variables): void {
    if (!isset($variables['attributes']) || !$variables['attributes'] instanceof Attribute) {
      return;
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->setAttribute('role', 'status');
    $attributes->setAttribute('aria-live', 'polite');
    $attributes->setAttribute('aria-busy', 'true');
    $attributes->setAttribute('aria-label', (string) ($variables['message'] ?? ''));
  }

  /**
   * @param array<string, mixed> $variables
   */
  public function applyDisclosureSemantics(array &$variables, string $hook): void {
    if (!isset($variables['attributes']) || !$variables['attributes'] instanceof Attribute) {
      return;
    }
    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $expanded = !empty($variables['expanded']);
    $attributes->setAttribute('data-mel-disclosure-expanded', $expanded ? 'true' : 'false');

    $toggle_id = (string) ($variables['toggle_id'] ?? '');
    $panel_id = (string) ($variables['panel_id'] ?? '');
    if ($toggle_id !== '' && $panel_id !== '') {
      $attributes->setAttribute('data-mel-disclosure-toggle', $toggle_id);
      $attributes->setAttribute('data-mel-disclosure-panel', $panel_id);
    }
    unset($hook);
  }

}
