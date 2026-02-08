<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Form handler for the Venue entity add/edit forms.
 */
class VenueForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    $form['#attributes']['class'][] = 'mel-form';
    $form['#attributes']['class'][] = 'mel-venue-form';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state): array {
    $actions = parent::actions($form, $form_state);

    $actions['submit']['#attributes']['class'][] = 'mel-btn';
    $actions['submit']['#attributes']['class'][] = 'mel-btn--primary';

    // Add cancel link - use entity collection as safe fallback.
    $cancel_url = $this->getCancelUrl();
    $actions['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $cancel_url,
      '#attributes' => [
        'class' => ['mel-btn', 'mel-btn--secondary'],
      ],
      '#weight' => 10,
    ];

    return $actions;
  }

  /**
   * Gets the cancel URL, with fallback for route availability.
   *
   * @return \Drupal\Core\Url
   *   The cancel URL.
   */
  protected function getCancelUrl(): Url {
    try {
      return Url::fromRoute('myeventlane_venue.vendor_venues');
    }
    catch (\Exception $e) {
      // Fallback to entity collection if vendor route not available.
      return Url::fromRoute('entity.myeventlane_venue.collection');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $entity = $this->entity;
    $status = parent::save($form, $form_state);

    if ($status === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('Venue "@name" has been created.', [
        '@name' => $entity->label(),
      ]));
    }
    else {
      $this->messenger()->addStatus($this->t('Venue "@name" has been updated.', [
        '@name' => $entity->label(),
      ]));
    }

    // Redirect with fallback for route availability.
    $form_state->setRedirectUrl($this->getCancelUrl());

    return $status;
  }

}
