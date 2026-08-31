<?php

declare(strict_types=1);

namespace Drupal\myeventlane_help_centre\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Resolves MEL contextual help keys to help_article nodes and render arrays.
 *
 * Prefers stable matching via field_mel_register_id (from docs import) when
 * the field exists; otherwise falls back to exact node title match.
 */
final class ContextualHelpResolver {

  use StringTranslationTrait;

  /**
   * Context key => [primary register id, optional secondary register id].
   *
   * Register IDs align with the MEL documentation register / CSV import.
   *
   * @var array<string, array{0: string, 1?: string}>
   */
  private const CONTEXT_REGISTER_MAP = [
    'event_wizard_basics' => ['V002', 'V001'],
    'event_wizard_when_where' => ['V002', 'V001'],
    'event_wizard_details' => ['V002', 'V001'],
    'event_wizard_tickets' => ['V003', 'V002'],
    'event_wizard_publish' => ['V007', 'V002'],
    'event_wizard_review' => ['V002', 'V001'],
    'checkout_booking' => ['H002', 'H003'],
    'checkout_payment' => ['H002', 'H003'],
    'checkout_confirmation' => ['H007', 'H008'],
    'vendor_dashboard_overview' => ['V001', 'V002'],
    'vendor_dashboard_attendees' => ['V004', 'V001'],
    'vendor_dashboard_orders' => ['V006', 'V004'],
    'vendor_dashboard_payout' => ['V005', 'V001'],
    'refunds_help' => ['V008', 'H003'],
  ];

  /**
   * Register id => canonical English title (matches import CSV Title column).
   *
   * @var array<string, non-empty-string>
   */
  private const REGISTER_TITLE = [
    'H002' => 'How to buy tickets',
    'H003' => 'Refunds explained',
    'H005' => 'Creating an account',
    'H007' => 'What happens after booking',
    'H008' => 'Contacting support',
    'V001' => 'Getting started as an organiser',
    'V002' => 'How to create an event',
    'V003' => 'Setting up tickets',
    'V004' => 'Managing attendees',
    'V005' => 'Payouts and fees',
    'V006' => 'Editing your event',
    'V007' => 'Event visibility and promotion',
    'V008' => 'Managing event orders, add-ons and refunds',
  ];

  private ?bool $registerFieldExists = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LoggerChannelInterface $logger,
    TranslationInterface $stringTranslation,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Builds a themed contextual help card render array, or an empty array.
   *
   * @param array{
   *   show_assistant_secondary?: bool,
   *   vendor_help_index_url?: string|null,
   * } $options
   *   show_assistant_secondary: When TRUE, secondary action may be Help Assistant.
   *   vendor_help_index_url: Optional URL when no secondary article (legacy).
   *   override_secondary_url: When set, use as secondary link (with optional
   *     override_secondary_label) instead of the mapped secondary article.
   *
   * @return array<string, mixed>
   *   A render array (possibly empty).
   */
  public function buildCard(string $context, array $options = []): array {
    $row = self::CONTEXT_REGISTER_MAP[$context] ?? NULL;
    if ($row === NULL) {
      $this->logger->warning('Unknown contextual help context @ctx.', ['@ctx' => $context]);
      return [];
    }

    [$primaryReg, $secondaryReg] = [$row[0], $row[1] ?? NULL];
    $node = $this->loadArticleByRegisterId($primaryReg);
    if (!$node instanceof NodeInterface) {
      $this->logger->warning('Contextual help article missing for context @ctx (register @reg).', [
        '@ctx' => $context,
        '@reg' => $primaryReg,
      ]);
      return [];
    }

    if (!$node->access('view', $this->currentUser)) {
      return [];
    }

    $title = $node->label();
    $summary = $this->buildSummary($node);
    $url = $node->toUrl()->setAbsolute(FALSE)->toString();

    $secondaryUrl = NULL;
    $secondaryLabel = NULL;

    $overrideSecondary = trim((string) ($options['override_secondary_url'] ?? ''));
    if ($overrideSecondary !== '') {
      $secondaryUrl = $overrideSecondary;
      $secondaryLabel = trim((string) ($options['override_secondary_label'] ?? ''));
      if ($secondaryLabel === '') {
        $secondaryLabel = (string) $this->t('Learn more');
      }
    }

    $showAssistant = (bool) ($options['show_assistant_secondary'] ?? FALSE);
    if ($secondaryUrl === NULL && $showAssistant && $this->assistantUrl() !== NULL) {
      $secondaryUrl = $this->assistantUrl();
      $secondaryLabel = (string) $this->t('Ask MEL Help');
    }
    elseif ($secondaryUrl === NULL && $secondaryReg !== NULL) {
      $sec = $this->loadArticleByRegisterId($secondaryReg);
      if ($sec instanceof NodeInterface && $sec->access('view', $this->currentUser)) {
        $secondaryUrl = $sec->toUrl()->setAbsolute(FALSE)->toString();
        $secondaryLabel = (string) $this->t('Related: @title', ['@title' => $sec->label()]);
      }
    }

    if ($secondaryUrl === NULL && !empty($options['vendor_help_index_url'])) {
      $secondaryUrl = (string) $options['vendor_help_index_url'];
      $secondaryLabel = (string) $this->t('Browse vendor help');
    }

    $audience = $this->formatAudience($node);

    $cacheTags = $node->getCacheTags();
    $cacheTags[] = 'node_list:help_article';

    return [
      '#theme' => 'contextual_help_card',
      '#title' => $title,
      '#summary' => $summary,
      '#url' => $url,
      '#read_more_label' => (string) $this->t('Read more'),
      '#secondary_url' => $secondaryUrl,
      '#secondary_label' => $secondaryLabel,
      '#context' => $context,
      '#audience' => $audience,
      '#attached' => [
        'library' => ['myeventlane_help_centre/contextual_help_card'],
      ],
      '#cache' => [
        'keys' => ['mel_contextual_help', $context],
        'contexts' => ['user', 'languages:language_interface'],
        'tags' => $cacheTags,
        'max-age' => -1,
      ],
    ];
  }

  /**
   * Resolves a help_article node by MEL register id or exact title.
   */
  public function loadArticleByRegisterId(string $registerId): ?NodeInterface {
    $registerId = trim($registerId);
    if ($registerId === '') {
      return NULL;
    }

    $title = self::REGISTER_TITLE[$registerId] ?? NULL;
    if ($title === NULL) {
      $this->logger->warning('No title mapping for register id @id.', ['@id' => $registerId]);
      return NULL;
    }

    if ($this->registerIdFieldExists()) {
      $ids = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'help_article')
        ->condition('status', 1)
        ->condition('field_mel_register_id', $registerId)
        ->range(0, 1)
        ->execute();
      $id = $ids ? (int) reset($ids) : 0;
      if ($id > 0) {
        $node = $this->entityTypeManager->getStorage('node')->load($id);
        return $node instanceof NodeInterface ? $node : NULL;
      }
    }

    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'help_article')
      ->condition('status', 1)
      ->condition('title', $title)
      ->range(0, 1)
      ->execute();
    $id = $ids ? (int) reset($ids) : 0;
    if ($id < 1) {
      return NULL;
    }
    $node = $this->entityTypeManager->getStorage('node')->load($id);
    return $node instanceof NodeInterface ? $node : NULL;
  }

  private function registerIdFieldExists(): bool {
    if ($this->registerFieldExists !== NULL) {
      return $this->registerFieldExists;
    }
    $defs = $this->entityFieldManager->getFieldDefinitions('node', 'help_article');
    $this->registerFieldExists = isset($defs['field_mel_register_id']);
    return $this->registerFieldExists;
  }

  private function buildSummary(NodeInterface $node): string {
    if ($node->hasField('field_help_summary') && !$node->get('field_help_summary')->isEmpty()) {
      $text = trim((string) $node->get('field_help_summary')->value);
      if ($text !== '') {
        return $text;
      }
    }
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $value = (string) $node->get('body')->value;
      $plain = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
      if ($plain !== '') {
        return $this->truncate($plain, 200);
      }
    }
    return '';
  }

  private function truncate(string $text, int $max): string {
    if (strlen($text) <= $max) {
      return $text;
    }
    return rtrim(substr($text, 0, $max - 1)) . '…';
  }

  private function formatAudience(NodeInterface $node): string {
    if (!$node->hasField('field_audience') || $node->get('field_audience')->isEmpty()) {
      return '';
    }
    $parts = [];
    foreach ($node->get('field_audience') as $item) {
      $v = trim((string) $item->value);
      if ($v !== '') {
        $parts[] = $v;
      }
    }
    return implode(', ', $parts);
  }

  private function assistantUrl(): ?string {
    if (!$this->moduleHandler->moduleExists('myeventlane_help_assistant')) {
      return NULL;
    }
    try {
      $url = Url::fromRoute('myeventlane_help_assistant.page');
      if ($url->access($this->currentUser)) {
        return $url->setAbsolute(FALSE)->toString();
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Contextual help assistant URL failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
    return NULL;
  }

}
