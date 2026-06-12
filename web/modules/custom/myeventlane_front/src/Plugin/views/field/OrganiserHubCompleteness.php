<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Plugin\views\field;

use Drupal\myeventlane_front\Service\OrganiserHubEditorialService;
use Drupal\node\NodeInterface;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders non-blocking organiser hub completeness warnings.
 *
 * @ViewsField("organiser_hub_completeness")
 */
final class OrganiserHubCompleteness extends FieldPluginBase {

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
    $node = $this->getNode($values);
    if ($node === NULL) {
      return '';
    }
    $warnings = $this->editorial->getCompletenessWarnings($node);
    if ($warnings === []) {
      return '<span class="status status--enabled">' . $this->t('Complete') . '</span>';
    }
    $items = array_map(static fn (string $warning): string => '<li>' . htmlspecialchars($warning, ENT_QUOTES) . '</li>', $warnings);
    return '<ul class="mel-organiser-hub-admin__warnings"><li class="visually-hidden">' . $this->t('Editorial warnings') . '</li>' . implode('', $items) . '</ul>';
  }

  private function getNode(ResultRow $values): ?NodeInterface {
    $entity = $values->_entity ?? NULL;
    return $entity instanceof NodeInterface ? $entity : NULL;
  }

}
