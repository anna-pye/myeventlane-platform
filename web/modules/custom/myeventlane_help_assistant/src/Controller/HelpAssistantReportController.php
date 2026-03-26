<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_assistant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Administrative report landing page for Help Assistant operations.
 */
final class HelpAssistantReportController extends ControllerBase {

  /**
   * Builds a lightweight report page.
   */
  public function page(): array {
    $items = [];
    if ($this->moduleHandler()->moduleExists('myeventlane_help_improvement') && $this->currentUser()->hasPermission('view documentation opportunities')) {
      $items[] = Link::fromTextAndUrl(
        $this->t('Open documentation opportunities queue'),
        Url::fromRoute('myeventlane_help_improvement.opportunities'),
      )->toString();
    }

    return [
      '#type' => 'container',
      'intro' => [
        '#markup' => '<p>' . $this->t('Help Assistant usage events are recorded in MEL Help analytics with event types ai_query, ai_success, and ai_low_confidence.') . '</p>',
      ],
      'details' => [
        '#markup' => '<p>' . $this->t('Use Help Insights and Views reporting to analyse retrieval quality, confidence trends, and fallback frequency.') . '</p>',
      ],
      'queue' => $items !== [] ? [
        '#theme' => 'item_list',
        '#title' => $this->t('Improvement loop'),
        '#items' => $items,
        '#cache' => ['contexts' => ['user.permissions']],
      ] : [],
      '#cache' => [
        'contexts' => ['user.permissions'],
      ],
    ];
  }

}
