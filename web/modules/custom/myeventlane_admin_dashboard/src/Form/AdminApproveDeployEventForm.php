<?php

declare(strict_types=1);

namespace Drupal\myeventlane_admin_dashboard\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_messaging\Service\MessagingManager;
use Drupal\myeventlane_notifications\Service\BusinessNotificationTriggerService;
use Drupal\node\NodeInterface;
use Drupal\workspaces\WorkspaceManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms and executes approval for an event in admin review.
 */
final class AdminApproveDeployEventForm extends FormBase {

  /**
   * Constructs the form.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly WorkspaceManagerInterface $workspaceManager,
    private readonly MessagingManager $messagingManager,
    private readonly LoggerInterface $logger,
    private readonly ?BusinessNotificationTriggerService $businessNotificationTrigger = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('workspaces.manager'),
      $container->get('myeventlane_messaging.manager'),
      $container->get('logger.channel.myeventlane_admin_dashboard'),
      $container->has('myeventlane_notifications.business_trigger')
        ? $container->get('myeventlane_notifications.business_trigger')
        : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_admin_approve_deploy_event_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if (!$node instanceof NodeInterface || $node->bundle() !== 'event') {
      $form['message'] = ['#markup' => '<p>Event not found.</p>'];
      return $form;
    }

    $state = 'unmoderated';
    if ($node->hasField('moderation_state') && !$node->get('moderation_state')->isEmpty()) {
      $state = (string) $node->get('moderation_state')->value;
    }

    $form['event_id'] = [
      '#type' => 'hidden',
      '#value' => (int) $node->id(),
    ];

    $form['summary'] = [
      '#markup' => '<p><strong>Event:</strong> ' . $node->label() . '<br><strong>Current moderation state:</strong> ' . $state . '</p><p>This action sets moderation to <strong>published</strong> in <strong>admin_review</strong>. Deployment to live is handled separately.</p>',
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Approve'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromUri('internal:/admin/myeventlane/review'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event_id = (int) $form_state->getValue('event_id', 0);
    if ($event_id <= 0) {
      $this->messenger()->addError($this->t('Invalid event ID.'));
      return;
    }

    try {
      $this->workspaceManager->executeInWorkspace('admin_review', function () use ($event_id): void {
        $event = $this->entityTypeManager->getStorage('node')->load($event_id);
        if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
          throw new \RuntimeException('Event not found in admin_review workspace.');
        }
        if (!$event->hasField('moderation_state')) {
          throw new \RuntimeException('Event moderation_state field is not available.');
        }

        $event->setNewRevision(TRUE);
        $event->setRevisionUserId((int) $this->currentUser()->id());
        $event->setRevisionLogMessage('Approved from Admin Review.');
        $event->set('moderation_state', 'published');
        $event->save();
      });
      $this->logger->notice('Admin approved event in admin_review. uid=@uid nid=@nid', [
        '@uid' => (int) $this->currentUser()->id(),
        '@nid' => $event_id,
      ]);
      $this->queueVendorApprovalNotification($event_id);
      if ($this->businessNotificationTrigger !== NULL) {
        $approvedEvent = $this->entityTypeManager->getStorage('node')->load($event_id);
        if ($approvedEvent instanceof NodeInterface) {
          $this->businessNotificationTrigger->onEventApproved($approvedEvent);
        }
      }
      $this->messenger()->addStatus($this->t('Event approved in Admin Review.'));
    }
    catch (\Throwable $exception) {
      $this->logger->error('Approve failed. uid=@uid nid=@nid message=@message', [
        '@uid' => (int) $this->currentUser()->id(),
        '@nid' => $event_id,
        '@message' => $exception->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Approve failed. Check logs for details.'));
    }

    $form_state->setRedirectUrl(Url::fromUri('internal:/admin/myeventlane/review'));
  }

  /**
   * Queues an approval notification to the event owner.
   */
  private function queueVendorApprovalNotification(int $eventId): void {
    $event = $this->entityTypeManager->getStorage('node')->load($eventId);
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      $this->logger->error('Vendor approval notification skipped. Event missing after approval. nid=@nid', [
        '@nid' => $eventId,
      ]);
      return;
    }

    $owner = $event->getOwner();
    $recipient = $owner ? trim((string) $owner->getEmail()) : '';
    if ($recipient === '') {
      $this->logger->error('Vendor approval notification skipped. Owner email missing. nid=@nid uid=@uid', [
        '@nid' => $eventId,
        '@uid' => $owner ? (int) $owner->id() : 0,
      ]);
      return;
    }

    $queueId = $this->messagingManager->queue('vendor_event_update', $recipient, [
      'event_id' => $eventId,
      'event_title' => $event->label(),
      'event_url' => Url::fromRoute('entity.node.canonical', ['node' => $eventId], ['absolute' => TRUE])->toString(),
      'custom_subject' => 'Your event has been approved',
      'message_body' => '<p>Your event has been approved by MyEventLane and is now ready for the next publishing step.</p>',
    ]);

    if ($queueId === NULL) {
      $this->logger->warning('Vendor approval notification queue skipped or failed. nid=@nid recipient=@recipient', [
        '@nid' => $eventId,
        '@recipient' => $recipient,
      ]);
      return;
    }

    $this->logger->notice('Vendor approval notification queued. nid=@nid recipient=@recipient message_id=@message_id', [
      '@nid' => $eventId,
      '@recipient' => $recipient,
      '@message_id' => $queueId,
    ]);
  }

}
