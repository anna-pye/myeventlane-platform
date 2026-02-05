<?php

declare(strict_types=1);

namespace Drupal\myeventlane_page_visuals;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\myeventlane_page_visuals\Entity\PageVisualInterface;

/**
 * List builder for Page Visual config entities.
 */
final class PageVisualListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Label');
    $header['route_name'] = $this->t('Route');
    $header['enabled'] = $this->t('Enabled');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof PageVisualInterface);
    $row['label'] = $entity->label();
    $row['route_name'] = $entity->getRouteName();
    $row['enabled'] = $entity->isEnabled() ? $this->t('Yes') : $this->t('No');
    return $row + parent::buildRow($entity);
  }

}
