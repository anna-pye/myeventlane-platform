<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Service;

use Drupal\Core\Database\Connection;

/**
 * Queries the locally imported, permissively licensed Overture catalogue.
 */
final class OverturePlaceRepository {

  private const TABLE = 'myeventlane_venue_overture_place';

  public function __construct(
    private readonly Connection $database,
    private readonly VenueCandidateScorer $scorer,
  ) {}

  /**
   * Finds likely Overture places without making an external request.
   *
   * @return array<int, array<string, mixed>>
   *   Ranked, presentation-safe candidates.
   */
  public function find(
    string $name,
    string $address = '',
    ?float $latitude = NULL,
    ?float $longitude = NULL,
    int $limit = 5,
  ): array {
    if (!$this->database->schema()->tableExists(self::TABLE)) {
      return [];
    }

    $normalized_name = $this->scorer->normalize($name);
    if ($normalized_name === '' && ($latitude === NULL || $longitude === NULL)) {
      return [];
    }

    $query = $this->database->select(self::TABLE, 'place')
      ->fields('place');
    $or = $query->orConditionGroup();
    if ($normalized_name !== '') {
      $or->condition('name_normalized', '%' . $this->database->escapeLike($normalized_name) . '%', 'LIKE');
    }
    if ($latitude !== NULL && $longitude !== NULL) {
      // Approximately 2 km in Australia; scoring applies the tighter threshold.
      $or->condition($query->andConditionGroup()
        ->condition('latitude', [$latitude - 0.02, $latitude + 0.02], 'BETWEEN')
        ->condition('longitude', [$longitude - 0.025, $longitude + 0.025], 'BETWEEN'));
    }
    $query->condition($or)->range(0, 80);

    $candidates = [];
    foreach ($query->execute() as $row) {
      $candidate = [
        'source' => 'overture',
        'source_id' => (string) $row->gers_id,
        'name' => (string) $row->name,
        'address' => (string) ($row->address ?? ''),
        'locality' => (string) ($row->locality ?? ''),
        'postcode' => (string) ($row->postcode ?? ''),
        'region' => (string) ($row->region ?? ''),
        'country' => (string) ($row->country ?? 'AU'),
        'latitude' => (float) $row->latitude,
        'longitude' => (float) $row->longitude,
        'website' => mb_substr((string) ($row->website ?? ''), 0, 255),
        'phone' => mb_substr((string) ($row->phone ?? ''), 0, 50),
        'email' => (string) ($row->email ?? ''),
        'socials' => $this->decodeSocials((string) ($row->socials ?? '')),
        'confidence' => $row->confidence === NULL ? NULL : (float) $row->confidence,
        'source_dataset' => (string) ($row->source_dataset ?? ''),
        'source_updated' => $row->source_updated === NULL ? NULL : (int) $row->source_updated,
      ];
      $candidate['score'] = $this->scorer->score($name, $address, $latitude, $longitude, $candidate);
      if ($candidate['score'] >= 35) {
        $candidates[] = $candidate;
      }
    }

    usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
    return array_slice($candidates, 0, max(1, min(10, $limit)));
  }

  /**
   * Loads one exact candidate for server-side provenance validation.
   *
   * @return array<string, mixed>|null
   *   The candidate, or NULL when it cannot be found.
   */
  public function load(string $source_id): ?array {
    if ($source_id === '' || !$this->database->schema()->tableExists(self::TABLE)) {
      return NULL;
    }
    $row = $this->database->select(self::TABLE, 'place')
      ->fields('place')
      ->condition('gers_id', $source_id)
      ->execute()
      ->fetchObject();
    if ($row === FALSE) {
      return NULL;
    }
    return [
      'source' => 'overture',
      'source_id' => (string) $row->gers_id,
      'name' => (string) $row->name,
      'address' => (string) ($row->address ?? ''),
      'website' => mb_substr((string) ($row->website ?? ''), 0, 255),
      'phone' => mb_substr((string) ($row->phone ?? ''), 0, 50),
      'email' => (string) ($row->email ?? ''),
      'socials' => $this->decodeSocials((string) ($row->socials ?? '')),
      'source_updated' => $row->source_updated === NULL ? NULL : (int) $row->source_updated,
    ];
  }

  /**
   * Maps known social URLs to the venue entity's social fields.
   *
   * @return array<string, string>
   *   Social field names keyed to valid URLs.
   */
  private function decodeSocials(string $json): array {
    if ($json === '') {
      return [];
    }
    $urls = json_decode($json, TRUE);
    if (!is_array($urls)) {
      return [];
    }
    $socials = [];
    foreach ($urls as $url) {
      if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        continue;
      }
      $host = strtolower((string) parse_url($url, PHP_URL_HOST));
      $field = match (TRUE) {
        str_contains($host, 'facebook.com') => 'facebook',
        str_contains($host, 'instagram.com') => 'instagram',
        str_contains($host, 'twitter.com'), str_contains($host, 'x.com') => 'twitter',
        str_contains($host, 'linkedin.com') => 'linkedin',
        str_contains($host, 'youtube.com'), str_contains($host, 'youtu.be') => 'youtube',
        str_contains($host, 'tiktok.com') => 'tiktok',
        default => '',
      };
      if ($field !== '' && !isset($socials[$field])) {
        $socials[$field] = $url;
      }
    }
    return $socials;
  }

}
