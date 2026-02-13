<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_portal\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_escalations_portal\Service\EscalationMailer;
use Drupal\myeventlane_escalations_portal\Service\EscalationPartyResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reply form that creates a comment and updates field_waiting_on.
 */
final class EscalationReplyForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly EscalationPartyResolver $partyResolver,
    private readonly EscalationMailer $mailer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('myeventlane_escalations_portal.party_resolver'),
      $container->get('myeventlane_escalations_portal.mailer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_escalations_portal_reply_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $escalation = NULL): array {
    if (!$escalation) {
      return $form;
    }

    $form['escalation_id'] = [
      '#type' => 'value',
      '#value' => (int) $escalation->id(),
    ];

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Your reply'),
      '#required' => TRUE,
      '#rows' => 4,
      '#attributes' => [
        'placeholder' => $this->t('Type your message here...'),
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send reply'),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $escalation_id = (int) $form_state->getValue('escalation_id');
    $message = (string) $form_state->getValue('message');

    $escalation = $this->entityTypeManager->getStorage('escalation')->load($escalation_id);
    if (!$escalation) {
      $this->messenger()->addError($this->t('Escalation not found.'));
      return;
    }

    // Create comment.
    $comment_storage = $this->entityTypeManager->getStorage('comment');
    /** @var \Drupal\comment\CommentInterface $comment */
    $comment = $comment_storage->create([
      'entity_type' => 'escalation',
      'entity_id' => $escalation_id,
      'field_name' => 'field_escalation_thread',
      'comment_type' => 'escalation_thread',
      'uid' => $this->currentUser->id(),
      'status' => 1,
      'comment_body' => [
        'value' => $message,
        'format' => 'plain_text',
      ],
    ]);
    $comment->save();

    // Determine party and update field_waiting_on.
    $party = $this->partyResolver->resolve($this->currentUser, $escalation);

    if ($escalation->hasField('field_waiting_on')) {
      if ($party === 'customer') {
        // Customer replied — next action is on vendor (if assigned) or staff.
        $escalation->set('field_waiting_on', $escalation->hasAssignedVendor() ? 'vendor' : 'staff');
      }
      elseif ($party === 'vendor') {
        $escalation->set('field_waiting_on', 'customer');
      }
      // Staff: no automatic change.
    }

    // If escalation is new or waiting, move to in_progress.
    $status = (string) $escalation->get('status')->value;
    if ($status === 'new') {
      $escalation->set('status', 'in_progress');
    }

    $escalation->save();

    // Send email to the other party.
    if ($party === 'customer') {
      $this->mailer->notifyVendorReply($escalation);
    }
    elseif ($party === 'vendor') {
      $this->mailer->notifyCustomerReply($escalation);
    }

    $this->messenger()->addStatus($this->t('Your reply has been sent.'));
  }

}
