<?php

declare(strict_types=1);

namespace Drupal\myeventlane_wallet\Service;

use Drupal\Core\Image\ImageFactory;
use Drupal\file\FileInterface;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates Apple Wallet background artwork from an event hero image.
 */
final class WalletEventBackground {

  /**
   * Apple non-poster event-ticket background size in points.
   */
  private const WIDTH = 180;

  private const HEIGHT = 220;

  public function __construct(
    private readonly ImageFactory $imageFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Writes 1x, 2x and 3x PNG artwork when the ticket event has a hero image.
   *
   * @return bool
   *   TRUE when the complete responsive artwork set was written.
   */
  public function write(Ticket $ticket, string $workDir): bool {
    $event = $ticket->get('event_id')->entity;
    if (!$event instanceof NodeInterface || !$event->hasField('field_event_image') || $event->get('field_event_image')->isEmpty()) {
      return FALSE;
    }

    $file = $event->get('field_event_image')->entity;
    if (!$file instanceof FileInterface || $file->getFileUri() === '') {
      return FALSE;
    }

    foreach ([1 => '', 2 => '@2x', 3 => '@3x'] as $scale => $suffix) {
      $image = $this->imageFactory->get($file->getFileUri());
      $destination = $workDir . '/background' . $suffix . '.png';
      $width = self::WIDTH * $scale;
      $height = self::HEIGHT * $scale;

      if (!$image->isValid() || !$image->scaleAndCrop($width, $height) || !$image->convert('png') || !$image->save($destination)) {
        // Artwork is optional. Keep the ticket usable if a source image or the
        // active image toolkit cannot produce a valid Wallet PNG derivative.
        foreach (glob($workDir . '/background*.png') ?: [] as $partial) {
          @unlink($partial);
        }
        $this->logger->warning('Apple Wallet background generation failed for event @event at @scale×.', [
          '@event' => (string) $event->id(),
          '@scale' => (string) $scale,
        ]);
        return FALSE;
      }
    }
    return TRUE;
  }

}
