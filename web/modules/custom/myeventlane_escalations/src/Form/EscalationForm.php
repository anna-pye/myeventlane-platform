<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form handler for the escalation entity forms.
 */
final class EscalationForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $entity = $this->getEntity();
    $message_arguments = ['%label' => $entity->toLink()->toString()];
    $logger_arguments = [
      '%label' => $entity->label(),
      'link' => $entity->toLink($this->t('View'))->toString(),
    ];

    switch ($result) {
      case SAVED_NEW:
        $this->messenger()->addStatus($this->t('New escalation %label has been created.', $message_arguments));
        $this->logger('myeventlane_escalations')->notice('Created new escalation %label', $logger_arguments);
        break;

      case SAVED_UPDATED:
        $this->messenger()->addStatus($this->t('The escalation %label has been updated.', $message_arguments));
        $this->logger('myeventlane_escalations')->notice('Updated escalation %label', $logger_arguments);
        break;

      default:
        throw new \RuntimeException('Could not save the entity.');
    }

    $form_state->setRedirect('entity.escalation.collection');
    return $result;
  }

}
