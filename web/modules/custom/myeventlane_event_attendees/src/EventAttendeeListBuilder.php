<?php

namespace Drupal\myeventlane_event_attendees;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin list builder for EventAttendee.
 */
class EventAttendeeListBuilder extends EntityListBuilder {

  /**
   * Constructs EventAttendeeListBuilder.
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
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['id'] = $this->t('ID');
    $header['event'] = $this->t('Event');
    $header['user'] = $this->t('User / Name');
    $header['status'] = $this->t('Status');
    $header['created'] = $this->t('Created');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\myeventlane_event_attendees\Entity\EventAttendee $entity */
    // Updated to match the current EventAttendee base fields.
    $event = $entity->get('event')->entity;
    $user = $entity->getOwner();

    $row['id'] = $entity->id();

    $row['event'] = $event ? Link::createFromRoute(
      $event->label(),
      'entity.node.canonical',
      ['node' => $event->id()]
    ) : '-';

    $row['user'] = $user
      ? $user->label()
      : $entity->get('name')->value;

    $row['status'] = $entity->get('status')->value;
    $row['created'] = $this->dateFormatter->format(
      $entity->get('created')->value,
      'short'
    );

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   *
   * Disable default operations (Edit/Delete) to avoid relying on routes we
   * don't currently expose in the admin UI.
   */
  public function getOperations(EntityInterface $entity): array {
    return [];
  }

}
