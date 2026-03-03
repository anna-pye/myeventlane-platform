<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Ticket;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Generates QR codes for ticket PDFs.
 */
final class QrCodeGenerator {

  /**
   * Builds a PNG QR data URI for use in ticket PDF templates.
   */
  public function buildDataUri(string $payload, int $size = 260): ?string {
    $payload = trim($payload);
    if ($payload === '') {
      return NULL;
    }

    $result = Builder::create()
      ->writer(new PngWriter())
      ->data($payload)
      ->encoding(new Encoding('UTF-8'))
      ->errorCorrectionLevel(ErrorCorrectionLevel::Medium)
      ->size(max(220, min(300, $size)))
      ->margin(8)
      ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
      ->build();

    return $result->getDataUri();
  }

}
