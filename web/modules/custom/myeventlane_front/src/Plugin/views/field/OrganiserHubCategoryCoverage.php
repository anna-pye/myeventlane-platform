<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Plugin\views\field;

use Drupal\myeventlane_front\Service\OrganiserHubEditorialService;
use Drupal\taxonomy\TermInterface;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @ViewsField("organiser_hub_category_coverage")
 */
final class OrganiserHubCategoryCoverage extends FieldPluginBase {

  /**
   * @var array<int, array<string, mixed>>|null
   */
  private ?array $coverage = NULL;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly OrganiserHubEditorialService $editorial,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('myeventlane_front.organiser_hub_editorial'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values): string {
    $entity = $values->_entity ?? NULL;
    if (!$entity instanceof TermInterface) {
      return '';
    }
    $row = $this->coverageMap()[(int) $entity->id()] ?? NULL;
    if (!is_array($row)) {
      return $this->t('No data');
    }
    $gap = !empty($row['gap']) ? ' <span class="status status--warning">' . $this->t('Gap') . '</span>' : '';
    return $this->t('Playbooks: @p · Articles: @a · Downloads: @d', [
      '@p' => (string) $row['playbook_count'],
      '@a' => (string) $row['article_count'],
      '@d' => (string) $row['download_count'],
    ]) . $gap;
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  private function coverageMap(): array {
    if ($this->coverage !== NULL) {
      return $this->coverage;
    }
    $this->coverage = [];
    foreach ($this->editorial->getCategoryCoverage() as $row) {
      $this->coverage[$row['term_id']] = $row;
    }
    return $this->coverage;
  }

}
