<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Governed progression affordances (checklists, lightweight progress snapshots).
 */
final class MelWorkflowProgressHelper {

  use StringTranslationTrait;

  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Optional checklist panel for tightly scoped hubs (one progression surface).
   *
   * @param list<\Drupal\myeventlane_surface\WorkflowDefinition> $definitions
   * @return array<string, mixed>
   */
  public function buildDashboardChecklist(array $definitions, MelWorkflowContext $context): array {
    if (!$context->account->isAuthenticated()) {
      return [];
    }
    if ($context->routeName !== 'myeventlane_account.dashboard') {
      return [];
    }

    $lines = [];
    foreach ($definitions as $definition) {
      $lines[] = $this->t('Keep progressing on @workflow', [
        '@workflow' => ucfirst(str_replace('_', ' ', $definition->id)),
      ]);
    }

    if ($lines === []) {
      return [];
    }

    return [
      '#theme' => 'mel_checklist_panel',
      '#content' => [
        '#theme' => 'item_list',
        '#items' => $lines,
      ],
      '#attributes' => [],
    ];
  }

}
