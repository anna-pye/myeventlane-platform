<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Keeps ticket-group deletion inside the selected event workspace.
 */
final class TicketGroupDeleteForm extends ContentEntityDeleteForm {

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    /** @var \Drupal\myeventlane_tickets\Entity\TicketGroup $entity */
    $entity = $this->getEntity();
    return Url::fromRoute('myeventlane_tickets.event_tickets_groups', [
      'event' => $entity->getEventId(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\myeventlane_tickets\Entity\TicketGroup $entity */
    $entity = $this->getEntity();
    $event_id = $entity->getEventId();
    parent::submitForm($form, $form_state);
    $form_state->setRedirect('myeventlane_tickets.event_tickets_groups', [
      'event' => $event_id,
    ]);
  }

}
