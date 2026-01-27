<?php

declare(strict_types=1);

namespace Drupal\myeventlane_questions\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form handler for VendorQuestion add/edit forms.
 */
class VendorQuestionForm extends ContentEntityForm {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->currentUser = $container->get('current_user');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\myeventlane_questions\Entity\VendorQuestionInterface $entity */
    $entity = $this->entity;

    // Auto-set store if not set and user has a vendor.
    if ($entity->isNew() && $entity->get('field_store')->isEmpty()) {
      $vendor_storage = $this->entityTypeManager->getStorage('myeventlane_vendor');
      $vendor_ids = $vendor_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $this->currentUser->id())
        ->range(0, 1)
        ->execute();

      if (!empty($vendor_ids)) {
        $vendor = $vendor_storage->load(reset($vendor_ids));
        if ($vendor && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
          $store = $vendor->get('field_vendor_store')->entity;
          if ($store) {
            $form['field_store']['widget'][0]['target_id']['#default_value'] = $store;
          }
        }
      }
    }

    // Hide store field from non-admins (auto-set based on vendor).
    if (!$this->currentUser->hasPermission('administer site configuration')) {
      $form['field_store']['#access'] = FALSE;
    }

    // Add AJAX to question type to show/hide options field.
    $form['field_question_type']['widget'][0]['value']['#ajax'] = [
      'callback' => '::ajaxRefresh',
      'wrapper' => 'question-options-wrapper',
      'event' => 'change',
    ];

    // Wrap options field for AJAX.
    $form['field_options']['#prefix'] = '<div id="question-options-wrapper">';
    $form['field_options']['#suffix'] = '</div>';

    // Show options field only for select/checkbox types.
    $question_type = $form_state->getValue(['field_question_type', 0, 'value'])
      ?? $entity->getQuestionType()
      ?? 'textfield';

    if (!in_array($question_type, ['select', 'checkbox'], TRUE)) {
      $form['field_options']['#access'] = FALSE;
    }

    return $form;
  }

  /**
   * AJAX callback to refresh options field visibility.
   */
  public function ajaxRefresh(array &$form, FormStateInterface $form_state): array {
    return $form['field_options'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $question_type = $form_state->getValue(['field_question_type', 0, 'value']);
    $options = $form_state->getValue(['field_options', 0, 'value']);

    // Require options for select/checkbox types.
    if (in_array($question_type, ['select', 'checkbox'], TRUE)) {
      if (empty(trim($options ?? ''))) {
        $form_state->setErrorByName('field_options', $this->t('Options are required for @type questions.', [
          '@type' => $question_type,
        ]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): void {
    $entity = $this->entity;
    $status = $entity->save();

    $message = $status === SAVED_NEW
      ? $this->t('Created question "%label".', ['%label' => $entity->label()])
      : $this->t('Updated question "%label".', ['%label' => $entity->label()]);

    $this->messenger()->addStatus($message);
    $form_state->setRedirect('myeventlane_questions.library');
  }

}
