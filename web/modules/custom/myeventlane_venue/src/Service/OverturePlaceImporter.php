<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Streams a bounded Overture CSV extract into the local suggestion catalogue.
 */
final class OverturePlaceImporter {

  private const TABLE = 'myeventlane_venue_overture_place';

  private const REQUIRED_COLUMNS = [
    'id',
    'name',
    'latitude',
    'longitude',
  ];

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly VenueCandidateScorer $scorer,
  ) {}

  /**
   * Imports or validates a CSV extract.
   *
   * @return array{processed: int, imported: int, skipped: int, errors: array<int, string>, dry_run: bool}
   *   Import counts, validation errors and the selected write mode.
   */
  public function import(string $path, bool $dry_run = TRUE): array {
    if (!is_file($path) || !is_readable($path)) {
      throw new \InvalidArgumentException(sprintf('Overture CSV is not readable: %s', $path));
    }
    if (!$dry_run && !$this->database->schema()->tableExists(self::TABLE)) {
      throw new \RuntimeException('The Overture catalogue table is missing. Run Drupal database updates first.');
    }

    $file = new \SplFileObject($path, 'rb');
    $file->setCsvControl(',', '"', '');
    $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);
    $header = $file->fgetcsv();
    if (!is_array($header)) {
      throw new \InvalidArgumentException('Overture CSV has no header row.');
    }
    $header = array_map(static function (mixed $value): string {
      $value = trim((string) $value);
      return ltrim($value, "\xEF\xBB\xBF");
    }, $header);
    $missing = array_diff(self::REQUIRED_COLUMNS, $header);
    if ($missing !== []) {
      throw new \InvalidArgumentException('Overture CSV is missing required columns: ' . implode(', ', $missing));
    }

    $result = [
      'processed' => 0,
      'imported' => 0,
      'skipped' => 0,
      'errors' => [],
      'dry_run' => $dry_run,
    ];
    $imported_at = $this->time->getRequestTime();

    while (!$file->eof()) {
      $values = $file->fgetcsv();
      if (!is_array($values) || $values === [NULL] || $values === []) {
        continue;
      }
      $result['processed']++;
      $row = $this->combineRow($header, $values);
      try {
        $fields = $this->normalizeRow($row, $imported_at);
        if (!$dry_run) {
          $gers_id = (string) $fields['gers_id'];
          unset($fields['gers_id']);
          $this->database->merge(self::TABLE)
            ->key(['gers_id' => $gers_id])
            ->fields($fields)
            ->execute();
        }
        $result['imported']++;
      }
      catch (\InvalidArgumentException $exception) {
        $result['skipped']++;
        if (count($result['errors']) < 20) {
          $result['errors'][] = sprintf('Row %d: %s', $result['processed'] + 1, $exception->getMessage());
        }
      }
    }

    return $result;
  }

  /**
   * Combines a CSV header and value row into named values.
   *
   * @param string[] $header
   *   The validated CSV column names.
   * @param array<int, mixed> $values
   *   The current CSV row values.
   *
   * @return array<string, string>
   *   Trimmed values keyed by column name.
   */
  private function combineRow(array $header, array $values): array {
    $row = [];
    foreach ($header as $index => $column) {
      $row[$column] = trim((string) ($values[$index] ?? ''));
    }
    return $row;
  }

  /**
   * Validates and normalises one catalogue row for storage.
   *
   * @param array<string, string> $row
   *   The source row keyed by column name.
   * @param int $imported_at
   *   The request timestamp applied to imported rows.
   *
   * @return array<string, int|float|string|null>
   *   A bounded database row.
   */
  private function normalizeRow(array $row, int $imported_at): array {
    $id = mb_substr(trim($row['id'] ?? ''), 0, 128);
    $name = mb_substr(trim($row['name'] ?? ''), 0, 255);
    if ($id === '' || $name === '') {
      throw new \InvalidArgumentException('Place ID and name are required.');
    }
    if (!is_numeric($row['latitude'] ?? NULL) || !is_numeric($row['longitude'] ?? NULL)) {
      throw new \InvalidArgumentException('Latitude and longitude must be numeric.');
    }
    $latitude = (float) $row['latitude'];
    $longitude = (float) $row['longitude'];
    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
      throw new \InvalidArgumentException('Coordinates are outside valid ranges.');
    }

    $country = strtoupper(mb_substr(trim($row['country'] ?? 'AU'), 0, 2));
    if ($country !== 'AU') {
      throw new \InvalidArgumentException('The first slice accepts Australian places only.');
    }

    $confidence = NULL;
    if (($row['confidence'] ?? '') !== '') {
      if (!is_numeric($row['confidence'])) {
        throw new \InvalidArgumentException('Confidence must be numeric.');
      }
      $confidence = max(0.0, min(1.0, (float) $row['confidence']));
    }

    return [
      'gers_id' => $id,
      'name' => $name,
      'name_normalized' => $this->scorer->normalize($name),
      'address' => $this->bounded($row['address'] ?? '', 500),
      'locality' => $this->bounded($row['locality'] ?? '', 128),
      'postcode' => $this->bounded($row['postcode'] ?? '', 16),
      'region' => $this->bounded($row['region'] ?? '', 16),
      'country' => $country,
      'latitude' => $latitude,
      'longitude' => $longitude,
      'website' => $this->url($row['website'] ?? ''),
      'phone' => $this->bounded($row['phone'] ?? '', 64),
      'email' => $this->email($row['email'] ?? ''),
      'socials' => $this->socials($row['socials'] ?? ''),
      'confidence' => $confidence,
      'source_dataset' => $this->bounded($row['source_dataset'] ?? '', 128),
      'source_updated' => $this->timestamp($row['source_updated'] ?? ''),
      'imported' => $imported_at,
    ];
  }

  /**
   * Trims a string to the storage limit, or returns NULL when empty.
   */
  private function bounded(string $value, int $length): ?string {
    $value = trim($value);
    return $value === '' ? NULL : mb_substr($value, 0, $length);
  }

  /**
   * Returns a bounded public HTTP URL, or NULL when invalid.
   */
  private function url(string $value): ?string {
    $value = trim($value);
    $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
    return $value !== ''
      && in_array($scheme, ['http', 'https'], TRUE)
      && filter_var($value, FILTER_VALIDATE_URL)
      ? mb_substr($value, 0, 2048)
      : NULL;
  }

  /**
   * Returns a bounded email address, or NULL when invalid.
   */
  private function email(string $value): ?string {
    $value = trim($value);
    return $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL)
      ? mb_substr($value, 0, 254)
      : NULL;
  }

  /**
   * Keeps valid public HTTP social URLs from a JSON array.
   */
  private function socials(string $value): ?string {
    $value = trim($value);
    if ($value === '') {
      return NULL;
    }
    $decoded = json_decode($value, TRUE);
    if (!is_array($decoded)) {
      return NULL;
    }
    $urls = [];
    foreach ($decoded as $url) {
      $scheme = is_string($url) ? strtolower((string) parse_url($url, PHP_URL_SCHEME)) : '';
      if (is_string($url)
        && in_array($scheme, ['http', 'https'], TRUE)
        && filter_var($url, FILTER_VALIDATE_URL)) {
        $urls[] = $url;
      }
    }
    $urls = array_values(array_unique($urls));
    return $urls === [] ? NULL : json_encode($urls, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  }

  /**
   * Converts a Unix timestamp or date string to a timestamp.
   */
  private function timestamp(string $value): ?int {
    $value = trim($value);
    if ($value === '') {
      return NULL;
    }
    if (ctype_digit($value)) {
      return (int) $value;
    }
    $timestamp = strtotime($value);
    return $timestamp === FALSE ? NULL : $timestamp;
  }

}
