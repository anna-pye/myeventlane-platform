<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Delete form for Access Code entities.
 *
 * Overrides the cancel URL to redirect back to the event access codes list.
 */
final class AccessCodeDeleteForm extends ContentEntityDeleteForm {

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    /** @var \Drupal\myeventlane_tickets\Entity\AccessCode $entity */
    $entity = $this->getEntity();
    $event_id = $entity->getEventId();

    return Url::fromRoute('myeventlane_tickets.event_tickets_access_codes', [
      'event' => $event_id,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);

    /** @var \Drupal\myeventlane_tickets\Entity\AccessCode $entity */
    $entity = $this->getEntity();
    $event_id = $entity->getEventId();

    $form_state->setRedirect('myeventlane_tickets.event_tickets_access_codes', [
      'event' => $event_id,
    ]);
  }

}
