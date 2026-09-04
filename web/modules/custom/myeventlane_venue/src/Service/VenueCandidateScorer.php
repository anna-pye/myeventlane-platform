<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Service;

/**
 * Scores venue candidates without depending on a map provider.
 */
final class VenueCandidateScorer {

  /**
   * Normalises user and catalogue strings for conservative matching.
   */
  public function normalize(string $value): string {
    $value = mb_strtolower(trim($value));
    $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
  }

  /**
   * Scores a candidate from 0 to 100.
   *
   * @param string $name
   *   The organiser-entered venue name.
   * @param string $address
   *   The organiser-entered address.
   * @param float|null $latitude
   *   The selected latitude, when available.
   * @param float|null $longitude
   *   The selected longitude, when available.
   * @param array{name?: string, address?: string, latitude?: float|null, longitude?: float|null, confidence?: float|null} $candidate
   *   Candidate venue data.
   */
  public function score(
    string $name,
    string $address,
    ?float $latitude,
    ?float $longitude,
    array $candidate,
  ): int {
    $score = 0;
    $query_name = $this->normalize($name);
    $candidate_name = $this->normalize((string) ($candidate['name'] ?? ''));
    if ($query_name !== '' && $candidate_name !== '') {
      if ($query_name === $candidate_name) {
        $score += 55;
      }
      elseif (str_contains($query_name, $candidate_name) || str_contains($candidate_name, $query_name)) {
        $score += 38;
      }
      else {
        $score += (int) round(30 * $this->tokenSimilarity($query_name, $candidate_name));
      }
    }

    $query_address = $this->normalize($address);
    $candidate_address = $this->normalize((string) ($candidate['address'] ?? ''));
    if ($query_address !== '' && $candidate_address !== '') {
      if ($query_address === $candidate_address) {
        $score += 25;
      }
      elseif (str_contains($query_address, $candidate_address) || str_contains($candidate_address, $query_address)) {
        $score += 18;
      }
      else {
        $score += (int) round(12 * $this->tokenSimilarity($query_address, $candidate_address));
      }
    }

    $candidate_latitude = isset($candidate['latitude']) ? (float) $candidate['latitude'] : NULL;
    $candidate_longitude = isset($candidate['longitude']) ? (float) $candidate['longitude'] : NULL;
    if ($latitude !== NULL && $longitude !== NULL && $candidate_latitude !== NULL && $candidate_longitude !== NULL) {
      $distance = $this->distanceMetres($latitude, $longitude, $candidate_latitude, $candidate_longitude);
      $score += match (TRUE) {
        $distance <= 50 => 20,
        $distance <= 250 => 15,
        $distance <= 1000 => 8,
        default => 0,
      };
    }

    $confidence = $candidate['confidence'] ?? NULL;
    if (is_numeric($confidence)) {
      $score += (int) round(max(0.0, min(1.0, (float) $confidence)) * 5);
    }

    return min(100, $score);
  }

  /**
   * Determines whether submitted details identify the same physical venue.
   *
   * Matching is deliberately stricter than suggestion scoring. A common name
   * alone must never block a legitimate venue at another address.
   *
   * @param string $name
   *   Submitted venue name.
   * @param string $address
   *   Submitted venue address.
   * @param float|null $latitude
   *   Submitted latitude, when supplied by the map provider.
   * @param float|null $longitude
   *   Submitted longitude, when supplied by the map provider.
   * @param array{name?: string, address?: string, latitude?: float|null, longitude?: float|null} $candidate
   *   Existing venue data.
   */
  public function isDuplicate(
    string $name,
    string $address,
    ?float $latitude,
    ?float $longitude,
    array $candidate,
  ): bool {
    $submitted_name = $this->normalize($name);
    $candidate_name = $this->normalize((string) ($candidate['name'] ?? ''));
    if ($submitted_name === '' || $submitted_name !== $candidate_name) {
      return FALSE;
    }

    $submitted_address = $this->normalize($address);
    $candidate_address = $this->normalize((string) ($candidate['address'] ?? ''));
    if ($submitted_address !== '' && $submitted_address === $candidate_address) {
      return TRUE;
    }

    $candidate_latitude = isset($candidate['latitude']) && is_numeric($candidate['latitude'])
      ? (float) $candidate['latitude']
      : NULL;
    $candidate_longitude = isset($candidate['longitude']) && is_numeric($candidate['longitude'])
      ? (float) $candidate['longitude']
      : NULL;
    if ($latitude === NULL || $longitude === NULL || $candidate_latitude === NULL || $candidate_longitude === NULL) {
      return FALSE;
    }

    return $this->distanceMetres(
      $latitude,
      $longitude,
      $candidate_latitude,
      $candidate_longitude,
    ) <= 75;
  }

  /**
   * Returns the great-circle distance in metres.
   */
  public function distanceMetres(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $earth_radius = 6371000.0;
    $lat_delta = deg2rad($lat2 - $lat1);
    $lng_delta = deg2rad($lng2 - $lng1);
    $a = sin($lat_delta / 2) ** 2
      + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lng_delta / 2) ** 2;
    return $earth_radius * 2 * atan2(sqrt($a), sqrt(1 - $a));
  }

  /**
   * Returns the proportion of unique tokens shared by both strings.
   */
  private function tokenSimilarity(string $left, string $right): float {
    $left_tokens = array_values(array_unique(array_filter(explode(' ', $left))));
    $right_tokens = array_values(array_unique(array_filter(explode(' ', $right))));
    if ($left_tokens === [] || $right_tokens === []) {
      return 0.0;
    }
    $intersection = count(array_intersect($left_tokens, $right_tokens));
    $union = count(array_unique(array_merge($left_tokens, $right_tokens)));
    return $union === 0 ? 0.0 : $intersection / $union;
  }

}
