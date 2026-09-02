<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\myeventlane_venue\Entity\Venue;
use Drupal\myeventlane_venue\Service\VenueWebsiteImageImporter;
use Drupal\myeventlane_venue\Service\VenueWebsiteMetadataFetcher;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides approval-gated website metadata actions for existing venues.
 */
final class VenueWebsiteMetadataController extends ControllerBase {

  public const TEMPSTORE_COLLECTION = 'myeventlane_venue.website_metadata';
  public const PREVIEW_TTL = 1800;

  public function __construct(
    private readonly VenueWebsiteMetadataFetcher $metadataFetcher,
    private readonly VenueWebsiteImageImporter $imageImporter,
    private readonly PrivateTempStoreFactory $tempStoreFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly FloodInterface $flood,
    private readonly AccountProxyInterface $account,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_venue.website_metadata_fetcher'),
      $container->get('myeventlane_venue.website_image_importer'),
      $container->get('tempstore.private'),
      $container->get('datetime.time'),
      $container->get('logger.channel.myeventlane_venue'),
      $container->get('flood'),
      $container->get('current_user'),
    );
  }

  /**
   * Fetches metadata only after the organiser requests a preview.
   */
  public function preview(Venue $myeventlane_venue): JsonResponse {
    $website = trim((string) $myeventlane_venue->get('website')->value);
    if ($website === '') {
      return new JsonResponse(['message' => 'Save an official venue website first.'], 422);
    }

    $flood_id = $this->floodIdentifier($myeventlane_venue);
    if (!$this->flood->isAllowed('myeventlane_venue.website_preview', 10, 3600, $flood_id)) {
      return new JsonResponse(['message' => 'Too many website previews were requested. Try again later.'], 429);
    }
    $this->flood->register('myeventlane_venue.website_preview', 3600, $flood_id);

    try {
      $candidate = $this->metadataFetcher->fetch($website);
      $candidate['website'] = $website;
      $candidate['fetched_at'] = $this->time->getRequestTime();
      $this->tempStoreFactory
        ->get(self::TEMPSTORE_COLLECTION)
        ->set($this->tempStoreKey($myeventlane_venue), $candidate);

      return new JsonResponse([
        'title' => $candidate['title'],
        'description' => $candidate['description'],
        'imageUrl' => $candidate['image_url'],
        'sourceUrl' => $candidate['source_url'],
        'expiresIn' => self::PREVIEW_TTL,
      ]);
    }
    catch (\Throwable $error) {
      $this->logger->notice('Venue website metadata preview failed for venue @venue: @message', [
        '@venue' => (string) $myeventlane_venue->id(),
        '@message' => $error->getMessage(),
      ]);
      return new JsonResponse([
        'message' => 'We could not safely read reusable details from that website. Check the saved URL or add the details manually.',
      ], 422);
    }
  }

  /**
   * Imports the previewed image only after a separate rights confirmation.
   */
  public function importImage(Request $request, Venue $myeventlane_venue): JsonResponse {
    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload) || ($payload['confirmRights'] ?? FALSE) !== TRUE) {
      return new JsonResponse(['message' => 'Confirm that you have permission to reuse this image.'], 422);
    }
    $candidate = $this->tempStoreFactory
      ->get(self::TEMPSTORE_COLLECTION)
      ->get($this->tempStoreKey($myeventlane_venue));
    if (!$this->isCurrentCandidate($candidate, $myeventlane_venue)
      || trim((string) ($candidate['image_url'] ?? '')) === '') {
      return new JsonResponse(['message' => 'This preview has expired or the website changed. Preview it again before saving the image.'], 409);
    }

    $flood_id = $this->floodIdentifier($myeventlane_venue);
    if (!$this->flood->isAllowed('myeventlane_venue.website_image_import', 5, 3600, $flood_id)) {
      return new JsonResponse(['message' => 'Too many website images were saved. Try again later.'], 429);
    }
    $this->flood->register('myeventlane_venue.website_image_import', 3600, $flood_id);

    try {
      $media = $this->imageImporter->import(
        $myeventlane_venue,
        (string) $candidate['source_url'],
        (string) $candidate['image_url'],
      );
      $this->tempStoreFactory
        ->get(self::TEMPSTORE_COLLECTION)
        ->delete($this->tempStoreKey($myeventlane_venue));
      return new JsonResponse([
        'message' => 'The approved image was saved to this venue and your Media Library.',
        'mediaId' => (int) $media->id(),
      ]);
    }
    catch (\Throwable $error) {
      $this->logger->error('Venue website image import failed for venue @venue: @message', [
        '@venue' => (string) $myeventlane_venue->id(),
        '@message' => $error->getMessage(),
      ]);
      return new JsonResponse([
        'message' => 'The image could not be saved safely. You can still upload an image from your Media Library.',
      ], 422);
    }
  }

  /**
   * Checks that a preview still belongs to this venue and website.
   */
  private function isCurrentCandidate(mixed $candidate, Venue $venue): bool {
    if (!is_array($candidate)) {
      return FALSE;
    }
    $fetched = (int) ($candidate['fetched_at'] ?? 0);
    return $fetched > 0
      && ($this->time->getRequestTime() - $fetched) <= self::PREVIEW_TTL
      && hash_equals(
        trim((string) $venue->get('website')->value),
        trim((string) ($candidate['website'] ?? '')),
      );
  }

  /**
   * Returns a per-venue key within the current user's private tempstore.
   */
  private function tempStoreKey(Venue $venue): string {
    return 'venue:' . (int) $venue->id();
  }

  /**
   * Returns the per-user, per-venue rate-limit identifier.
   */
  private function floodIdentifier(Venue $venue): string {
    return sprintf('%d:%d', (int) $this->account->id(), (int) $venue->id());
  }

}
