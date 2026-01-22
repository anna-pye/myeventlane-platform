<?php

namespace Drupal\myeventlane_rsvp\Entity;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 *
 */
class RsvpSubmissionListBuilder extends EntityListBuilder {

  /**
   * Constructs RsvpSubmissionListBuilder.
   */
  public function __construct(
    $entity_type,
    EntityStorageInterface $storage,
    protected readonly DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type),
      $container->get('date.formatter')
    );
  }

  /**
   *
   */
  public function buildHeader(): array {
    $header['id'] = $this->t('ID');
    $header['event'] = $this->t('Event');
    $header['user'] = $this->t('User');
    $header['status'] = $this->t('Status');
    $header['created'] = $this->t('Created');
    return $header + parent::buildHeader();
  }

  /**
   *
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\myeventlane_rsvp\Entity\RsvpSubmission $entity */
    $row['id'] = $entity->id();
    $row['event'] = $entity->getEvent()?->label() ?? 'N/A';
    $row['user'] = $entity->getUser()?->label() ?? 'Anonymous';
    $row['status'] = $entity->get('status')->value;
    $row['created'] = $this->dateFormatter
      ->format($entity->get('created')->value, 'short');

    return $row + parent::buildRow($entity);
  }

}
