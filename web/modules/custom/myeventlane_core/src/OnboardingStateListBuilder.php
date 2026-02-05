<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\myeventlane_core\Entity\OnboardingStateInterface;

/**
 * List builder for onboarding state entities.
 */
class OnboardingStateListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['id'] = $this->t('ID');
    $header['uid'] = $this->t('Owner');
    $header['track'] = $this->t('Track');
    $header['stage'] = $this->t('Stage');
    $header['completed'] = $this->t('Completed');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof OnboardingStateInterface);
    $row['id'] = $entity->id();
    $row['uid'] = $entity->get('uid')->target_id;
    $row['track'] = $entity->getTrack();
    $row['stage'] = $entity->getStage();
    $row['completed'] = $entity->isCompleted() ? $this->t('Yes') : $this->t('No');
    return $row + parent::buildRow($entity);
  }

}
