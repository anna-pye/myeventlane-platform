<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\MelAdminShellBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for Help Centre admin insights dashboard.
 */
final class HelpInsightsController extends ControllerBase {

  public function __construct(
    private readonly MelAdminShellBuilder $adminShellBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_core.mel_admin_shell_builder'),
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
    $build['#cache'] = [
      'contexts' => ['user.permissions'],
      'max-age' => 300,
      'tags' => [
        'config:views.view.mel_help_top_articles',
        'config:views.view.mel_help_zero_results',
        'config:views.view.mel_help_least_helpful',
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

}
