<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\myeventlane_venue\Entity\VenueIssue;

/**
 * Provides a list controller for the venue issue entity type.
 */
class VenueIssueListBuilder extends EntityListBuilder {

  /**
   * Issue type labels.
   */
  protected const TYPE_LABELS = [
    VenueIssue::TYPE_ADDRESS => 'Incorrect address',
    VenueIssue::TYPE_DUPLICATE => 'Duplicate venue',
    VenueIssue::TYPE_ACCESSIBILITY => 'Accessibility issue',
    VenueIssue::TYPE_INAPPROPRIATE => 'Inappropriate content',
    VenueIssue::TYPE_OTHER => 'Other',
  ];

  /**
   * Status labels.
   */
  protected const STATUS_LABELS = [
    VenueIssue::STATUS_OPEN => 'Open',
    VenueIssue::STATUS_TRIAGED => 'Triaged',
    VenueIssue::STATUS_CLOSED => 'Closed',
  ];

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header = [
      'venue' => $this->t('Venue'),
      'type' => $this->t('Type'),
      'reporter' => $this->t('Reporter'),
      'status' => $this->t('Status'),
      'created' => $this->t('Reported'),
    ];
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    if (!$entity instanceof VenueIssue) {
      return parent::buildRow($entity);
    }

    $venue = $entity->getVenue();
    $reporter = $entity->getOwner();

    // Get type and status values directly to avoid render array issues.
    $typeValue = $entity->get('type')->value ?? '';
    $statusValue = $entity->get('status')->value ?? '';

    $row = [
      'venue' => $venue ? $venue->toLink() : $this->t('Unknown'),
      'type' => $this->t(self::TYPE_LABELS[$typeValue] ?? $typeValue),
      'reporter' => $reporter?->getDisplayName() ?? $this->t('Unknown'),
      'status' => $this->t(self::STATUS_LABELS[$statusValue] ?? $statusValue),
      'created' => \Drupal::service('date.formatter')->format($entity->get('created')->value ?? 0, 'short'),
    ];
    return $row + parent::buildRow($entity);
  }

}
