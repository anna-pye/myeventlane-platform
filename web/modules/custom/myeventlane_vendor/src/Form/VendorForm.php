<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form handler for the Vendor entity forms.
 */
class VendorForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $entity = $this->getEntity();
    $currentUser = $this->currentUser();

    // ENFORCE 1:1 RELATIONSHIP: Prevent duplicate Vendor creation at form level.
    // This validation runs before preSave(), providing early feedback.
    if ($entity->isNew()) {
      $ownerId = $entity->getOwnerId();
      
      // If owner is not set, it will be set in preSave() to current user.
      // Check for current user's existing vendor.
      if ($ownerId === NULL) {
        $ownerId = (int) $currentUser->id();
      }

      if ($ownerId > 0) {
        $storage = $this->entityTypeManager()->getStorage('myeventlane_vendor');
        $existingVendorIds = $storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('uid', $ownerId)
          ->execute();

        if (!empty($existingVendorIds)) {
          $form_state->setError($form, $this->t('A vendor entity already exists for this user. Each user can only have one vendor entity.'));
          $this->logger('myeventlane_vendor')->warning('Form validation blocked duplicate Vendor creation for user @uid', [
            '@uid' => $ownerId,
          ]);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $entity = $this->getEntity();

    // Group fields into logical sections.
    $form['basic_info'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Basic information'),
      '#weight' => -20,
    ];

    // Move name field into basic_info section.
    if (isset($form['name'])) {
      $form['name']['#group'] = 'basic_info';
    }

    // Move logo into basic_info section.
    if (isset($form['field_vendor_logo'])) {
      $form['field_vendor_logo']['#group'] = 'basic_info';
    }

    // Move bio/description into basic_info section.
    if (isset($form['field_vendor_bio'])) {
      $form['field_vendor_bio']['#group'] = 'basic_info';
    }
    if (isset($form['field_description'])) {
      $form['field_description']['#group'] = 'basic_info';
    }

    // Contact and visibility section.
    $form['contact_visibility'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Contact details and visibility'),
      '#weight' => -10,
    ];

    // Contact information fields.
    if (isset($form['field_email'])) {
      $form['field_email']['#group'] = 'contact_visibility';
    }
    if (isset($form['field_phone'])) {
      $form['field_phone']['#group'] = 'contact_visibility';
    }
    if (isset($form['field_website'])) {
      $form['field_website']['#group'] = 'contact_visibility';
    }
    if (isset($form['field_address'])) {
      $form['field_address']['#group'] = 'contact_visibility';
    }
    if (isset($form['field_social_links'])) {
      $form['field_social_links']['#group'] = 'contact_visibility';
    }

    // Visibility toggles.
    if (isset($form['field_public_show_email'])) {
      $form['field_public_show_email']['#group'] = 'contact_visibility';
    }
    if (isset($form['field_public_show_phone'])) {
      $form['field_public_show_phone']['#group'] = 'contact_visibility';
    }
    if (isset($form['field_public_show_location'])) {
      $form['field_public_show_location']['#group'] = 'contact_visibility';
    }

    // Users section (hide during onboarding, but group if visible).
    if (isset($form['field_vendor_users'])) {
      $form['field_vendor_users']['#group'] = 'contact_visibility';
    }

    // Store and Stripe section (admin-only).
    $account = $this->currentUser();
    if ($account->hasPermission('administer myeventlane vendor')) {
      $form['store_stripe'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Store and Stripe integration'),
        '#weight' => 10,
        '#collapsible' => TRUE,
        '#collapsed' => $entity->isNew(),
      ];

      if (isset($form['field_vendor_store'])) {
        $form['field_vendor_store']['#group'] = 'store_stripe';
      }
    }

    return $form;
  }

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
        $this->messenger()->addStatus($this->t('New vendor %label has been created.', $message_arguments));
        $this->logger('myeventlane_vendor')->notice('Created new vendor %label', $logger_arguments);
        $form_state->setRedirect('entity.myeventlane_vendor.canonical', ['myeventlane_vendor' => $entity->id()]);
        break;

      case SAVED_UPDATED:
        $this->messenger()->addStatus($this->t('The vendor %label has been updated.', $message_arguments));
        $this->logger('myeventlane_vendor')->notice('Updated vendor %label.', $logger_arguments);
        $form_state->setRedirect('entity.myeventlane_vendor.canonical', ['myeventlane_vendor' => $entity->id()]);
        break;

      default:
        $form_state->setRedirect('entity.myeventlane_vendor.collection');
    }

    return $result;
  }

}
