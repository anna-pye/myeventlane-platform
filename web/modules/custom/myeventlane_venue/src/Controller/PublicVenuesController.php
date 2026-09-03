<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_venue\Entity\Venue;
use Drupal\myeventlane_venue\Service\VenueManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds the public venue directory.
 */
final class PublicVenuesController extends ControllerBase {

  private const PAGE_SIZE = 12;

  public function __construct(
    private readonly EntityTypeManagerInterface $venueEntityTypeManager,
    private readonly VenueManager $venueManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('myeventlane_venue.manager'),
    );
  }

  /**
   * Renders venues deliberately published to the public directory.
   *
   * @return array<string, mixed>
   *   The public venue directory render array.
   */
  public function listing(): array {
    $storage = $this->venueEntityTypeManager->getStorage('myeventlane_venue');

    // The explicit visibility condition is the security boundary. Entity
    // access also covers organiser-only shared venues, which must never be
    // allowed to broaden this public listing.
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('visibility', Venue::VISIBILITY_PUBLIC)
      ->sort('name', 'ASC')
      ->pager(self::PAGE_SIZE)
      ->execute();

    $entities = $ids === [] ? [] : $storage->loadMultiple($ids);
    $cards = [];
    $cacheTags = [
      'myeventlane_venue_list',
      'myeventlane_venue_location_list',
    ];

    foreach ($ids as $id) {
      $venue = $entities[$id] ?? NULL;
      if (!$venue instanceof Venue || !$venue->isPublic()) {
        continue;
      }

      $cards[] = $this->buildCard($venue);
      $cacheTags = Cache::mergeTags($cacheTags, $venue->getCacheTags());

      $imageMedia = $venue->getImageMedia();
      $imageFile = $venue->getImageFile();
      if ($imageMedia !== NULL) {
        $cacheTags = Cache::mergeTags($cacheTags, $imageMedia->getCacheTags());
      }
      elseif ($imageFile !== NULL) {
        $cacheTags = Cache::mergeTags($cacheTags, $imageFile->getCacheTags());
      }
    }

    return [
      '#theme' => 'myeventlane_venue_public_directory',
      '#venues' => $cards,
      '#pager' => [
        '#type' => 'pager',
      ],
      '#attached' => [
        'library' => ['myeventlane_venue/public_venues'],
      ],
      '#cache' => [
        'contexts' => ['url.query_args:page'],
        'tags' => $cacheTags,
      ],
    ];
  }

  /**
   * Builds the presentation data for one public venue card.
   *
   * @return array<string, mixed>
   *   Public, presentation-safe venue data.
   */
  private function buildCard(Venue $venue): array {
    $primaryLocation = $this->venueManager->getPrimaryLocation($venue);
    $address = trim($venue->getPrimaryAddress());
    if ($address === '' && $primaryLocation !== NULL) {
      $address = trim($primaryLocation->getAddressText());
    }

    return [
      'name' => $venue->getName(),
      'url' => $venue->toUrl()->toString(),
      'address' => $address,
      'summary' => $this->buildSummary($venue),
      'image_url' => $venue->getImageUrl('large') ?? $venue->getImageUrl(),
      'image_alt' => $venue->getImageAlt(),
    ];
  }

  /**
   * Produces a short plain-text description for card presentation.
   */
  private function buildSummary(Venue $venue): string {
    $value = $venue->get('description')->value ?? '';
    $plainText = Html::decodeEntities(strip_tags((string) $value));
    $plainText = trim((string) preg_replace('/\s+/u', ' ', $plainText));

    return Unicode::truncate($plainText, 150, TRUE, TRUE);
  }

}
