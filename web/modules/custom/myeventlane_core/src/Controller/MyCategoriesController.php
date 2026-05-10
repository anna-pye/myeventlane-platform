<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Url;
use Drupal\flag\FlagServiceInterface;
use Drupal\image\ImageStyleInterface;
use Drupal\myeventlane_boost\BoostManager;
use Drupal\myeventlane_core\GovernedOperationalTemplates;
use Drupal\myeventlane_core\Utility\UpcomingEventEntityQueryHelper;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

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
    private readonly BoostManager $boostManager,
    private readonly GovernedOperationalTemplates $operationalTemplates,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('flag'),
      $container->get('datetime.time'),
      $container->get('myeventlane_boost.manager'),
      $container->get('myeventlane_surface.governed_operational_templates'),
      $container->get('file_url_generator'),
    );
  }

  /**
   * Renders the My Categories page.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   A render array or redirect when unauthenticated (route normally blocks guests).
   */
  public function myCategories(): array|RedirectResponse {
    $currentUser = $this->currentUser();
    $userId = (int) $currentUser->id();

    if ($userId === 0) {
      return new RedirectResponse(
        Url::fromRoute('user.login', [], [
          'query' => ['destination' => '/my-categories'],
        ])->toString(),
        302,
      );
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
      $categoryQuery = $this->entityTypeManager()
        ->getStorage('node')
        ->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'event')
        ->condition('status', 1)
        ->condition('field_category', $category->id())
        ->condition('created', $weekAgo, '>=');
      UpcomingEventEntityQueryHelper::addStartOrEndInFutureOrOngoing($categoryQuery, (int) $now);
      $eventIds = $categoryQuery
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

          if (!$event instanceof NodeInterface) {
            continue;
          }
          $newEvents[] = [
            'id' => $event->id(),
            'title' => $event->label(),
            'url' => $event->toUrl()->toString(),
            'thumbnail_url' => $this->eventThumbnailUrl($event),
            'start_date' => $startTime ? date('M j, Y', $startTime) : '',
            'start_time' => $startTime ? date('g:ia', $startTime) : '',
            'venue' => $event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty()
              ? $event->get('field_venue_name')->value
              : '',
            'ics_url' => Url::fromRoute('myeventlane_rsvp.ics_download', ['node' => $event->id()])->toString(),
            'is_boosted' => $this->boostManager->isBoosted($event),
          ];
        }
      }

      $categoryData[] = [
        'term' => $category,
        'id' => $category->id(),
        'name' => $category->label(),
        'url' => $category->toUrl()->toString(),
        'follow_cta' => $this->categoryFollowFlagLink($category),
        'new_events' => $newEvents,
        'new_events_count' => count($newEvents),
      ];
    }

    return [
      '#theme' => 'myeventlane_my_categories',
      '#followed_categories' => $categoryData,
      '#mel_category_follow_empty' => $categoryData === []
        ? $this->operationalTemplates->customerCategoriesFollowEmpty()
        : NULL,
      '#mel_category_follow_section_no_new_events' => $this->operationalTemplates->customerCategoriesNoNewEventsThisWeek(),
      '#attached' => [
        'library' => [
          'flag/link_ajax',
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['taxonomy_term_list', 'node_list', 'flag.flag.follow_category'],
        'max-age' => 300,
      ],
    ];
  }

  /**
   * Small listing thumbnail for account category rows (image style when available).
   */
  private function eventThumbnailUrl(NodeInterface $event): string {
    if (!$event->hasField('field_event_image') || $event->get('field_event_image')->isEmpty()) {
      return '';
    }
    $file = $event->get('field_event_image')->entity;
    if ($file === NULL || $file->getFileUri() === '') {
      return '';
    }
    $uri = $file->getFileUri();
    $style = $this->entityTypeManager()->getStorage('image_style')->load('thumbnail');
    if ($style instanceof ImageStyleInterface) {
      return $style->buildUrl($uri);
    }

    return $this->fileUrlGenerator->generateAbsoluteString($uri);
  }

  /**
   * Builds the canonical Flag AJAX link for an already-followed category.
   *
   * The shared theme component owns the MEL CTA chrome; Flag owns state,
   * persistence, access checks, and AJAX token handling.
   */
  private function categoryFollowFlagLink(TermInterface $term): ?array {
    if ($term->bundle() !== 'categories') {
      return NULL;
    }

    $flag = $this->flagService->getFlagById('follow_category');
    if ($flag === NULL) {
      return NULL;
    }

    return [
      '#lazy_builder' => [
        'flag.link_builder:build',
        [
          'taxonomy_term',
          (string) $term->id(),
          'follow_category',
          'default',
        ],
      ],
      '#create_placeholder' => TRUE,
    ];
  }

}
