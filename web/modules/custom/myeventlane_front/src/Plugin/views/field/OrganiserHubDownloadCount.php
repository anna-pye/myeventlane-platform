<?php

declare(strict_types=1);

namespace Drupal\myeventlane_front\Plugin\views\field;

use Drupal\myeventlane_front\Service\OrganiserHubEditorialService;
use Drupal\node\NodeInterface;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @ViewsField("organiser_hub_download_count")
 */
final class OrganiserHubDownloadCount extends FieldPluginBase {

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
      return '0';
    }
    return (string) $this->editorial->getDownloadCount($entity);
  }

}
