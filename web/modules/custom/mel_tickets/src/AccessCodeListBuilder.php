<?php

declare(strict_types=1);

namespace Drupal\mel_tickets;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * List builder for Access Code entities.
 */
final class AccessCodeListBuilder extends EntityListBuilder {

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
   * Constructs AccessCodeListBuilder.
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
      ->sort('code', 'ASC');

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
    $header['code'] = $this->t('Code');
    $header['status'] = $this->t('Status');
    $header['usage'] = $this->t('Usage');
    $header['expires'] = $this->t('Expires');
    $header['created'] = $this->t('Created');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\mel_tickets\Entity\AccessCode $entity */
    $row['code'] = Link::createFromRoute(
      $entity->getCode(),
      'mel_tickets.event_tickets_access_codes_edit',
      [
        'event' => $entity->getEventId(),
        'mel_access_code' => $entity->id(),
      ]
    );
    $row['status'] = $entity->get('status')->value;

    $usage_count = (int) $entity->get('usage_count')->value;
    $usage_limit = $entity->get('usage_limit')->isEmpty() ? NULL : (int) $entity->get('usage_limit')->value;
    if ($usage_limit !== NULL) {
      $row['usage'] = $this->t('@count / @limit', ['@count' => $usage_count, '@limit' => $usage_limit]);
    }
    else {
      $row['usage'] = $this->t('@count (unlimited)', ['@count' => $usage_count]);
    }

    $row['expires'] = $entity->get('expires')->isEmpty()
      ? $this->t('Never')
      : $this->dateFormatter->format(strtotime($entity->get('expires')->value), 'short');

    $row['created'] = $this->dateFormatter->format($entity->get('created')->value, 'short');
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity): array {
    $operations = parent::getDefaultOperations($entity);
    /** @var \Drupal\mel_tickets\Entity\AccessCode $entity */
    $event_id = $entity->getEventId();

    if (isset($operations['edit'])) {
      $operations['edit']['url'] = $entity->toUrl('edit-form', ['event' => $event_id]);
    }

    if (isset($operations['delete'])) {
      $operations['delete']['url'] = $entity->toUrl('delete-form', ['event' => $event_id]);
    }

    return $operations;
  }

}
