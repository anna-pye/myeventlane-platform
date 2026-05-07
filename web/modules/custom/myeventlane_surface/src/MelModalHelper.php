<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Core\Template\Attribute;

/**
 * Modal shell contracts — markup attributes only; Drupal AJAX owns transport.
 */
final class MelModalHelper {

  public function __construct(
    private readonly MelInteractionRegistry $interactionRegistry,
    private readonly MelInteractionAccessibilityHelper $accessibilityHelper,
  ) {}

  /**
   * @param array<string, mixed> $variables
   */
  public function primeModalVariables(array &$variables): void {
    $variables += [
      'contract_id' => '',
      'title_id' => '',
      'description_id' => '',
      'overlay_attributes' => new Attribute(),
      'dialog_attributes' => new Attribute(),
      'initially_open' => FALSE,
      'fullscreen_mobile' => TRUE,
    ];
  }

  /**
   * Applies governed modal chrome classes and data-contract hints.
   *
   * @param array<string, mixed> $variables
   */
  public function applyModalShell(array &$variables): void {
    $this->primeModalVariables($variables);
    $contract_id = (string) $variables['contract_id'];
    if ($contract_id !== '' && $this->interactionRegistry->get($contract_id) === NULL) {
      $contract_id = '';
      $variables['contract_id'] = '';
    }

    if (!$variables['overlay_attributes'] instanceof Attribute) {
      $variables['overlay_attributes'] = new Attribute($variables['overlay_attributes'] ?? []);
    }
    if (!$variables['dialog_attributes'] instanceof Attribute) {
      $variables['dialog_attributes'] = new Attribute($variables['dialog_attributes'] ?? []);
    }

    /** @var \Drupal\Core\Template\Attribute $overlay */
    $overlay = $variables['overlay_attributes'];
    /** @var \Drupal\Core\Template\Attribute $dialog */
    $dialog = $variables['dialog_attributes'];

    $overlay->addClass('mel-modal__overlay');
    $overlay->setAttribute('data-mel-modal-overlay', 'true');

    $dialog->addClass('mel-modal__dialog');
    $dialog->setAttribute('data-mel-modal-dialog', 'true');
    if ($contract_id !== '') {
      $dialog->setAttribute('data-mel-interaction-contract', $contract_id);
    }

    $this->accessibilityHelper->applyModalDialogSemantics($variables);
  }

}
