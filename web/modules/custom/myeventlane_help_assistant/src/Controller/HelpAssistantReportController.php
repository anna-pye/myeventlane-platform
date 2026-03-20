<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Administrative report landing page for Help Assistant operations.
 */
final class HelpAssistantReportController extends ControllerBase {

  /**
   * Builds a lightweight report page.
   */
  public function page(): array {
    return [
      '#type' => 'container',
      'intro' => [
        '#markup' => '<p>' . $this->t('Help Assistant usage events are recorded in MEL Help analytics with event types ai_query, ai_success, and ai_low_confidence.') . '</p>',
      ],
      'details' => [
        '#markup' => '<p>' . $this->t('Use Help Insights and Views reporting to analyse retrieval quality, confidence trends, and fallback frequency.') . '</p>',
      ],
    ];
  }

}
