<?php

namespace Drupal\myeventlane_rsvp\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Confirmation form for cancelling an RSVP.
 *
 * Access is enforced by RsvpCancelAccess. This form applies state transition
 * (status -> cancelled) and returns 403 if the RSVP is missing or already cancelled.
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
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected QueueFactory $queueFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->storage = $container->get('entity_type.manager')->getStorage('rsvp_submission');
    $instance->queueFactory = $container->get('queue');
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
    $entity = $this->storage->load($this->submissionId);
    if (!$entity) {
      throw new AccessDeniedHttpException('RSVP not found.');
    }
    // Already cancelled – deny to avoid duplicate transitions.
    if ($entity->get('status')->value === 'cancelled') {
      throw new AccessDeniedHttpException('This RSVP has already been cancelled.');
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $entity = $this->storage->load($this->submissionId);
    if ($entity && $entity->get('status')->value !== 'cancelled') {
      $entity->set('status', 'cancelled');
      $entity->save();
      // Notify vendor digest.
      $this->queueFactory->get('mel_rsvp_vendor_digest')->createItem([
        'action' => 'rsvp_cancelled',
        'id' => $entity->id(),
      ]);
      $this->messenger()->addStatus('Your RSVP has been cancelled.');
    }
    $form_state->setRedirect('<front>');
  }

}
