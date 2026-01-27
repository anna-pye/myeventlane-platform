<?php

declare(strict_types=1);

namespace Drupal\myeventlane_questions;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Provides a list controller for VendorQuestion entities.
 */
class VendorQuestionListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Question');
    $header['type'] = $this->t('Type');
    $header['required'] = $this->t('Required');
    $header['status'] = $this->t('Status');
    $header['created'] = $this->t('Created');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\myeventlane_questions\Entity\VendorQuestionInterface $entity */
    $row['label'] = Link::createFromRoute(
      $entity->label(),
      'entity.vendor_question.edit_form',
      ['vendor_question' => $entity->id()]
    );
    $row['type'] = $this->getQuestionTypeLabel($entity->getQuestionType());
    $row['required'] = $entity->isRequired() ? $this->t('Yes') : $this->t('No');
    $row['status'] = $entity->isEnabled() ? $this->t('Enabled') : $this->t('Disabled');
    $row['created'] = $this->dateFormatter->format($entity->getCreatedTime(), 'short');
    return $row + parent::buildRow($entity);
  }

  /**
   * Gets a human-readable label for question type.
   *
   * @param string $type
   *   The question type machine name.
   *
   * @return string
   *   The human-readable label.
   */
  private function getQuestionTypeLabel(string $type): string {
    $labels = [
      'textfield' => $this->t('Text field'),
      'textarea' => $this->t('Textarea'),
      'select' => $this->t('Select'),
      'checkbox' => $this->t('Checkbox'),
    ];
    return $labels[$type] ?? $type;
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = parent::render();
    $build['table']['#empty'] = $this->t('No questions found. @link to add one.', [
      '@link' => Link::createFromRoute($this->t('Click here'), 'myeventlane_questions.add')->toString(),
    ]);
    return $build;
  }

}
