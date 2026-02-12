<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_portal\Service;

use Drupal\comment\CommentInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_escalations\Entity\EscalationInterface;
use Psr\Log\LoggerInterface;

/**
 * Renders escalation comment thread with consistent BEM markup.
 *
 * Groups consecutive comments by role (customer, vendor, staff). Used by
 * vendor, customer, and admin escalation views.
 */
final class EscalationThreadRenderer {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly EscalationPartyResolver $partyResolver,
    private readonly ?LoggerInterface $logger = NULL,
  ) {}

  /**
   * Renders the comment thread for an escalation.
   *
   * Comments are grouped by consecutive role. Each group shows a single
   * header (chip + timestamp of first message) and multiple message bubbles.
   *
   * @param \Drupal\myeventlane_escalations\Entity\EscalationInterface $escalation
   *   The escalation entity.
   *
   * @return array
   *   Render array: divider + thread container with message groups.
   */
  public function renderThread(EscalationInterface $escalation): array {
    $comment_storage = $this->entityTypeManager->getStorage('comment');
    $cids = $comment_storage->getQuery()
      ->condition('entity_type', 'escalation')
      ->condition('entity_id', (int) $escalation->id())
      ->condition('field_name', 'field_escalation_thread')
      ->sort('created', 'ASC')
      ->accessCheck(FALSE)
      ->execute();

    $groups = [];
    if ($cids) {
      $comments = $comment_storage->loadMultiple($cids);
      $current_group = null;

      foreach ($comments as $comment) {
        if (!$comment instanceof CommentInterface) {
          continue;
        }
        $role = $this->resolveCommentAuthorRole($comment, $escalation);

        if ($current_group !== null && $current_group['role'] === $role) {
          $current_group['comments'][] = $comment;
        }
        else {
          if ($current_group !== null) {
            $groups[] = $this->buildMessageGroup($current_group['role'], $current_group['comments'], $escalation);
          }
          $current_group = ['role' => $role, 'comments' => [$comment]];
        }
      }
      if ($current_group !== null) {
        $groups[] = $this->buildMessageGroup($current_group['role'], $current_group['comments'], $escalation);
      }
    }

    $thread = [
      '#type' => 'container',
      'divider' => [
        '#markup' => '<div class="mel-thread-divider">' . htmlspecialchars((string) t('Conversation')) . '</div>',
      ],
      'thread' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-escalation-thread']],
      ],
    ];

    if ($groups) {
      foreach ($groups as $i => $group) {
        $thread['thread'][$i] = $group;
      }
    }
    else {
      $thread['thread']['empty'] = [
        '#markup' => '<p class="mel-escalation-thread__empty">' . htmlspecialchars((string) t('No messages yet.')) . '</p>',
      ];
    }

    return $thread;
  }

  /**
   * Builds a message group (consecutive comments from same role).
   *
   * @param string $role
   *   customer|vendor|staff.
   * @param \Drupal\comment\CommentInterface[] $comments
   *   Comments in this group (chronological order).
   *
   * @return array
   *   Render array for the group.
   */
  private function buildMessageGroup(string $role, array $comments, EscalationInterface $escalation): array {
    $label = $this->getRoleLabel($role);
    $first_created = (int) $comments[0]->getCreatedTime();
    $created_formatted = $this->dateFormatter->format($first_created, 'medium');

    $items = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-message-group__items']],
    ];
    foreach ($comments as $i => $comment) {
      $body = (string) ($comment->get('comment_body')->value ?? '');
      $items[$i] = [
        '#markup' => '<div class="mel-message-bubble">' . nl2br(htmlspecialchars($body)) . '</div>',
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'mel-message-group',
          'mel-message-group--' . $role,
        ],
      ],
      'header' => [
        '#markup' => '<div class="mel-message-group__header">' .
          '<span class="mel-chip mel-chip--' . $role . '">' . htmlspecialchars($label) . '</span> ' .
          '<span class="mel-meta-time">' . htmlspecialchars($created_formatted) . '</span>' .
          '</div>',
      ],
      'items' => $items,
    ];
  }

  /**
   * Resolves the role of the comment author (customer, vendor, or staff).
   *
   * Presentation only. Never exposes identity.
   */
  private function resolveCommentAuthorRole(CommentInterface $comment, EscalationInterface $escalation): string {
    $author = $comment->getOwner();
    if (!$author || $author->isAnonymous()) {
      return 'staff';
    }

    $role = $this->partyResolver->resolve($author, $escalation);
    return in_array($role, ['customer', 'vendor'], TRUE) ? $role : 'staff';
  }

  /**
   * Returns the display label for a role. Staff always shown as "MEL Support".
   */
  private function getRoleLabel(string $role): string {
    return match ($role) {
      'customer' => (string) t('Customer'),
      'vendor' => (string) t('Vendor'),
      default => (string) t('MEL Support'),
    };
  }

}
