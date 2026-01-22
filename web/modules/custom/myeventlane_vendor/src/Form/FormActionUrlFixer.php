<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Form;

use Drupal\Core\Security\TrustedCallbackInterface;

/**
 * Trusted callback for fixing form action URLs on vendor domain.
 */
class FormActionUrlFixer implements TrustedCallbackInterface {

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['fixFormActionUrl'];
  }

  /**
   * Pre-render callback to fix form action URLs.
   *
   * @param array $element
   *   The form element.
   *
   * @return array
   *   The modified form element.
   */
  public static function fixFormActionUrl(array $element): array {
    // Fix form action URL if it contains /vendor/form_action_.
    // Drupal generates form action URLs based on current request path.
    // On /vendor/settings, it generates /vendor/form_action_... which causes 404s.
    // We need to strip the /vendor/ prefix.
    if (isset($element['#action']) && is_string($element['#action'])) {
      // Handle both /vendor/form_action_ and form_action_ with /vendor/ prefix.
      if (str_contains($element['#action'], '/vendor/form_action_')) {
        $element['#action'] = str_replace('/vendor/form_action_', '/form_action_', $element['#action']);
      }
      // Also handle case where action might be a full URL.
      elseif (str_contains($element['#action'], 'vendor.myeventlane.ddev.site/vendor/form_action_')) {
        $element['#action'] = str_replace('/vendor/form_action_', '/form_action_', $element['#action']);
      }
    }

    // Also check for form action in form attributes if present.
    if (isset($element['#attributes']['action']) && is_string($element['#attributes']['action'])) {
      if (str_contains($element['#attributes']['action'], '/vendor/form_action_')) {
        $element['#attributes']['action'] = str_replace('/vendor/form_action_', '/form_action_', $element['#attributes']['action']);
      }
    }

    return $element;
  }

}
