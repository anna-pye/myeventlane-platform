<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Seeds organiser hub playbook article stubs from exported config.
 */
final class OrganiserHubContentSeeder {

  /**
   * @var list<string>
   */
  private const REQUIRED_NODE_FIELDS = [
    'field_playbook',
    'field_featured_playbook',
    'field_excerpt',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * @return list<string>
   */
  public function seedPlaybooks(): array {
    $missing = $this->getMissingRequirements();
    if ($missing !== []) {
      return [
        (string) t(
          'Skipped organiser hub playbook seeding. Missing: @items. Import configuration, then re-run database updates.',
          ['@items' => implode(', ', $missing)],
        ),
      ];
    }

    $config = $this->configFactory->get('myeventlane_front.organiser_hub_seeds');
    $playbooks = $config->get('playbooks') ?? [];
    if (!is_array($playbooks) || $playbooks === []) {
      return [(string) t('No organiser hub playbook seeds found.')];
    }

    $messages = [];
    $logger = $this->loggerFactory->get('myeventlane_front');
    foreach ($playbooks as $key => $definition) {
      if (!is_array($definition)) {
        continue;
      }
      $title = trim((string) ($definition['title'] ?? ''));
      if ($title === '') {
        continue;
      }

      try {
        $message = $this->seedPlaybook((string) $key, $title, $definition);
        if ($message !== NULL) {
          $messages[] = $message;
        }
      }
      catch (\Throwable $exception) {
        $logger->error('Organiser hub seed failed for @key: @message', [
          '@key' => (string) $key,
          '@message' => $exception->getMessage(),
        ]);
        $messages[] = (string) t('Failed to seed playbook @title: @error', [
          '@title' => $title,
          '@error' => $exception->getMessage(),
        ]);
      }
    }

    if ($messages === []) {
      return [(string) t('No organiser hub playbook content was seeded.')];
    }

    return $messages;
  }

  /**
   * @param array<string, mixed> $definition
   */
  private function seedPlaybook(string $key, string $title, array $definition): ?string {
    $existing = $this->entityTypeManager->getStorage('node')->loadByProperties([
      'type' => 'blog_post',
      'title' => $title,
    ]);
    $node = is_array($existing) && $existing !== [] ? reset($existing) : NULL;
    $created = FALSE;
    if (!$node instanceof NodeInterface) {
      $node = Node::create([
        'type' => 'blog_post',
        'title' => $title,
        'status' => 0,
      ]);
      $created = TRUE;
    }

    $node->set('field_playbook', 1);
    $node->set('field_featured_playbook', !empty($definition['featured']) ? 1 : 0);
    if (!empty($definition['excerpt'])) {
      $node->set('field_excerpt', (string) $definition['excerpt']);
    }
    if ($node->hasField('field_organiser_category')) {
      $term = $this->loadCategoryTerm((string) ($definition['category'] ?? ''));
      if ($term instanceof TermInterface) {
        $node->set('field_organiser_category', ['target_id' => $term->id()]);
      }
    }
    if ($node->hasField('body') && $node->get('body')->isEmpty()) {
      $node->set('body', [
        'value' => '<p>' . htmlspecialchars((string) ($definition['excerpt'] ?? $title), ENT_QUOTES) . '</p>',
        'format' => $this->resolveBodyFormat(),
      ]);
    }
    $node->save();

    $this->loggerFactory->get('myeventlane_front')->notice('Organiser hub seed @key: @title', [
      '@key' => $key,
      '@title' => $title,
    ]);

    return $created
      ? (string) t('Created playbook draft: @title', ['@title' => $title])
      : (string) t('Updated playbook draft: @title', ['@title' => $title]);
  }

  /**
   * @return list<string>
   */
  public function getMissingRequirements(): array {
    $missing = [];
    $fieldStorage = $this->entityTypeManager->getStorage('field_storage_config');
    foreach (self::REQUIRED_NODE_FIELDS as $fieldName) {
      if ($fieldStorage->load('node.' . $fieldName) === NULL) {
        $missing[] = $fieldName;
      }
    }
    if (!$this->entityTypeManager->getStorage('node_type')->load('blog_post')) {
      $missing[] = 'blog_post bundle';
    }
    if ($this->configFactory->get('myeventlane_front.organiser_hub_seeds')->isNew()) {
      $missing[] = 'myeventlane_front.organiser_hub_seeds config';
    }
    return $missing;
  }

  private function resolveBodyFormat(): string {
    $storage = $this->entityTypeManager->getStorage('filter_format');
    foreach (['basic_html', 'restricted_html', 'plain_text'] as $candidate) {
      if ($storage->load($candidate) !== NULL) {
        return $candidate;
      }
    }
    return 'plain_text';
  }

  private function loadCategoryTerm(string $suffix): ?TermInterface {
    $suffix = trim($suffix);
    if ($suffix === '') {
      return NULL;
    }
    $config = $this->configFactory->get('taxonomy.term.organiser_hub_categories.' . $suffix);
    if (!$config->isNew()) {
      $name = trim((string) $config->get('name'));
      if ($name !== '') {
        $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
          'vid' => 'organiser_hub_categories',
          'name' => $name,
        ]);
        if (is_array($terms) && $terms !== []) {
          $term = reset($terms);
          if ($term instanceof TermInterface) {
            return $term;
          }
        }
      }
    }
    return NULL;
  }

}
