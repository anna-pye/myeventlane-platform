<?php

declare(strict_types=1);

namespace Drupal\mel_ticket\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Standalone add/edit form for ticket types (admin UI or deep links).
 */
final class TicketTypeForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $status = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('The ticket type %label was saved.', ['%label' => $this->entity->label()]));
    $form_state->setRedirect('entity.mel_ticket_type.canonical', ['mel_ticket_type' => $this->entity->id()]);
    return $status;
  }

}
