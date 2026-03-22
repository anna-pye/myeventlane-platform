<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core\Service;

/**
 * MEL Language Standardisation: Australian English + terminology unification.
 *
 * MEL LANGUAGE STANDARD:
 * - Australian English (organiser, colour, centre, etc.)
 * - "Attendee" not "Customer"
 * - "Organiser" not "Vendor"
 * - Friendly, non-corporate tone.
 *
 * Use replace() for display text only. Never apply to URLs, paths, or
 * machine names.
 */
final class LanguageStyleService {

  /**
   * Replacement map: US → AU spelling and terminology standardisation.
   *
   * Longer phrases must appear before shorter overlapping ones (strtr uses
   * longest match). Order: phrases first, then single words.
   */
  private const REPLACEMENTS = [
    // Phrase-level (specific MEL terminology).
    'Send message to customers' => 'Message your attendees',
    'send message to customers' => 'message your attendees',
    'user details' => 'your details',
    'User details' => 'Your details',
    'complete purchase' => 'confirm booking',
    'Complete purchase' => 'Confirm booking',
    'event host' => 'organiser',
    'Event host' => 'Organiser',

    // US → AU spelling.
    'organizers' => 'organisers',
    'Organizers' => 'Organisers',
    'organizer' => 'organiser',
    'Organizer' => 'Organiser',

    'color' => 'colour',
    'Color' => 'Colour',

    'center' => 'centre',
    'Center' => 'Centre',

    'favorite' => 'favourite',
    'Favorite' => 'Favourite',

    'customize' => 'customise',
    'Customize' => 'Customise',

    'analyze' => 'analyse',
    'Analyze' => 'Analyse',

    // Terminology standardisation.
    'customer' => 'attendee',
    'Customer' => 'Attendee',

    'customers' => 'attendees',
    'Customers' => 'Attendees',

    'user' => 'member',
    'User' => 'Member',

    'vendor' => 'organiser',
    'Vendor' => 'Organiser',

    'vendors' => 'organisers',
    'Vendors' => 'Organisers',

    'seller' => 'organiser',
    'Seller' => 'Organiser',
  ];

  /**
   * Variable keys that must never be processed (URLs, paths, machine names).
   */
  private const SKIP_KEYS = [
    'url', 'path', 'href', 'src', '#url', 'uri', 'file_uri', 'logo_path',
    'image_src', 'directory', 'base_path', 'form_id', 'id', 'machine_name',
    'route_name', 'route', '#route', 'link', '#link', 'file', '#file',
    'fragment', 'query', '#query', 'attributes', '#attributes', 'class',
    '#class', 'style', '#style', 'data', '#data', 'placeholder',
  ];

  /**
   * Applies MEL language standardisation to display text.
   *
   * @param string $text
   *   The raw text (labels, titles, messages, etc.).
   *
   * @return string
   *   Text with Australian English and MEL terminology applied.
   */
  public function replace(string $text): string {
    return strtr($text, self::REPLACEMENTS);
  }

  /**
   * Validates text against MEL language standards (lint-style helper).
   *
   * @param string $text
   *   The text to validate.
   *
   * @return string[]
   *   List of issues found (empty if compliant).
   */
  public function validate(string $text): array {
    $issues = [];
    $lower = strtolower($text);

    if (str_contains($lower, 'customer')) {
      $issues[] = 'Use "attendee" instead of "customer"';
    }
    if (str_contains($lower, 'vendor') && !str_contains($lower, 'vendor_id') && !str_contains($lower, 'vendor.')) {
      $issues[] = 'Use "organiser" instead of "vendor" for user-facing text';
    }
    if (str_contains($lower, 'organizer')) {
      $issues[] = 'Use Australian spelling "organiser"';
    }
    if (str_contains($lower, 'color') && !str_contains($lower, 'color:')) {
      $issues[] = 'Use Australian spelling "colour"';
    }
    if (str_contains($lower, 'customize')) {
      $issues[] = 'Use Australian spelling "customise"';
    }

    return $issues;
  }

  /**
   * Checks if a variable key should be skipped (not processed).
   *
   * @param string|int $key
   *   The array key (string or numeric index).
   */
  public function shouldSkipKey(string|int $key): bool {
    if (is_int($key)) {
      return FALSE;
    }
    if (in_array($key, self::SKIP_KEYS, TRUE)) {
      return TRUE;
    }
    // Skip keys that suggest URL/path/technical.
    $suffixes = ['_path', '_url', '_uri', '_src', '_id', '_ids'];
    foreach ($suffixes as $suffix) {
      if (str_ends_with($key, $suffix)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Checks if a string looks like a URL or path (should not be processed).
   */
  public function looksLikeUrl(string $value): bool {
    $trimmed = trim($value);
    return $trimmed !== '' && (
      str_starts_with($trimmed, '/') ||
      str_starts_with($trimmed, 'http://') ||
      str_starts_with($trimmed, 'https://') ||
      str_starts_with($trimmed, 'mailto:') ||
      str_starts_with($trimmed, '//')
    );
  }

}
