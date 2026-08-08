<?php

declare(strict_types=1);

namespace Drupal\mel_guide\Service;

/**
 * Distinguishes managed-file display IDs from configuration rollback IDs.
 */
final class MelGuideAssetSubmissionResolver {

  /**
   * Resolves the rollback FID and required Media action for an asset input.
   *
   * @return array{fid: int, preserve_media: bool, capture: bool}
   *   The rollback FID to persist and the required Media action.
   */
  public static function resolve(
    int $submitted_fid,
    int $configured_fid,
    ?int $stored_media_fid,
    bool $configured_file_exists,
  ): array {
    if ($submitted_fid === 0) {
      if ($configured_fid > 0 && !$configured_file_exists && ($stored_media_fid ?? 0) <= 0) {
        // An unavailable legacy file cannot appear in managed_file, so an
        // empty submission alone is not evidence that the editor removed it.
        return [
          'fid' => $configured_fid,
          'preserve_media' => FALSE,
          'capture' => FALSE,
        ];
      }

      return [
        'fid' => 0,
        'preserve_media' => FALSE,
        'capture' => FALSE,
      ];
    }

    if ($stored_media_fid === $submitted_fid) {
      return [
        'fid' => $configured_fid > 0 ? $configured_fid : $submitted_fid,
        'preserve_media' => TRUE,
        'capture' => FALSE,
      ];
    }

    if ($submitted_fid === $configured_fid) {
      return [
        'fid' => $configured_fid,
        'preserve_media' => FALSE,
        'capture' => FALSE,
      ];
    }

    return [
      'fid' => $submitted_fid,
      'preserve_media' => FALSE,
      'capture' => TRUE,
    ];
  }

}
