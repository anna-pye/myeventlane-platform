<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Controller for Help Centre admin insights dashboard.
 */
final class HelpInsightsController extends ControllerBase {

  /**
   * Builds the Help Insights dashboard.
   */
  public function dashboard(): array {
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-help-insights-dashboard'],
      ],
      'top_articles_heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Top Articles'),
      ],
      'top_articles' => views_embed_view('mel_help_top_articles', 'block_1'),
      'search_heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Search Insights'),
      ],
      'top_searches' => views_embed_view('mel_help_top_searches', 'block_1'),
      'zero_results' => views_embed_view('mel_help_zero_results', 'block_1'),
      'content_issues_heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Content Issues'),
      ],
      'least_helpful' => views_embed_view('mel_help_least_helpful', 'block_1'),
      'most_helpful' => views_embed_view('mel_help_most_helpful', 'block_1'),
      '#cache' => [
        'contexts' => ['user.permissions'],
      ],
    ];
  }

}
