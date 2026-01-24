<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\UI;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * List builder for Ticket Group entities.
 */
final class TicketGroupListBuilder extends EntityListBuilder {

  /**
   * Event ID to filter by.
   */
  private ?int $eventFilter = NULL;

  /**
   * The date formatter service.
   */
  protected readonly DateFormatterInterface $dateFormatter;

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
   * Constructs TicketGroupListBuilder.
   */
  public function __construct(
    $entity_type,
    EntityStorageInterface $storage,
    DateFormatterInterface $date_formatter,
  ) {
    parent::__construct($entity_type, $storage);
    $this->dateFormatter = $date_formatter;
  }

  /**
   * Sets the event filter for the list.
   */
  public function setEventFilter(int $event_id): void {
    $this->eventFilter = $event_id;
  }

  /**
   * {@inheritdoc}
   */
  public function load(): array {
    $query = $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->sort('weight', 'ASC')
      ->sort('name', 'ASC');

    if ($this->eventFilter !== NULL) {
      $query->condition('event', $this->eventFilter);
    }

    $ids = $query->execute();
    return $this->storage->loadMultiple($ids);
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['name'] = $this->t('Name');
    $header['description'] = $this->t('Description');
    $header['weight'] = $this->t('Weight');
    $header['status'] = $this->t('Status');
    $header['created'] = $this->t('Created');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\myeventlane_tickets\Entity\TicketGroup $entity */
    $row['name'] = Link::createFromRoute(
      $entity->getName(),
      'myeventlane_tickets.event_tickets_groups_edit',
      [
        'event' => $entity->getEventId(),
        'mel_ticket_group' => $entity->id(),
      ]
    );
    $row['description'] = $entity->get('description')->value ? substr(strip_tags($entity->get('description')->value), 0, 100) : '-';
    $row['weight'] = $entity->get('weight')->value;
    $row['status'] = $entity->get('status')->value ? $this->t('Enabled') : $this->t('Disabled');
    $row['created'] = $this->dateFormatter->format($entity->get('created')->value, 'short');
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity): array {
    $operations = parent::getDefaultOperations($entity);
    /** @var \Drupal\myeventlane_tickets\Entity\TicketGroup $entity */
    $event_id = $entity->getEventId();

    // Update edit operation route.
    if (isset($operations['edit'])) {
      $operations['edit']['url'] = $entity->toUrl('edit-form', ['event' => $event_id]);
    }

    // Update delete operation route.
    if (isset($operations['delete'])) {
      $operations['delete']['url'] = $entity->toUrl('delete-form', ['event' => $event_id]);
    }

    return $operations;
  }

}
