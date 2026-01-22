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
 * List builder for Purchase Surface entities.
 */
final class PurchaseSurfaceListBuilder extends EntityListBuilder {

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
   * Constructs PurchaseSurfaceListBuilder.
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
      ->sort('created', 'DESC');

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
    $header['label'] = $this->t('Label');
    $header['surface_type'] = $this->t('Type');
    $header['embed_token'] = $this->t('Embed Token');
    $header['status'] = $this->t('Status');
    $header['created'] = $this->t('Created');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\mel_tickets\Entity\PurchaseSurface $entity */
    $row['label'] = Link::createFromRoute(
      $entity->getLabel(),
      'mel_tickets.event_tickets_widgets_edit',
      [
        'event' => $entity->getEventId(),
        'mel_purchase_surface' => $entity->id(),
      ]
    );
    $row['surface_type'] = $entity->get('surface_type')->value;
    $row['embed_token'] = $entity->getEmbedToken();
    $row['status'] = $entity->get('status')->value ? $this->t('Active') : $this->t('Inactive');
    $row['created'] = $this->dateFormatter->format($entity->get('created')->value, 'short');
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity): array {
    $operations = parent::getDefaultOperations($entity);
    /** @var \Drupal\mel_tickets\Entity\PurchaseSurface $entity */
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
