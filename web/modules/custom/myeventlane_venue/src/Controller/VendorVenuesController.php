<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\myeventlane_venue\Entity\Venue;
use Drupal\myeventlane_venue\Service\VenueAccessResolver;
use Drupal\myeventlane_venue\Service\VenueManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Controller for the vendor venues list.
 */
class VendorVenuesController extends ControllerBase {

  /**
   * The venue access resolver.
   */
  protected VenueAccessResolver $accessResolver;

  /**
   * The venue manager.
   */
  protected VenueManager $venueManager;

  /**
   * The module logger.
   */
  protected LoggerInterface $logger;

  /**
   * Constructs the controller.
   */
  public function __construct(
    VenueAccessResolver $access_resolver,
    VenueManager $venue_manager,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->accessResolver = $access_resolver;
    $this->venueManager = $venue_manager;
    $this->logger = $logger_factory->get('myeventlane_venue');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_venue.access_resolver'),
      $container->get('myeventlane_venue.manager'),
      $container->get('logger.factory'),
    );
  }

  /**
   * Renders the vendor venues list.
   *
   * @return array
   *   Render array.
   */
  public function list(): array {
    $venues = $this->accessResolver->getAccessibleVenues($this->currentUser());

    $rows = [];
    foreach ($venues as $venue) {
      $rows[] = $this->buildVenueRow($venue);
    }

    $build = [
      '#theme' => 'myeventlane_venue_vendor_list',
      '#venues' => $rows,
      '#add_url' => Url::fromRoute('myeventlane_venue.vendor_venue_add'),
      '#attached' => [
        'library' => ['myeventlane_venue/vendor_venues'],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['myeventlane_venue_list'],
      ],
    ];

    return $build;
  }

  /**
   * Builds a venue row for the list.
   *
   * @param \Drupal\myeventlane_venue\Entity\Venue $venue
   *   The venue entity.
   *
   * @return array
   *   Row data.
   */
  protected function buildVenueRow(Venue $venue): array {
    $isOwner = (int) $venue->getOwnerId() === (int) $this->currentUser()->id();
    $locationCount = $this->venueManager->getLocationCount($venue);

    $actions = [];

    // View action (always available).
    $actions['view'] = [
      'url' => $venue->toUrl(),
      'title' => $this->t('View'),
    ];

    if ($isOwner) {
      // Edit action (owner only).
      $actions['edit'] = [
        'url' => Url::fromRoute('myeventlane_venue.vendor_venue_edit', [
          'myeventlane_venue' => $venue->id(),
        ]),
        'title' => $this->t('Edit'),
      ];

      // Copy share link (owner only).
      $shareToken = $venue->getShareToken();
      if (!empty($shareToken)) {
        $actions['share'] = [
          'url' => NULL,
          'title' => $this->t('Copy share link'),
          'share_url' => Url::fromRoute('myeventlane_venue.share_accept', [
            'token' => $shareToken,
          ], ['absolute' => TRUE])->toString(),
        ];
      }
      else {
        $this->logger->error('Venue @venue_id is missing share token; share link button omitted for owner @owner_id.', [
          '@venue_id' => $venue->id(),
          '@owner_id' => $this->currentUser()->id(),
        ]);
      }

      // Delete action (owner only).
      $actions['delete'] = [
        'url' => Url::fromRoute('myeventlane_venue.vendor_venue_delete', [
          'myeventlane_venue' => $venue->id(),
        ]),
        'title' => $this->t('Delete'),
        'attributes' => [
          'class' => ['mel-venue-delete-btn'],
          'data-venue-name' => $venue->getName(),
        ],
      ];
    }
    else {
      // Report issue (non-owners).
      $actions['report'] = [
        'url' => Url::fromRoute('myeventlane_venue.report_issue', [
          'myeventlane_venue' => $venue->id(),
        ]),
        'title' => $this->t('Report issue'),
      ];
    }

    // Build image data.
    $imageUrl = NULL;
    if ($venue->hasImage()) {
      // Try to get medium image style URL, fallback to raw URL.
      $imageUrl = $venue->getImageUrl('medium');
      if (!$imageUrl) {
        $imageUrl = $venue->getImageUrl();
      }
    }

    return [
      'venue' => $venue,
      'name' => $venue->getName(),
      'visibility' => $venue->getVisibility(),
      'visibility_label' => $venue->isPublic() ? $this->t('Public') : $this->t('Shared'),
      'location_count' => $locationCount,
      'primary_address' => $venue->getPrimaryAddress(),
      'is_owner' => $isOwner,
      'has_image' => $venue->hasImage(),
      'image_url' => $imageUrl,
      'actions' => $actions,
    ];
  }

}
