<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\myeventlane_venue\Entity\VenueLocation;

/**
 * Provides a list controller for the venue location entity type.
 */
class VenueLocationListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header = [
      'title' => $this->t('Location'),
      'venue' => $this->t('Venue'),
      'address' => $this->t('Address'),
      'primary' => $this->t('Primary'),
    ];
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    if (!$entity instanceof VenueLocation) {
      return parent::buildRow($entity);
    }

    $venue = $entity->getVenue();

    $row = [
      'title' => $entity->getTitle(),
      'venue' => $venue ? $venue->toLink() : $this->t('Unknown'),
      'address' => $entity->getAddressText(),
      'primary' => $entity->isPrimary() ? $this->t('Yes') : $this->t('No'),
    ];
    return $row + parent::buildRow($entity);
  }

}
