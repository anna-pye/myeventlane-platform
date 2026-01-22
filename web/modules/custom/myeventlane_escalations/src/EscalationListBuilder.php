<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a list controller for the escalation entity type.
 */
final class EscalationListBuilder extends EntityListBuilder {

  /**
   * The date formatter service.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): self {
    $instance = parent::createInstance($container, $entity_type);
    $instance->dateFormatter = $container->get('date.formatter');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['id'] = $this->t('ID');
    $header['subject'] = $this->t('Subject');
    $header['type'] = $this->t('Type');
    $header['status'] = $this->t('Status');
    $header['priority'] = $this->t('Priority');
    $header['customer'] = $this->t('Customer');
    $header['created'] = $this->t('Created');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\myeventlane_escalations\Entity\EscalationInterface $entity */
    $row['id'] = $entity->id();
    $row['subject'] = $entity->toLink();
    $row['type'] = $entity->get('type')->value;
    $row['status'] = $entity->get('status')->value;
    $row['priority'] = $entity->get('priority')->value;
    $customer = $entity->getCustomer();
    $row['customer'] = $customer ? $customer->getDisplayName() : '-';
    $row['created'] = $this->dateFormatter->format($entity->getCreatedTime(), 'short');
    return $row + parent::buildRow($entity);
  }

}
