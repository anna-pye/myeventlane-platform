<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Core\Template\Attribute;

/**
 * Progressive disclosure contracts — keyboard + aria-expanded hints.
 */
final class MelDisclosureHelper {

  public function __construct(
    private readonly MelInteractionRegistry $interactionRegistry,
    private readonly MelInteractionAccessibilityHelper $accessibilityHelper,
  ) {}

  /**
   * @param array<string, mixed> $variables
   */
  public function primeDisclosureVariables(array &$variables): void {
    $variables += [
      'contract_id' => '',
      'expanded' => FALSE,
      'toggle_label' => '',
      'panel_id' => '',
      'toggle_id' => '',
      'toggle_content' => '',
      'panel_content' => '',
      'toggle_attributes' => new Attribute(),
      'panel_attributes' => new Attribute(),
    ];
  }

  /**
   * @param array<string, mixed> $variables
   */
  public function applyDisclosureChrome(array &$variables, string $hook): void {
    $this->primeDisclosureVariables($variables);
    $contract_id = (string) $variables['contract_id'];
    if ($contract_id !== '' && $this->interactionRegistry->get($contract_id) === NULL) {
      $contract_id = '';
      $variables['contract_id'] = '';
    }

    $variables += ['attributes' => new Attribute()];
    if (!$variables['attributes'] instanceof Attribute) {
      $variables['attributes'] = new Attribute($variables['attributes'] ?? []);
    }
    if (!$variables['toggle_attributes'] instanceof Attribute) {
      $variables['toggle_attributes'] = new Attribute($variables['toggle_attributes'] ?? []);
    }
    if (!$variables['panel_attributes'] instanceof Attribute) {
      $variables['panel_attributes'] = new Attribute($variables['panel_attributes'] ?? []);
    }

    /** @var \Drupal\Core\Template\Attribute $attributes */
    $attributes = $variables['attributes'];
    $attributes->addClass('mel-disclosure');
    $attributes->addClass('mel-disclosure--' . str_replace('_', '-', $hook));
    $attributes->setAttribute('data-mel-disclosure', 'true');
    if ($contract_id !== '') {
      $attributes->setAttribute('data-mel-interaction-contract', $contract_id);
    }

    /** @var \Drupal\Core\Template\Attribute $toggle */
    $toggle = $variables['toggle_attributes'];
    $toggle->addClass('mel-disclosure__toggle');
    $toggle->setAttribute('type', 'button');
    $toggle->setAttribute('aria-expanded', !empty($variables['expanded']) ? 'true' : 'false');

    /** @var \Drupal\Core\Template\Attribute $panel */
    $panel = $variables['panel_attributes'];
    $panel->addClass('mel-disclosure__panel');

    $toggle_id = (string) $variables['toggle_id'];
    $panel_id = (string) $variables['panel_id'];
    if ($toggle_id !== '') {
      $toggle->setAttribute('id', $toggle_id);
    }
    if ($panel_id !== '') {
      $panel->setAttribute('id', $panel_id);
      $toggle->setAttribute('aria-controls', $panel_id);
      $panel->setAttribute('role', 'region');
      if ($toggle_id !== '') {
        $panel->setAttribute('aria-labelledby', $toggle_id);
      }
    }

    $this->accessibilityHelper->applyDisclosureSemantics($variables, $hook);
  }

}
