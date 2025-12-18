<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\flag\FlagServiceInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\user\UserInterface;

/**
 * Service to generate and send weekly category digests to users.
 */
final class CategoryDigestGenerator {

  /**
   * Constructs a CategoryDigestGenerator.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MailManagerInterface $mailManager,
    private readonly RendererInterface $renderer,
    private readonly FlagServiceInterface $flagService,
  ) {}

  /**
   * Sends weekly digest to a user based on their followed categories.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user to send the digest to.
   */
  public function sendDigest(UserInterface $user): void {
    $userId = (int) $user->id();
    if ($userId === 0) {
      return;
    }

    // Get the follow_category flag.
    $flag = $this->flagService->getFlagById('follow_category');
    if (!$flag) {
      return;
    }

    // Get all categories this user follows.
    $flaggingStorage = $this->entityTypeManager->getStorage('flagging');
    $flaggingIds = $flaggingStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('flag_id', 'follow_category')
      ->condition('uid', $userId)
      ->execute();

    if (empty($flaggingIds)) {
      // User doesn't follow any categories, skip.
      return;
    }

    $flaggings = $flaggingStorage->loadMultiple($flaggingIds);
    $followedCategoryIds = [];
    foreach ($flaggings as $flagging) {
      $term = $flagging->getFlaggable();
      if ($term instanceof TermInterface) {
        $followedCategoryIds[] = $term->id();
      }
    }

    if (empty($followedCategoryIds)) {
      return;
    }

    // Find new events in followed categories from the last week.
    $now = \Drupal::time()->getRequestTime();
    $weekAgo = $now - (7 * 24 * 60 * 60);

    $eventIds = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->condition('field_category', $followedCategoryIds, 'IN')
      ->condition('created', $weekAgo, '>=')
      ->condition('field_event_start', $now, '>=')
      ->sort('field_promoted', 'DESC')
      ->sort('field_event_start', 'ASC')
      ->range(0, 50)
      ->execute();

    if (empty($eventIds)) {
      // No new events, skip digest.
      return;
    }

    $events = $this->entityTypeManager->getStorage('node')->loadMultiple($eventIds);

    // Group events by category.
    $eventsByCategory = [];
    foreach ($events as $event) {
      if (!$event->hasField('field_category') || $event->get('field_category')->isEmpty()) {
        continue;
      }

      $category = $event->get('field_category')->entity;
      if (!$category instanceof TermInterface) {
        continue;
      }

      $categoryId = $category->id();
      if (!in_array($categoryId, $followedCategoryIds, TRUE)) {
        continue;
      }

      if (!isset($eventsByCategory[$categoryId])) {
        $eventsByCategory[$categoryId] = [
          'category' => $category,
          'events' => [],
        ];
      }

      $startTime = NULL;
      if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
        try {
          $startTime = strtotime($event->get('field_event_start')->value);
        }
        catch (\Exception) {
          // Ignore date parsing errors.
        }
      }

      $venue = '';
      if ($event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty()) {
        $venue = $event->get('field_venue_name')->value;
      }

      $isBoosted = FALSE;
      if ($event->hasField('field_promoted') && (bool) $event->get('field_promoted')->value) {
        if ($event->hasField('field_promo_expires') && !$event->get('field_promo_expires')->isEmpty()) {
          try {
            $expires = strtotime($event->get('field_promo_expires')->value);
            $isBoosted = $expires > $now;
          }
          catch (\Exception) {
            // Ignore date parsing errors.
          }
        }
      }

      $eventsByCategory[$categoryId]['events'][] = [
        'id' => $event->id(),
        'title' => $event->label(),
        'url' => $event->toUrl('canonical', ['absolute' => TRUE])->toString(),
        'start_date' => $startTime ? date('M j, Y', $startTime) : '',
        'start_time' => $startTime ? date('g:ia', $startTime) : '',
        'venue' => $venue,
        'ics_url' => Url::fromRoute('myeventlane_rsvp.ics_download', ['node' => $event->id()], ['absolute' => TRUE])->toString(),
        'is_boosted' => $isBoosted,
      ];
    }

    if (empty($eventsByCategory)) {
      return;
    }

    // Render email template.
    $render = [
      '#theme' => 'myeventlane_category_digest_email',
      '#user' => $user,
      '#events_by_category' => $eventsByCategory,
    ];

    $body = $this->renderer->renderRoot($render);

    // Send email.
    $this->mailManager->mail(
      'myeventlane_core',
      'category_digest',
      $user->getEmail(),
      \Drupal::languageManager()->getDefaultLanguage()->getId(),
      [
        'subject' => t('Weekly Event Digest - New events in your categories'),
        'body' => $body,
      ]
    );
  }

  /**
   * Gets all users who follow at least one category.
   *
   * @return array
   *   Array of user IDs.
   */
  public function getUsersWithFollowedCategories(): array {
    $flaggingStorage = $this->entityTypeManager->getStorage('flagging');
    $flaggingIds = $flaggingStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('flag_id', 'follow_category')
      ->execute();

    if (empty($flaggingIds)) {
      return [];
    }

    $flaggings = $flaggingStorage->loadMultiple($flaggingIds);
    $userIds = [];
    foreach ($flaggings as $flagging) {
      $userId = (int) $flagging->getOwnerId();
      if ($userId > 0) {
        $userIds[$userId] = $userId;
      }
    }

    return array_values($userIds);
  }

}
