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

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * @return list<string>
   */
  public function seedPlaybooks(): array {
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
      $term = $this->loadCategoryTerm((string) ($definition['category'] ?? ''));
      if ($term instanceof TermInterface) {
        $node->set('field_organiser_category', ['target_id' => $term->id()]);
      }
      if ($node->get('body')->isEmpty()) {
        $node->set('body', [
          'value' => '<p>' . htmlspecialchars((string) ($definition['excerpt'] ?? $title), ENT_QUOTES) . '</p>',
          'format' => 'basic_html',
        ]);
      }
      $node->save();

      $messages[] = $created
        ? (string) t('Created playbook draft: @title', ['@title' => $title])
        : (string) t('Updated playbook draft: @title', ['@title' => $title]);
      $logger->notice('Organiser hub seed @key: @title', ['@key' => (string) $key, '@title' => $title]);
    }

    return $messages;
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
