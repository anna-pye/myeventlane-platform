<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\MelAdminShellBuilder;
use Drupal\myeventlane_help_centre\Service\HelpContentRepository;
use Drupal\myeventlane_help_centre\Service\HelpPanelIntelligence;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for Help Centre admin insights dashboard.
 */
final class HelpInsightsController extends ControllerBase {

  public function __construct(
    private readonly MelAdminShellBuilder $adminShellBuilder,
    private readonly HelpPanelIntelligence $panelIntelligence,
    private readonly HelpContentRepository $helpContentRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.mel_admin_shell_builder'),
      $container->get('myeventlane_help.intelligence'),
      $container->get('myeventlane_help_centre.help_content_repository'),
    );
  }

  /**
   * Builds the Help Insights dashboard.
   */
  public function dashboard(): array {
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-help-insights-dashboard'],
      ],
    ];

    if ($this->moduleHandler()->moduleExists('myeventlane_help_improvement') && $this->currentUser()->hasPermission('view documentation opportunities')) {
      $build['improvement_nav'] = $this->buildImprovementNav();
    }

    $build['top_articles_heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Top Articles'),
    ];
    $build['top_articles'] = views_embed_view('mel_help_top_articles', 'block_1');
    $build['search_heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Search Insights'),
    ];
    $build['top_searches'] = views_embed_view('mel_help_top_searches', 'block_1');
    $build['zero_results'] = views_embed_view('mel_help_zero_results', 'block_1');
    $build['content_issues_heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Content Issues'),
    ];
    $build['least_helpful'] = views_embed_view('mel_help_least_helpful', 'block_1');
    $build['most_helpful'] = views_embed_view('mel_help_most_helpful', 'block_1');
    $build['support_panels_heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Support panel performance'),
    ];
    $build['support_panels'] = $this->buildSupportPanelTable();
    $build['#cache'] = [
      'contexts' => ['user.permissions'],
    ];

    return $build;
  }

  /**
   * Help content health: embeds existing analytics views (single admin surface).
   */
  public function contentHealth(): array {
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['mel-help-content-health'],
      ],
    ];

    if ($this->moduleHandler()->moduleExists('myeventlane_help_improvement') && $this->currentUser()->hasPermission('view documentation opportunities')) {
      $build['improvement_nav'] = $this->buildImprovementNav();
    }

    $build['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('These panels reuse the configured Help analytics views so editorial sees a single, consistent picture.'),
      '#attributes' => ['class' => ['mel-help-content-health__intro']],
    ];
    $build['top_heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Top viewed articles'),
    ];
    $build['top_articles'] = views_embed_view('mel_help_top_articles', 'block_1');
    $build['zero_heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Zero-result searches'),
    ];
    $build['zero_results'] = views_embed_view('mel_help_zero_results', 'block_1');
    $build['least_heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Least helpful articles'),
    ];
    $build['least_helpful'] = views_embed_view('mel_help_least_helpful', 'block_1');
    $build['support_panels_heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Support panel performance'),
    ];
    $build['support_panels'] = $this->buildSupportPanelTable();
    $build['#cache'] = [
      'contexts' => ['user.permissions'],
      'max-age' => 300,
      'tags' => [
        'config:views.view.mel_help_top_articles',
        'config:views.view.mel_help_zero_results',
        'config:views.view.mel_help_least_helpful',
        'config:myeventlane_help_centre.help_content',
      ],
    ];

    return $this->adminShellBuilder->wrapStandard(
      $build,
      $this->t('Help content health'),
      $this->t('Editorial analytics from existing Help views.'),
    );
  }

  /**
   * @return array<string, mixed>
   */
  private function buildImprovementNav(): array {
    $items = [];
    if ($this->moduleHandler()->moduleExists('myeventlane_help_improvement')) {
      $items[] = Link::fromTextAndUrl(
        $this->t('Documentation opportunities queue'),
        Url::fromRoute('myeventlane_help_improvement.opportunities'),
      )->toString();
    }
    $items[] = Link::fromTextAndUrl(
      $this->t('Help content health'),
      Url::fromRoute('myeventlane_help_centre.docs_health'),
    )->toString();
    $items[] = Link::fromTextAndUrl(
      $this->t('MEL Help Insights'),
      Url::fromRoute('myeventlane_help_centre.help_insights_dashboard'),
    )->toString();
    return [
      '#theme' => 'item_list',
      '#title' => $this->t('Documentation improvement'),
      '#items' => $items,
      '#attributes' => ['class' => ['mel-help-improvement-nav']],
      '#cache' => [
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   *   Table render array for panel analytics.
   */
  private function buildSupportPanelTable(): array {
    $panels = $this->helpContentRepository->getSupportPanels();
    if (!is_array($panels) || $panels === []) {
      return [
        '#markup' => $this->t('No support panels configured.'),
      ];
    }

    $orderedKeys = $this->panelIntelligence->getOrderedPanelKeys($panels);
    if ($orderedKeys === []) {
      $orderedKeys = array_keys($panels);
    }
    $metrics = $this->panelIntelligence->getPanelMetrics($panels);

    $rows = [];
    foreach ($orderedKeys as $key) {
      $panel = $panels[$key] ?? [];
      $panelTitle = trim((string) ($panel['title'] ?? '')) ?: $key;
      $stat = $metrics[$key] ?? [
        'impressions' => 0,
        'clicks' => 0,
        'ctr' => 0,
        'feedback_total' => 0,
        'helpful' => 0,
        'helpful_rate' => 0,
      ];
      $rows[] = [
        $panelTitle,
        $key,
        (int) $stat['impressions'],
        (int) $stat['clicks'],
        sprintf('%.1f%%', ((float) $stat['ctr']) * 100),
        (int) $stat['feedback_total'],
        (int) $stat['helpful'],
        sprintf('%.1f%%', ((float) $stat['helpful_rate']) * 100),
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Panel'),
        $this->t('Key'),
        $this->t('Impressions'),
        $this->t('Clicks'),
        $this->t('CTR'),
        $this->t('Feedback'),
        $this->t('Helpful'),
        $this->t('Helpful rate'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No support panel activity recorded yet.'),
      '#cache' => [
        'contexts' => ['user.permissions'],
        'tags' => ['config:myeventlane_help_centre.help_content'],
        'max-age' => 300,
      ],
    ];
  }

}
