<?php

namespace Drupal\myeventlane_rsvp\Entity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

class RsvpSubmissionListBuilder extends EntityListBuilder {

  public function buildHeader(): array {
    $header['id'] = $this->t('ID');
    $header['event'] = $this->t('Event');
    $header['user'] = $this->t('User');
    $header['status'] = $this->t('Status');
    $header['created'] = $this->t('Created');
    return $header + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\myeventlane_rsvp\Entity\RsvpSubmission $entity */
    $row['id'] = $entity->id();
    $row['event'] = $entity->getEvent()?->label() ?? 'N/A';
    $row['user'] = $entity->getUser()?->label() ?? 'Anonymous';
    $row['status'] = $entity->get('status')->value;
    $row['created'] = \Drupal::service('date.formatter')
      ->format($entity->get('created')->value, 'short');

    return $row + parent::buildRow($entity);
  }

}