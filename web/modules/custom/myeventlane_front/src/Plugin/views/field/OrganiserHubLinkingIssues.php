<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Plugin\views\field;

use Drupal\myeventlane_front\Service\OrganiserHubEditorialService;
use Drupal\node\NodeInterface;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @ViewsField("organiser_hub_linking_issues")
 */
final class OrganiserHubLinkingIssues extends FieldPluginBase {

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
    if (!$entity instanceof NodeInterface) {
      return '';
    }
    $issues = $this->editorial->getLinkingIssues($entity);
    if ($issues === []) {
      return '<span class="status status--enabled">' . $this->t('OK') . '</span>';
    }
    $items = array_map(static fn (string $issue): string => '<li>' . htmlspecialchars($issue, ENT_QUOTES) . '</li>', $issues);
    return '<ul class="mel-organiser-hub-admin__warnings">' . implode('', $items) . '</ul>';
  }

}
