<?php

namespace Drupal\myeventlane_rsvp\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for cancelling an RSVP.
 */
class RsvpCancelConfirmForm extends ConfirmFormBase {

  /**
   * The submission entity ID.
   *
   * @var int
   */
  protected int $submissionId;

  /**
   * The RSVP storage handler.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $storage;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->storage = $container->get('entity_type.manager')->getStorage('rsvp_submission');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_rsvp_cancel_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    return 'Are you sure you want to cancel this RSVP?';
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('<front>');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): string {
    return 'Cancel RSVP';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $rsvp_id = NULL): array {
    $this->submissionId = (int) $rsvp_id;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($entity = $this->storage->load($this->submissionId)) {
      $entity->delete();
      $this->messenger()->addStatus('Your RSVP has been cancelled.');
    }
    $form_state->setRedirect('<front>');
  }

}