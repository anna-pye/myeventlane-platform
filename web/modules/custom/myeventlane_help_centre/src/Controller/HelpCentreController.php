<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\VendorConsoleTrust;
use Drupal\myeventlane_help_centre\Service\HelpAnalyticsService;
use Drupal\myeventlane_help_centre\Service\MelSupportSettingsBuilder;
use Drupal\taxonomy\TermInterface;
use Drupal\views\Views;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for public and vendor Help Centre pages.
 */
final class HelpCentreController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManagerService,
    private readonly RequestStack $requestStack,
    private readonly LoggerInterface $logger,
    private readonly HelpAnalyticsService $analyticsService,
    private readonly MelSupportSettingsBuilder $melSupportSettingsBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('request_stack'),
      $container->get('logger.factory')->get('myeventlane_help_centre'),
      $container->get('myeventlane_help_centre.analytics'),
      $container->get('myeventlane_help_centre.mel_support_settings_builder'),
    );
  }

  /**
   * Redirects legacy /help/index to the canonical help hub.
   */
  public function publicIndex(): RedirectResponse {
    return $this->redirect('myeventlane_help_centre.home', [], [], 301);
  }

  /**
   * Renders the Help Centre SaaS-style homepage.
   */
  public function homepage(): array {
    $currentRequest = $this->requestStack->getCurrentRequest();
    $queryValue = $currentRequest?->query->get('q');
    $searchValue = is_string($queryValue) ? trim($queryValue) : '';
    $contextValue = $currentRequest?->query->get('context');
    $isVendorContext = is_string($contextValue) && trim($contextValue) === 'vendor';

    $melPayload = $this->melSupportSettingsBuilder->buildDrupalSettings('hub', TRUE);

    $featuredArticlesHasContent = $this->hasFeaturedArticles();

    $build = [
      '#theme' => 'help_centre_home',
      '#context' => $isVendorContext ? 'vendor' : NULL,
      '#help_search' => [
        'action' => Url::fromRoute('myeventlane_help_centre.search')->toString(),
        'value' => $searchValue,
        'placeholder' => $this->t('Search help articles...'),
      ],
      '#featured_articles_has_content' => $featuredArticlesHasContent,
      '#featured_articles' => $featuredArticlesHasContent
        ? $this->buildView('mel_help_featured_articles', 'block_featured')
        : [],
      '#vendors' => $isVendorContext ? $this->buildView('mel_help_vendor_help', 'block_vendors') : [],
      '#faq_listing' => $this->buildView('mel_help_faq', 'block_faq'),
      '#ia_sections' => $isVendorContext ? [] : $this->buildHelpCentreIaSections(),
      '#help_assistant_page_url' => $this->getHelpAssistantPageUrl(),
      '#contact_support_url' => $this->getContactSupportUrl(),
      '#mel_support_layer' => [
        '#theme' => 'mel_support_layer',
        '#variant' => 'hub',
        '#escalation' => $melPayload['escalation'],
        '#show_intro' => TRUE,
        '#show_articles_jump' => !$isVendorContext,
        '#strings' => $melPayload['strings'],
        '#cache' => [
          'contexts' => [
            'languages:language_interface',
            'url.path',
            'url.query_args:q',
            'url.query_args:context',
            'url.query_args:event',
            'user.permissions',
            'url.query_args:mel_support_debug',
          ],
          'tags' => [
            'node_list:help_article',
            'node_list:faq',
            'config:taxonomy.vocabulary.help_topic',
          ],
        ],
      ],
      '#attached' => [
        'library' => [
          'myeventlane_help_centre/support_components',
        ],
        'drupalSettings' => [
          'melSupport' => $melPayload,
        ],
      ],
    ];

    $cacheability = new CacheableMetadata();
    $cacheability->setCacheTags([
      'node_list:help_article',
      'node_list:faq',
      'config:taxonomy.vocabulary.help_topic',
    ]);
    $cacheability->setCacheContexts([
      'url.path',
      'url.query_args:q',
      'url.query_args:context',
      'url.query_args:event',
      'languages:language_interface',
      'user.permissions',
      'url.query_args:mel_support_debug',
    ]);
    if ($this->moduleHandler()->moduleExists('myeventlane_help_assistant')) {
      $cacheability->addCacheTags(['config:myeventlane_help_assistant.settings']);
      $cacheability->addCacheContexts(['url.query_args:event']);
    }
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
  public function organisersIndex(): array|RedirectResponse {
    if (VendorConsoleTrust::accountIsTrustedForVendorConsole($this->currentUser())) {
      return $this->redirect('myeventlane_help_centre.vendors_index', [], [], 302);
    }
    return $this->buildViewPage((string) $this->t('Organiser help'), 'mel_help_organiser_help', 'block_organisers');
  }

  /**
   * Renders the vendor Help Centre listing.
   */
  public function vendorsIndex(): array|RedirectResponse {
    $account = $this->currentUser();
    if (!$account->isAuthenticated()) {
      return $this->redirect('user.login', [], [
        'query' => [
          'destination' => '/help/vendors',
        ],
      ], 302);
    }
    return $this->buildViewPage((string) $this->t('Organiser help'), 'mel_help_vendor_help', 'block_vendors', TRUE);
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
      $audienceRaw = $request?->query->get('audience');
      $audience = is_string($audienceRaw) ? trim($audienceRaw) : NULL;
      $allowedAudience = in_array($audience, ['public', 'vendor', 'staff'], TRUE) ? $audience : NULL;
      $this->analyticsService->logSearch($query, $resultCount, $allowedAudience);
    }
    return $this->buildViewPage((string) $this->t('Search help'), 'mel_help_search', 'block_search');
  }

  /**
   * Legacy /vendor/help route: redirect to the canonical Help Centre hub.
   */
  public function vendorHelp(): RedirectResponse {
    return $this->redirect('myeventlane_help_centre.home', [], [], 301);
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

  /**
   * Structured Help Centre IA for the public homepage (Discover / Book / Manage / Policies).
   *
   * @return list<array<string, mixed>>
   */
  private function buildHelpCentreIaSections(): array {
    return [
      [
        'id' => 'discover',
        'title' => (string) $this->t('Discover'),
        'description' => (string) $this->t('Search, featured guides, and FAQs to get oriented quickly.'),
        'cards' => [
          [
            'title' => (string) $this->t('Search help'),
            'url' => Url::fromRoute('myeventlane_help_centre.search')->toString(),
            'excerpt' => (string) $this->t('Jump straight into articles and answers across the Help Centre.'),
          ],
          [
            'title' => (string) $this->t('Help Assistant'),
            'url' => Url::fromRoute('myeventlane_help_centre.home', [], ['fragment' => 'mel-help-assistant'])->toString(),
            'excerpt' => (string) $this->t('Ask a question and get answers grounded in our Help Centre articles.'),
          ],
        ],
      ],
      [
        'id' => 'book',
        'title' => (string) $this->t('Book'),
        'description' => (string) $this->t('Tickets, RSVPs, checkout, and attendee questions.'),
        'cards' => [
          [
            'title' => (string) $this->t('Attendee help'),
            'url' => Url::fromRoute('myeventlane_help_centre.attendees_index')->toString(),
            'excerpt' => (string) $this->t('Ticketing, refunds, RSVPs, and event-day access.'),
          ],
        ],
      ],
      [
        'id' => 'manage',
        'title' => (string) $this->t('Manage'),
        'description' => (string) $this->t('Run events, vendor tools, and organiser workflows.'),
        'cards' => [
          [
            'title' => (string) $this->t('Organiser help'),
            'url' => Url::fromRoute('myeventlane_help_centre.organisers_index')->toString(),
            'excerpt' => (string) $this->t('Setup, ticketing, payouts, and day-of operations.'),
          ],
          [
            'title' => (string) $this->t('Vendor help'),
            'url' => $this->currentUser()->isAuthenticated()
              ? Url::fromRoute('myeventlane_help_centre.vendors_index')->toString()
              : Url::fromRoute('user.login', [], [
                'query' => ['destination' => '/help/vendors'],
              ])->toString(),
            'excerpt' => (string) $this->t('Vendor profile, applications, and organiser console basics.'),
          ],
        ],
      ],
      [
        'id' => 'policies',
        'title' => (string) $this->t('Policies'),
        'description' => (string) $this->t('Trust, safety, refunds, and platform rules.'),
        'cards' => [
          [
            'title' => (string) $this->t('Policies and trust'),
            'url' => Url::fromRoute('myeventlane_help_centre.policies_index')->toString(),
            'excerpt' => (string) $this->t('Refund policy, community expectations, and privacy highlights.'),
          ],
        ],
      ],
    ];
  }

  private function buildViewPage(string $heading, string $viewId, string $displayId, bool $isVendor = FALSE, array $arguments = []): array {
    $assistantUrl = $this->getHelpAssistantPageUrl();

    $support_markup = '<section class="mel-help-support-panel"><h2>' . $this->t('Still need help?') . '</h2><p>' . $this->t('If you still need a hand, contact support and include your booking or event details.') . '</p>';
    if ($assistantUrl !== '') {
      $support_markup .= '<p><a class="mel-help-support-panel__link" href="' . htmlspecialchars($assistantUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $this->t('Open the Help Assistant') . '</a></p>';
    }
    $contact_support_href = htmlspecialchars($this->getContactSupportUrl(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $support_markup .= '<p><a class="mel-help-support-panel__link" href="' . $contact_support_href . '">' . $this->t('Contact support') . '</a></p></section>';

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['help-centre-page']],
      'listing' => $this->buildView($viewId, $displayId, $arguments),
      'support' => [
        '#markup' => $support_markup,
        '#cache' => [
          'contexts' => ['user.permissions'],
          'tags' => $assistantUrl !== '' ? ['config:myeventlane_help_assistant.settings'] : [],
        ],
      ],
    ];

    // The MEL Help Search view renders its own page title inside the exposed form
    // hero (search + filters). Other listing pages keep the controller heading.
    if ($viewId !== 'mel_help_search') {
      $build = array_merge([
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h1',
          '#value' => $heading,
          '#attributes' => ['class' => ['help-centre-page__title']],
        ],
      ], $build);
    }

    if ($isVendor) {
      $build['#attributes']['class'][] = 'help-centre-page--vendor';
    }

    return $build;
  }

  /**
   * Whether published featured help articles exist for the hub section.
   */
  private function hasFeaturedArticles(): bool {
    try {
      $count = $this->entityTypeManagerService->getStorage('node')->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'help_article')
        ->condition('status', 1)
        ->condition('field_featured_help', 1)
        ->range(0, 1)
        ->count()
        ->execute();
      return $count > 0;
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not count featured help articles: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
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

  /**
   * Returns the customer support tickets URL (Help Centre “Contact support”).
   */
  private function getContactSupportUrl(): string {
    return \_myeventlane_help_centre_contact_support_url();
  }

  /**
   * Returns the in-hub Help Assistant anchor URL on /help when the feature is enabled.
   */
  private function getHelpAssistantPageUrl(): string {
    if (!$this->moduleHandler()->moduleExists('myeventlane_help_assistant')) {
      return '';
    }
    $settings = $this->config('myeventlane_help_assistant.settings');
    if (!(bool) $settings->get('enabled')) {
      return '';
    }
    try {
      $url = Url::fromRoute('myeventlane_help_centre.home', [], ['fragment' => 'mel-help-assistant']);
      if (!$url->access($this->currentUser())) {
        return '';
      }
      return $url->toString();
    }
    catch (\Throwable) {
      return '';
    }
  }

}
