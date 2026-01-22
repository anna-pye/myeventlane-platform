<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\flag\FlagServiceInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the My Categories page.
 */
final class MyCategoriesController extends ControllerBase {

  /**
   * Constructs a MyCategoriesController.
   */
  public function __construct(
    private readonly FlagServiceInterface $flagService,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('flag'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Renders the My Categories page.
   *
   * @return array
   *   A render array.
   */
  public function myCategories(): array {
    $currentUser = $this->currentUser();
    $userId = (int) $currentUser->id();

    if ($userId === 0) {
      return [
        '#markup' => '<p>' . $this->t('Please log in to view your followed categories.') . '</p>',
      ];
    }

    // Get the follow_category flag.
    $flag = $this->flagService->getFlagById('follow_category');
    if (!$flag) {
      return [
        '#markup' => '<p>' . $this->t('Category following is not yet configured.') . '</p>',
      ];
    }

    // Get all flags for this user.
    $flaggingStorage = $this->entityTypeManager()->getStorage('flagging');
    $flaggingIds = $flaggingStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('flag_id', 'follow_category')
      ->condition('uid', $userId)
      ->execute();

    $followedCategories = [];
    if (!empty($flaggingIds)) {
      $flaggings = $flaggingStorage->loadMultiple($flaggingIds);
      foreach ($flaggings as $flagging) {
        $term = $flagging->getFlaggable();
        if ($term instanceof TermInterface) {
          $followedCategories[] = $term;
        }
      }
    }

    // Build category data with "new this week" events.
    $categoryData = [];
    $now = $this->time->getRequestTime();
    $weekAgo = $now - (7 * 24 * 60 * 60);

    foreach ($followedCategories as $category) {
      // Find events in this category created in the last week.
      $eventIds = $this->entityTypeManager()
        ->getStorage('node')
        ->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'event')
        ->condition('status', 1)
        ->condition('field_category', $category->id())
        ->condition('created', $weekAgo, '>=')
        ->condition('field_event_start', $now, '>=')
        ->sort('field_promoted', 'DESC')
        ->sort('field_event_start', 'ASC')
        ->range(0, 10)
        ->execute();

      $newEvents = [];
      if (!empty($eventIds)) {
        $events = $this->entityTypeManager()->getStorage('node')->loadMultiple($eventIds);
        foreach ($events as $event) {
          $startTime = NULL;
          if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
            try {
              $startTime = strtotime($event->get('field_event_start')->value);
            }
            catch (\Exception) {
              // Ignore date parsing errors.
            }
          }

          $newEvents[] = [
            'id' => $event->id(),
            'title' => $event->label(),
            'url' => $event->toUrl()->toString(),
            'start_date' => $startTime ? date('M j, Y', $startTime) : '',
            'start_time' => $startTime ? date('g:ia', $startTime) : '',
            'venue' => $event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty()
              ? $event->get('field_venue_name')->value
              : '',
            'ics_url' => Url::fromRoute('myeventlane_rsvp.ics_download', ['node' => $event->id()])->toString(),
            'is_boosted' => $event->hasField('field_promoted') && (bool) $event->get('field_promoted')->value,
          ];
        }
      }

      $categoryData[] = [
        'term' => $category,
        'id' => $category->id(),
        'name' => $category->label(),
        'url' => $category->toUrl()->toString(),
        'unfollow_url' => Url::fromRoute('flag.action_link_unflag_nojs', [
          'flag' => 'follow_category',
          'entity_id' => $category->id(),
          'view_mode' => 'default',
        ])->toString(),
        'new_events' => $newEvents,
        'new_events_count' => count($newEvents),
      ];
    }

    return [
      '#theme' => 'myeventlane_my_categories',
      '#followed_categories' => $categoryData,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['taxonomy_term_list', 'node_list'],
        'max-age' => 300,
      ],
    ];
  }

}
