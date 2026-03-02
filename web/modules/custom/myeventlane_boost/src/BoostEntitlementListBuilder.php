<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * List builder for Boost entitlement entities.
 */
final class BoostEntitlementListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['id'] = $this->t('ID');
    $header['vendor'] = $this->t('Vendor');
    $header['event'] = $this->t('Event');
    $header['order_id'] = $this->t('Order');
    $header['order_item_id'] = $this->t('Order item');
    $header['status'] = $this->t('Status');
    $header['starts'] = $this->t('Starts');
    $header['ends'] = $this->t('Ends');
    $header['amount_paid'] = $this->t('Amount paid');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    $row['id'] = (string) $entity->id();
    $row['vendor'] = $entity->get('uid')->entity?->label() ?? '-';
    $row['event'] = $entity->get('event')->entity?->label() ?? '-';
    $row['order_id'] = (string) ($entity->get('order_id')->target_id ?? '-');
    $row['order_item_id'] = (string) ($entity->get('order_item_id')->target_id ?? '-');
    $row['status'] = (string) ($entity->get('status')->value ?? '-');
    $row['starts'] = !empty($entity->get('starts')->value) ? gmdate('Y-m-d H:i:s', (int) $entity->get('starts')->value) : '-';
    $row['ends'] = !empty($entity->get('ends')->value) ? gmdate('Y-m-d H:i:s', (int) $entity->get('ends')->value) : '-';
    $amount = $entity->get('amount_paid')->first();
    if ($amount) {
      $amount_value = $amount->toPrice();
      $row['amount_paid'] = $amount_value->getNumber() . ' ' . $amount_value->getCurrencyCode();
    }
    else {
      $row['amount_paid'] = '-';
    }

    return $row + parent::buildRow($entity);
  }

}
