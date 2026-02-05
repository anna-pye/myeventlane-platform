<?php

declare(strict_types=1);

namespace Drupal\myeventlane_account\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_account\Entity\EventReview;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for creating and editing event reviews.
 */
class EventReviewForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    $form['rating']['#required'] = TRUE;
    if (isset($form['rating']['widget'][0]['value'])) {
      $form['rating']['widget'][0]['value']['#type'] = 'select';
      $form['rating']['widget'][0]['value']['#options'] = [
        1 => $this->t('1 star'),
        2 => $this->t('2 stars'),
        3 => $this->t('3 stars'),
        4 => $this->t('4 stars'),
        5 => $this->t('5 stars'),
      ];
    }

    if (isset($form['body']['widget'][0]['value'])) {
      $form['body']['widget'][0]['value']['#title'] = $this->t('Your review (optional)');
      $form['body']['widget'][0]['value']['#attributes']['placeholder'] = $this->t('Share your experience...');
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $entity = $this->entity;
    assert($entity instanceof EventReview);
    $status = parent::save($form, $form_state);

    $event = $entity->getEvent();
    $url = $event instanceof NodeInterface
      ? $event->toUrl()
      : Url::fromRoute('<front>');

    $this->messenger()->addStatus($this->t('Your review has been saved.'));
    $form_state->setRedirectUrl($url);

    return $status;
  }

}
