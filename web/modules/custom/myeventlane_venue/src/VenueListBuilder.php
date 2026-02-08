<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\myeventlane_venue\Entity\Venue;

/**
 * Provides a list controller for the venue entity type.
 */
class VenueListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header = [
      'name' => $this->t('Name'),
      'owner' => $this->t('Owner'),
      'visibility' => $this->t('Visibility'),
      'created' => $this->t('Created'),
    ];
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    if (!$entity instanceof Venue) {
      return parent::buildRow($entity);
    }

    $row = [
      'name' => $entity->toLink(),
      'owner' => $entity->getOwner()?->getDisplayName() ?? $this->t('Unknown'),
      'visibility' => $entity->isPublic() ? $this->t('Public') : $this->t('Shared'),
      'created' => \Drupal::service('date.formatter')->format($entity->getCreatedTime(), 'short'),
    ];
    return $row + parent::buildRow($entity);
  }

}
