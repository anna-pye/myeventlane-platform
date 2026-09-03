<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_location\Service\LocationProviderManager;
use Drupal\myeventlane_venue\Entity\Venue;
use Drupal\myeventlane_venue\Service\VenueAccessResolver;
use Drupal\myeventlane_venue\Service\VenueManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the public venue view.
 */
class VenueViewController extends ControllerBase {

  /**
   * The date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected VenueAccessResolver $accessResolver,
    protected VenueManager $venueManager,
    protected LocationProviderManager $locationProviderManager,
    DateFormatterInterface $date_formatter,
  ) {
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_venue.access_resolver'),
      $container->get('myeventlane_venue.manager'),
      $container->get('myeventlane_location.provider_manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Renders the venue view page.
   *
   * @param \Drupal\myeventlane_venue\Entity\Venue $myeventlane_venue
   *   The venue entity.
   *
   * @return array
   *   Render array.
   */
  public function view(Venue $myeventlane_venue): array {
    $image_file = $myeventlane_venue->getImageFile();
    $image_media = $myeventlane_venue->getImageMedia();
    $cache_tags = $myeventlane_venue->getCacheTags();
    if ($image_media !== NULL) {
      $cache_tags = Cache::mergeTags($cache_tags, $image_media->getCacheTags());
    }
    elseif ($image_file !== NULL) {
      $cache_tags = Cache::mergeTags($cache_tags, $image_file->getCacheTags());
    }
    $cache_tags = Cache::mergeTags($cache_tags, ['myeventlane_venue_location_list']);

    $locations = $this->getLocations($myeventlane_venue);
    $map = $this->getMapData($myeventlane_venue);
    $attached = [
      'library' => ['myeventlane_venue/venue_page'],
    ];

    if ($map['latitude'] !== NULL && $map['longitude'] !== NULL) {
      $attached['library'][] = 'myeventlane_location/event_map';
      $attached['drupalSettings']['myeventlaneLocation'] = $this->locationProviderManager->getFrontendSettings();
      $attached['drupalSettings']['myeventlaneLocationMap'] = [
        'latitude' => $map['latitude'],
        'longitude' => $map['longitude'],
        'title' => $myeventlane_venue->getName(),
        'address' => $map['address'],
      ];
    }

    $build = [
      '#theme' => 'myeventlane_venue_page',
      '#venue' => $myeventlane_venue,
      '#image_url' => $myeventlane_venue->getImageUrl('large') ?? $myeventlane_venue->getImageUrl(),
      '#image_alt' => $myeventlane_venue->getImageAlt(),
      '#can_edit' => $this->canEdit($myeventlane_venue),
      '#edit_url' => $this->getEditUrl($myeventlane_venue),
      '#locations' => $locations,
      '#map' => $map,
      '#events' => $this->getEvents($myeventlane_venue),
      '#cache' => [
        'tags' => $cache_tags,
        'contexts' => ['user', 'url'],
      ],
      '#attached' => $attached,
    ];

    return $build;
  }

  /**
   * Title callback.
   *
   * @param \Drupal\myeventlane_venue\Entity\Venue $myeventlane_venue
   *   The venue entity.
   *
   * @return string
   *   The page title.
   */
  public function title(Venue $myeventlane_venue): string {
    return $myeventlane_venue->getName();
  }

  /**
   * Access callback for the canonical venue page.
   *
   * Public venues are viewable. Non-public venues return 404 for unauthorized.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(RouteMatchInterface $route_match, AccountInterface $account): AccessResultInterface {
    $venue = $route_match->getParameter('myeventlane_venue');

    if (!$venue instanceof Venue) {
      return AccessResult::forbidden()->cachePerUser();
    }

    // Admin always has access.
    if ($account->hasPermission('administer myeventlane venues')) {
      return AccessResult::allowed()
        ->cachePerPermissions()
        ->addCacheableDependency($venue);
    }

    // Owner always has access.
    if ((int) $venue->getOwnerId() === (int) $account->id()) {
      return AccessResult::allowed()
        ->cachePerUser()
        ->addCacheableDependency($venue);
    }

    // Public venues are viewable.
    if ($venue->isPublic()) {
      return AccessResult::allowed()
        ->addCacheableDependency($venue);
    }

    // Shared venues: check explicit access.
    if ($this->accessResolver->hasExplicitAccess($venue, $account)) {
      return AccessResult::allowed()
        ->cachePerUser()
        ->addCacheableDependency($venue);
    }

    // 404 for unauthorized access to non-public venues.
    return AccessResult::forbidden('This venue is not publicly available.')
      ->cachePerUser()
      ->addCacheableDependency($venue);
  }

  /**
   * Checks if the current user can edit the venue.
   *
   * @param \Drupal\myeventlane_venue\Entity\Venue $venue
   *   The venue entity.
   *
   * @return bool
   *   TRUE if the user can edit.
   */
  protected function canEdit(Venue $venue): bool {
    $account = $this->currentUser();

    // Admin can always edit.
    if ($account->hasPermission('administer myeventlane venues')) {
      return TRUE;
    }

    // Owner can edit.
    if ((int) $venue->getOwnerId() === (int) $account->id()) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Gets the edit URL for the venue.
   *
   * @param \Drupal\myeventlane_venue\Entity\Venue $venue
   *   The venue entity.
   *
   * @return \Drupal\Core\Url|null
   *   The edit URL, or NULL.
   */
  protected function getEditUrl(Venue $venue): ?Url {
    $account = $this->currentUser();

    // Admin uses admin edit route.
    if ($account->hasPermission('administer myeventlane venues')) {
      return Url::fromRoute('entity.myeventlane_venue.edit_form', [
        'myeventlane_venue' => $venue->id(),
      ]);
    }

    // Owner uses vendor edit route.
    if ((int) $venue->getOwnerId() === (int) $account->id()) {
      return Url::fromRoute('myeventlane_venue.vendor_venue_edit', [
        'myeventlane_venue' => $venue->id(),
      ]);
    }

    return NULL;
  }

  /**
   * Gets the locations for this venue.
   *
   * @param \Drupal\myeventlane_venue\Entity\Venue $venue
   *   The venue entity.
   *
   * @return array
   *   Array of location data.
   */
  protected function getLocations(Venue $venue): array {
    $locations = [];

    try {
      foreach ($this->venueManager->getLocations($venue) as $location) {
        $locations[] = [
          'id' => $location->id(),
          'name' => $location->getTitle(),
          'address' => $location->getAddressText(),
          'lat' => $location->getLatitude(),
          'lng' => $location->getLongitude(),
          'is_primary' => $location->isPrimary(),
        ];
      }
    }
    catch (\Exception $e) {
      // Log but don't fail.
    }

    return $locations;
  }

  /**
   * Builds provider-neutral map data for the venue's best mapped location.
   *
   * @return array{latitude: ?float, longitude: ?float, address: string}
   *   Map coordinates and address. Coordinates may be absent for older venues.
   */
  protected function getMapData(Venue $venue): array {
    $location = $this->venueManager->getPrimaryLocation($venue);

    if ($location === NULL || $location->getLatitude() === NULL || $location->getLongitude() === NULL) {
      foreach ($this->venueManager->getLocations($venue) as $candidate) {
        if ($candidate->getLatitude() !== NULL && $candidate->getLongitude() !== NULL) {
          $location = $candidate;
          break;
        }
      }
    }

    $address = $location?->getAddressText() ?? '';
    if ($address === '' && !$venue->get('primary_address')->isEmpty()) {
      $address = (string) $venue->get('primary_address')->value;
    }

    return [
      'latitude' => $location?->getLatitude(),
      'longitude' => $location?->getLongitude(),
      'address' => $address,
    ];
  }

  /**
   * Gets events that take place at this venue.
   *
   * @param \Drupal\myeventlane_venue\Entity\Venue $venue
   *   The venue entity.
   *
   * @return array
   *   Array of event data for display.
   */
  protected function getEvents(Venue $venue): array {
    $events = [];

    try {
      $node_storage = $this->entityTypeManager()->getStorage('node');

      // Query for published events referencing this venue.
      $query = $node_storage->getQuery()
        ->condition('type', 'event')
        ->condition('status', 1)
        ->accessCheck(TRUE)
        ->sort('field_event_start', 'ASC')
        ->range(0, 20);

      // Check if field_venue exists and filter by venue ID.
      // Events may reference venues differently, so we try multiple field names.
      $venue_id = $venue->id();

      // Try field_venue (entity reference).
      $or_group = $query->orConditionGroup();
      $or_group->condition('field_venue', $venue_id);

      // Also check if venue name matches field_venue_name.
      $or_group->condition('field_venue_name', $venue->getName());

      $query->condition($or_group);

      $event_ids = $query->execute();

      if (!empty($event_ids)) {
        $event_nodes = $node_storage->loadMultiple($event_ids);

        foreach ($event_nodes as $event) {
          $event_data = [
            'id' => $event->id(),
            'title' => $event->label(),
            'url' => $event->toUrl()->toString(),
          ];

          // Get event date.
          if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
            $start_date = $event->get('field_event_start')->value;
            $event_data['date'] = $this->dateFormatter->format(strtotime($start_date), 'custom', 'l, F j, Y');
            $event_data['time'] = $this->dateFormatter->format(strtotime($start_date), 'custom', 'g:i A');
          }

          // Get event image.
          if ($event->hasField('field_event_image') && !$event->get('field_event_image')->isEmpty()) {
            $file = $event->get('field_event_image')->entity;
            if ($file) {
              $event_data['image_url'] = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
            }
          }

          $events[] = $event_data;
        }
      }
    }
    catch (\Exception $e) {
      // Log but don't fail - events might not exist or field might not exist.
    }

    return $events;
  }

}
