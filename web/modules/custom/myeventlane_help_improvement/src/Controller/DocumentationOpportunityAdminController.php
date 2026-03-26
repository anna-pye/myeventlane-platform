<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_improvement\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\myeventlane_help_improvement\Form\DocumentationOpportunityEditForm;
use Drupal\myeventlane_help_improvement\Form\DocumentationOpportunityFiltersForm;
use Drupal\myeventlane_core\Service\MelAdminShellBuilder;
use Drupal\myeventlane_help_improvement\Service\DocumentationOpportunityStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admin UI for the documentation opportunity queue.
 */
final class DocumentationOpportunityAdminController extends ControllerBase {

  public function __construct(
    private readonly Connection $connection,
    private readonly DocumentationOpportunityStorage $storage,
    private readonly MelAdminShellBuilder $adminShellBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('myeventlane_help_improvement.storage'),
      $container->get('myeventlane_core.mel_admin_shell_builder'),
    );
  }

  /**
   * Tabular listing with filters and pager.
   */
  public function listing(Request $request): array {
    $status = $request->query->get('status');
    $source = $request->query->get('source');
    $audience = $request->query->get('audience');
    $status = is_string($status) ? $status : 'all';
    $source = is_string($source) ? $source : 'all';
    $audience = is_string($audience) ? $audience : 'all';

    $query = $this->connection->select('mel_doc_opportunity', 'o')->fields('o');
    if ($status !== 'all') {
      $query->condition('status', $status);
    }
    if ($source !== 'all') {
      $query->condition('source_type', $source);
    }
    if ($audience !== 'all') {
      $query->condition('audience', $audience);
    }
    $query->orderBy('last_seen', 'DESC');
    $pager = $query->extend(PagerSelectExtender::class)->limit(50)->element(0);
    /** @var \Drupal\Core\Database\StatementInterface $result */
    $result = $pager->execute();
    $rows = $result->fetchAll(\PDO::FETCH_ASSOC);

    $header = [
      $this->t('ID'),
      $this->t('Status'),
      $this->t('Source'),
      $this->t('Audience'),
      $this->t('Frequency'),
      $this->t('Last seen'),
      $this->t('Query / topic'),
      $this->t('Operations'),
    ];
    $tableRows = [];
    foreach ($rows as $row) {
      $id = (int) ($row['id'] ?? 0);
      $viewUrl = Url::fromRoute('myeventlane_help_improvement.opportunity', ['opportunity' => $id]);
      $tableRows[] = [
        (string) $id,
        (string) ($row['status'] ?? ''),
        (string) ($row['source_type'] ?? ''),
        (string) ($row['audience'] ?? ''),
        (string) ($row['frequency_count'] ?? '0'),
        $this->dateFormatter()->format((int) ($row['last_seen'] ?? 0), 'short'),
        mb_substr((string) ($row['query_text'] ?? ''), 0, 120),
        Link::fromTextAndUrl($this->t('View'), $viewUrl)->toString(),
      ];
    }

    $open = $this->storage->countOpen();

    $main = [
      '#attached' => [
        'library' => ['myeventlane_help_improvement/admin'],
      ],
      'summary' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Open opportunities (new, reviewing, drafted): @count', ['@count' => (string) $open]),
        '#attributes' => ['class' => ['mel-doc-opp-summary']],
      ],
      'filters' => $this->formBuilder()->getForm(DocumentationOpportunityFiltersForm::class, $status, $source, $audience),
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $tableRows,
        '#empty' => $this->t('No documentation opportunities match the current filters.'),
      ],
      'pager' => [
        '#type' => 'pager',
        '#element' => 0,
      ],
      '#cache' => [
        'contexts' => ['user.permissions', 'url.query_args:status', 'url.query_args:source', 'url.query_args:audience'],
        'max-age' => 0,
      ],
    ];

    return $this->adminShellBuilder->wrapStandard(
      $main,
      $this->t('Documentation opportunities'),
      $this->t('Review search and support signals that suggest new or improved help content.'),
    );
  }

  /**
   * Detail page with edit form and workflow links.
   */
  public function view(int $opportunity): array {
    $row = $this->storage->load($opportunity);
    if ($row === NULL) {
      throw new NotFoundHttpException();
    }

    $items = [
      $this->t('Source type: @v', ['@v' => (string) ($row['source_type'] ?? '')]),
      $this->t('Audience: @v', ['@v' => (string) ($row['audience'] ?? '')]),
      $this->t('Frequency: @v', ['@v' => (string) ($row['frequency_count'] ?? '')]),
      $this->t('Suggested title: @v', ['@v' => (string) ($row['suggested_title'] ?? '')]),
      $this->t('Suggested article type: @v', ['@v' => (string) ($row['suggested_article_type'] ?? '')]),
      $this->t('Normalized topic: @v', ['@v' => (string) ($row['normalized_topic'] ?? '')]),
    ];
    $linked = $row['linked_help_article_nid'] !== NULL ? (int) $row['linked_help_article_nid'] : 0;
    if ($linked > 0) {
      $items[] = $this->t('Linked help article nid: @nid', ['@nid' => (string) $linked]);
    }

    $meta = $this->storage->decodeMetadata($row['metadata'] !== NULL ? (string) $row['metadata'] : NULL);
    $metaMarkup = '';
    if ($meta !== NULL) {
      $metaMarkup = '<pre class="mel-doc-opp-meta">' . htmlspecialchars(json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
    }

    $build = [
      '#attached' => [
        'library' => ['myeventlane_help_improvement/admin'],
      ],
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('Back to list'),
        '#url' => Url::fromRoute('myeventlane_help_improvement.opportunities'),
        '#attributes' => ['class' => ['button']],
      ],
      'facts' => [
        '#theme' => 'item_list',
        '#items' => $items,
      ],
      'query' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('User need / query'),
      ],
      'query_body' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => (string) ($row['query_text'] ?? ''),
        '#attributes' => ['class' => ['mel-doc-opp-query']],
      ],
      'notes_heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Notes'),
      ],
      'notes_body' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $row['notes'] !== NULL ? (string) $row['notes'] : $this->t('—'),
      ],
      'metadata' => [
        '#markup' => $metaMarkup,
      ],
      'edit_form' => $this->formBuilder()->getForm(DocumentationOpportunityEditForm::class, $row),
      '#cache' => [
        'contexts' => ['user.permissions'],
        'max-age' => 0,
      ],
    ];

    if ($this->currentUser()->hasPermission('generate ai help drafts') && $this->currentUser()->hasPermission('administer documentation opportunities')) {
      $build['generate'] = [
        '#type' => 'link',
        '#title' => $this->t('Generate draft help article (AI-assisted)'),
        '#url' => Url::fromRoute('myeventlane_help_improvement.generate_draft', ['opportunity' => $opportunity]),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    }

    return $this->adminShellBuilder->wrapStandard(
      $build,
      $this->title($opportunity),
      '',
    );
  }

  public function title(int $opportunity): string {
    $row = $this->storage->load($opportunity);
    if ($row === NULL) {
      return (string) $this->t('Not found');
    }
    return (string) $this->t('Opportunity #@id', ['@id' => (string) $opportunity]);
  }

}
