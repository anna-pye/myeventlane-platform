<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_help_centre\Service\HelpAnalyticsService;
use Drupal\taxonomy\TermInterface;
use Drupal\views\Views;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for public and vendor Help Centre pages.
 */
final class HelpCentreController extends ControllerBase {

  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeManagerInterface $entityTypeManagerService,
    private readonly RequestStack $requestStack,
    private readonly LoggerInterface $logger,
    private readonly HelpAnalyticsService $analyticsService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_field.manager'),
      $container->get('entity_type.manager'),
      $container->get('request_stack'),
      $container->get('logger.factory')->get('myeventlane_help_centre'),
      $container->get('myeventlane_help_centre.analytics'),
    );
  }

  /**
   * Renders the Help Centre homepage.
   */
  public function publicIndex(): array {
    return $this->homepage();
  }

  /**
   * Renders the Help Centre SaaS-style homepage.
   */
  public function homepage(): array {
    $currentRequest = $this->requestStack->getCurrentRequest();
    $queryValue = $currentRequest?->query->get('q');
    $searchValue = is_string($queryValue) ? trim($queryValue) : '';

    $build = [
      '#theme' => 'help_centre_home',
      '#help_search' => [
        'action' => Url::fromRoute('myeventlane_help_centre.search')->toString(),
        'value' => $searchValue,
        'placeholder' => $this->t('Search help articles...'),
      ],
      '#featured_articles' => $this->buildView('mel_help_featured_articles', 'block_featured'),
      '#help_categories' => $this->loadHelpCategories(),
      '#faq_listing' => $this->buildView('mel_help_faq', 'block_faq'),
    ];

    $cacheability = new CacheableMetadata();
    $cacheability->setCacheTags([
      'node_list:help_article',
      'node_list:faq',
      'taxonomy_term_list',
      'config:taxonomy.vocabulary.help_topic',
    ]);
    $cacheability->setCacheContexts([
      'url.path',
      'url.query_args:q',
      'languages:language_interface',
      'user.permissions',
    ]);
    $cacheability->applyTo($build);

    return $build;
  }

  /**
   * Renders the attendee Help Centre listing.
   */
  public function attendeesIndex(): array {
    return $this->buildViewPage((string) $this->t('Attendee help'), 'mel_help_attendee_help', 'block_attendees');
  }

  /**
   * Renders the organiser Help Centre listing.
   */
  public function organisersIndex(): array {
    return $this->buildViewPage((string) $this->t('Organiser help'), 'mel_help_organiser_help', 'block_organisers');
  }

  /**
   * Renders the vendor Help Centre listing.
   */
  public function vendorsIndex(): array {
    return $this->buildViewPage((string) $this->t('Vendor help'), 'mel_help_vendor_help', 'block_vendors', TRUE);
  }

  /**
   * Renders the policies and trust Help Centre listing.
   */
  public function policiesIndex(): array {
    return $this->buildViewPage((string) $this->t('Policies and trust'), 'mel_help_policies_help', 'block_policies');
  }

  /**
   * Renders Help Centre articles by category.
   */
  public function categoryIndex(TermInterface $category): array {
    return $this->buildViewPage((string) $category->label(), 'mel_help_search', 'block_search');
  }

  /**
   * Category page title callback.
   */
  public function categoryIndexTitle(TermInterface $category): string {
    return (string) $category->label();
  }

  /**
   * Renders scoped Help Centre search for articles and FAQs.
   */
  public function searchIndex(): array {
    $request = $this->requestStack->getCurrentRequest();
    $query = trim((string) ($request?->query->get('q') ?? ''));
    if ($query !== '') {
      $resultCount = $this->countSearchResults($request?->query->all() ?? []);
      $this->analyticsService->logSearch($query, $resultCount);
    }
    return $this->buildViewPage((string) $this->t('Search help'), 'mel_help_search', 'block_search');
  }

  /**
   * Vendor Help Centre homepage: same layout as /help, scoped for vendors.
   */
  public function vendorHelp(): array {
    $currentRequest = $this->requestStack->getCurrentRequest();
    $queryValue = $currentRequest?->query->get('q');
    $searchValue = is_string($queryValue) ? trim($queryValue) : '';

    $build = [
      '#theme' => 'help_centre_home',
      '#context' => 'vendor',
      '#help_search' => [
        'action' => Url::fromRoute('myeventlane_help_centre.search')->toString(),
        'value' => $searchValue,
        'placeholder' => $this->t('Search help articles...'),
      ],
      '#featured_articles' => $this->buildView('mel_help_featured_articles', 'block_featured'),
      '#vendors' => $this->buildView('mel_help_vendor_help', 'block_vendors'),
      '#help_categories' => $this->loadHelpCategories(),
      '#faq_listing' => $this->buildView('mel_help_faq', 'block_faq'),
    ];

    $cacheability = new CacheableMetadata();
    $cacheability->setCacheTags([
      'node_list:help_article',
      'node_list:faq',
      'taxonomy_term_list',
      'config:taxonomy.vocabulary.help_topic',
      'config:taxonomy.vocabulary.help_audience',
    ]);
    $cacheability->setCacheContexts([
      'url.path',
      'url.query_args:q',
      'languages:language_interface',
      'user.permissions',
    ]);
    $cacheability->applyTo($build);

    return $build;
  }

  /**
   * Existing vendor topic route, retained for backward compatibility.
   */
  public function vendorTopic(TermInterface $category): array {
    return $this->buildViewPage((string) $category->label(), 'mel_help_vendor_help', 'block_vendors', TRUE, [(int) $category->id()]);
  }

  /**
   * Existing vendor topic title callback.
   */
  public function vendorTopicTitle(TermInterface $category): string {
    return (string) $category->label();
  }

  /**
   * Renders the staff guide help article.
   */
  public function staffSnippetAuthoring(): array {
    try {
      $nodes = $this->entityTypeManagerService->getStorage('node')
        ->loadByProperties([
          'type' => 'help_article',
          'title' => 'Staff Snippet Authoring Guide',
          'status' => 1,
        ]);
      $node = $nodes ? reset($nodes) : NULL;
    }
    catch (\Exception $exception) {
      $this->logger->error('Failed to load Staff Snippet Authoring Guide: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return [
        '#markup' => '<p>' . $this->t('The guide is not yet available. Please contact your administrator.') . '</p>',
        '#cache' => ['max-age' => 0],
      ];
    }

    if (!$node) {
      return [
        '#markup' => '<p>' . $this->t('The guide has not been created yet. Run update.php to create it.') . '</p>',
        '#cache' => ['max-age' => 0],
      ];
    }

    $build = $this->entityTypeManagerService->getViewBuilder('node')->view($node, 'full');
    $cacheability = CacheableMetadata::createFromRenderArray($build);
    $cacheability->addCacheTags(['node:' . $node->id(), 'node_list:help_article']);
    $cacheability->addCacheContexts(['user.permissions']);
    $cacheability->applyTo($build);

    return $build;
  }

  private function buildViewPage(string $heading, string $viewId, string $displayId, bool $isVendor = FALSE, array $arguments = []): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['help-centre-page']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h1',
        '#value' => $heading,
        '#attributes' => ['class' => ['help-centre-page__title']],
      ],
      'listing' => $this->buildView($viewId, $displayId, $arguments),
      'support' => [
        '#markup' => '<section class="mel-help-support-panel"><h2>' . $this->t('Still need help?') . '</h2><p>' . $this->t('If you still need a hand, contact support and include your booking or event details.') . '</p><p><a class="mel-help-support-panel__link" href="/contact">' . $this->t('Contact support') . '</a></p></section>',
      ],
    ];

    if ($isVendor) {
      $build['#attributes']['class'][] = 'help-centre-page--vendor';
    }

    return $build;
  }

  /**
   * Builds a render array for a configured View display.
   *
   * @param array<int, int|string> $arguments
   *   Optional contextual arguments.
   */
  private function buildView(string $viewId, string $displayId, array $arguments = []): array {
    return [
      '#type' => 'view',
      '#name' => $viewId,
      '#display_id' => $displayId,
      '#arguments' => $arguments,
    ];
  }

  /**
   * Loads Help Topic terms and article counts for the homepage.
   */
  private function loadHelpCategories(): array {
    try {
      $vocabularyId = $this->vocabularyExists('help_topic') ? 'help_topic' : 'help_categories';
      $storageDefinitions = $this->entityFieldManager->getActiveFieldStorageDefinitions('node');
      $topicFieldName = isset($storageDefinitions['field_help_topic']) ? 'field_help_topic' : 'field_help_category';
      $termStorage = $this->entityTypeManagerService->getStorage('taxonomy_term');
      $terms = $termStorage->loadByProperties(['vid' => $vocabularyId]);
      if (empty($terms)) {
        return [];
      }

      usort($terms, static fn (TermInterface $a, TermInterface $b): int => strnatcasecmp($a->label(), $b->label()));

      $nodeStorage = $this->entityTypeManagerService->getStorage('node');
      $categories = [];
      foreach ($terms as $term) {
        $articleCount = (int) $nodeStorage->getQuery()
          ->condition('type', 'help_article')
          ->condition('status', 1)
          ->condition($topicFieldName . '.target_id', (int) $term->id())
          ->accessCheck(TRUE)
          ->count()
          ->execute();

        $categories[] = [
          'name' => $term->label(),
          'url' => Url::fromRoute('myeventlane_help_centre.search', [], [
            'query' => ['topic' => (int) $term->id()],
          ])->toString(),
          'article_count' => $articleCount,
        ];
      }

      return $categories;
    }
    catch (\Exception $exception) {
      $this->logger->error('Failed to load help categories: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Returns TRUE when the taxonomy vocabulary exists.
   */
  private function vocabularyExists(string $vocabularyId): bool {
    return $this->entityTypeManagerService->getStorage('taxonomy_vocabulary')->load($vocabularyId) !== NULL;
  }

  /**
   * Executes the search view for analytics result counts.
   *
   * @param array<string, mixed> $query
   *   Current query arguments.
   */
  private function countSearchResults(array $query): int {
    $view = Views::getView('mel_help_search');
    if (!$view) {
      return 0;
    }
    $view->setDisplay('block_search');
    $view->setExposedInput($query);
    $view->execute();
    return count($view->result);
  }

}
