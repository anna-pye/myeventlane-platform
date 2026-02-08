<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_venue\Entity\Venue;

/**
 * Form handler for the VenueLocation entity add/edit forms.
 */
class VenueLocationForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    $form['#attributes']['class'][] = 'mel-form';
    $form['#attributes']['class'][] = 'mel-venue-location-form';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state): array {
    $actions = parent::actions($form, $form_state);

    $actions['submit']['#attributes']['class'][] = 'mel-btn';
    $actions['submit']['#attributes']['class'][] = 'mel-btn--primary';

    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $entity = $this->entity;
    $status = parent::save($form, $form_state);

    if ($status === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('Location "@name" has been added.', [
        '@name' => $entity->label(),
      ]));
    }
    else {
      $this->messenger()->addStatus($this->t('Location "@name" has been updated.', [
        '@name' => $entity->label(),
      ]));
    }

    // Redirect back to venue.
    $venue = $entity->getVenue();
    if ($venue instanceof Venue) {
      $form_state->setRedirectUrl(Url::fromRoute('myeventlane_venue.vendor_venue_edit', [
        'myeventlane_venue' => $venue->id(),
      ]));
    }

    return $status;
  }

}
