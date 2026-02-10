<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_portal\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_escalations_portal\Form\EscalationReplyForm;
use Drupal\myeventlane_escalations_portal\Service\EscalationMailer;
use Drupal\myeventlane_escalations_portal\Service\EscalationPartyResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Customer-facing escalation controller.
 */
final class CustomerEscalationController extends ControllerBase {

  public function __construct(
    private readonly EscalationPartyResolver $partyResolver,
    private readonly EscalationMailer $mailer,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_escalations_portal.party_resolver'),
      $container->get('myeventlane_escalations_portal.mailer'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Lists the current customer's escalations.
   */
  public function list(): array {
    $uid = (int) $this->currentUser()->id();

    $ids = $this->entityTypeManager()->getStorage('escalation')
      ->getQuery()
      ->condition('user_id', $uid)
      ->sort('created', 'DESC')
      ->range(0, 50)
      ->accessCheck(FALSE)
      ->execute();

    $rows = [];
    if ($ids) {
      $escalations = $this->entityTypeManager()->getStorage('escalation')->loadMultiple($ids);
      foreach ($escalations as $escalation) {
        $rows[] = [
          'id' => $escalation->id(),
          'subject' => [
            'data' => [
              '#type' => 'link',
              '#title' => $escalation->get('subject')->value,
              '#url' => Url::fromRoute('myeventlane_escalations_portal.customer_view', ['escalation' => $escalation->id()]),
            ],
          ],
          'status' => $escalation->get('status')->value,
          'priority' => $escalation->get('priority')->value,
          'created' => $this->dateFormatter->format((int) $escalation->get('created')->value, 'short'),
        ];
      }
    }

    $build = [];
    $build['add_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Submit a new escalation'),
      '#url' => Url::fromRoute('myeventlane_escalations_portal.customer_add'),
      '#attributes' => ['class' => ['button', 'button--primary']],
      '#prefix' => '<div style="margin-bottom: 16px;">',
      '#suffix' => '</div>',
    ];

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('#'),
        $this->t('Subject'),
        $this->t('Status'),
        $this->t('Priority'),
        $this->t('Created'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('You have no support escalations yet.'),
    ];

    return $build;
  }

  /**
   * Views a single escalation with comment thread.
   */
  public function view(int $escalation): array {
    $entity = $this->entityTypeManager()->getStorage('escalation')->load($escalation);
    if (!$entity) {
      return ['#markup' => $this->t('Escalation not found.')];
    }

    $build = [];

    // Escalation details (NO internal notes).
    $build['details'] = [
      '#type' => 'container',
      '#attributes' => ['style' => 'margin-bottom: 24px; padding: 16px; border: 1px solid #e0e0e0; border-radius: 10px; background: #fafafa;'],
      'subject' => ['#markup' => '<h2>' . htmlspecialchars((string) $entity->get('subject')->value) . '</h2>'],
      'meta' => [
        '#markup' => '<div><strong>' . $this->t('Type:') . '</strong> ' . htmlspecialchars((string) $entity->get('type')->value)
          . ' &nbsp; <strong>' . $this->t('Status:') . '</strong> ' . htmlspecialchars((string) $entity->get('status')->value)
          . ' &nbsp; <strong>' . $this->t('Priority:') . '</strong> ' . htmlspecialchars((string) $entity->get('priority')->value)
          . '</div>',
      ],
      'description' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => '<p>' . nl2br(htmlspecialchars((string) $entity->get('description')->value)) . '</p>',
        '#attributes' => ['style' => 'margin-top: 12px;'],
      ],
    ];

    // Reopen button for resolved/closed.
    $status = (string) $entity->get('status')->value;
    if (in_array($status, ['resolved', 'closed'], TRUE)) {
      $build['reopen'] = [
        '#type' => 'link',
        '#title' => $this->t('Request to reopen'),
        '#url' => Url::fromRoute('myeventlane_escalations_portal.customer_reopen', ['escalation' => $escalation]),
        '#attributes' => ['class' => ['button', 'button--small']],
        '#prefix' => '<div style="margin-bottom: 16px;">',
        '#suffix' => '</div>',
      ];
    }

    // Comment thread.
    $build['thread'] = $this->buildCommentThread($escalation);

    // Reply form (only if not resolved/closed).
    if (!in_array($status, ['resolved', 'closed'], TRUE)) {
      if ($this->currentUser()->hasPermission('comment on own escalation')) {
        $build['reply_form'] = $this->formBuilder()->getForm(EscalationReplyForm::class, $entity);
      }
    }

    return $build;
  }

  /**
   * Title callback for the view route.
   */
  public function viewTitle(int $escalation): string {
    $entity = $this->entityTypeManager()->getStorage('escalation')->load($escalation);
    if (!$entity) {
      return (string) $this->t('Escalation');
    }
    return (string) $entity->get('subject')->value;
  }

  /**
   * Reopens a resolved/closed escalation (customer request).
   */
  public function reopen(int $escalation): RedirectResponse {
    $entity = $this->entityTypeManager()->getStorage('escalation')->load($escalation);
    if (!$entity) {
      $this->messenger()->addError($this->t('Escalation not found.'));
      return new RedirectResponse('/my/support/escalations');
    }

    $entity->set('status', 'in_progress');
    if ($entity->hasField('field_waiting_on')) {
      $entity->set('field_waiting_on', 'vendor');
    }
    $entity->save();

    $this->mailer->notifyVendorReopened($entity);
    $this->messenger()->addStatus($this->t('Your escalation has been reopened.'));

    return new RedirectResponse('/my/support/escalations/' . $escalation);
  }

  /**
   * Builds the comment thread render array.
   */
  private function buildCommentThread(int $escalation_id): array {
    $comment_storage = $this->entityTypeManager()->getStorage('comment');
    $cids = $comment_storage->getQuery()
      ->condition('entity_type', 'escalation')
      ->condition('entity_id', $escalation_id)
      ->condition('field_name', 'field_escalation_thread')
      ->sort('created', 'ASC')
      ->accessCheck(FALSE)
      ->execute();

    $items = [];
    if ($cids) {
      $comments = $comment_storage->loadMultiple($cids);
      foreach ($comments as $comment) {
        $author = $comment->getOwner();
        $author_name = $author ? $author->getDisplayName() : $this->t('Unknown');
        $body = (string) ($comment->get('comment_body')->value ?? '');
        $created = $this->dateFormatter->format((int) $comment->getCreatedTime(), 'medium');

        $items[] = [
          '#type' => 'container',
          '#attributes' => ['style' => 'padding: 12px; margin-bottom: 8px; border: 1px solid #eee; border-radius: 8px; background: #fff;'],
          'header' => [
            '#markup' => '<div style="font-size: 0.85em; color: #666; margin-bottom: 6px;"><strong>' . htmlspecialchars((string) $author_name) . '</strong> &middot; ' . $created . '</div>',
          ],
          'body' => [
            '#markup' => '<div>' . nl2br(htmlspecialchars($body)) . '</div>',
          ],
        ];
      }
    }

    return [
      '#type' => 'container',
      '#attributes' => ['style' => 'margin-bottom: 16px;'],
      'title' => ['#markup' => '<h3>' . $this->t('Conversation') . '</h3>'],
      'items' => $items ?: ['#markup' => '<p>' . $this->t('No messages yet.') . '</p>'],
    ];
  }

}
