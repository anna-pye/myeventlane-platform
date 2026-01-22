<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a form for deleting Venue entities.
 *
 * Prevents deletion if the venue is referenced by any Event.
 */
class VenueDeleteForm extends ContentEntityDeleteForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);

    $venue = $this->getEntity();
    /** @var \Drupal\myeventlane_venue\VenueStorage $storage */
    $storage = $this->entityTypeManager->getStorage('myeventlane_venue');

    // Check if venue is referenced by any events.
    if ($storage->venueIsReferenced((int) $venue->id())) {
      // Disable the delete button and show a message.
      $form['description']['#markup'] = $this->t(
        'This venue cannot be deleted because it is currently being used by one or more events. Please remove all references to this venue from events before deleting it.'
      );

      if (isset($form['actions']['delete'])) {
        $form['actions']['delete']['#access'] = FALSE;
      }

      // Add a cancel button.
      $form['actions']['cancel'] = [
        '#type' => 'link',
        '#title' => $this->t('Cancel'),
        '#url' => $venue->toUrl('canonical'),
        '#attributes' => [
          'class' => ['button'],
        ],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $venue = $this->getEntity();
    /** @var \Drupal\myeventlane_venue\VenueStorage $storage */
    $storage = $this->entityTypeManager->getStorage('myeventlane_venue');

    // Double-check before deletion.
    if ($storage->venueIsReferenced((int) $venue->id())) {
      $this->messenger()->addError($this->t(
        'Cannot delete venue %name because it is referenced by one or more events.',
        ['%name' => $venue->label()]
      ));
      $form_state->setRedirect('entity.myeventlane_venue.canonical', ['myeventlane_venue' => $venue->id()]);
      return;
    }

    parent::submitForm($form, $form_state);
  }

}
