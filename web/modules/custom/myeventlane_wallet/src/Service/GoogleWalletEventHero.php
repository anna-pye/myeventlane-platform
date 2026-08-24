<?php

declare(strict_types=1);

namespace Drupal\myeventlane_wallet\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\file\FileInterface;
use Drupal\image\ImageStyleInterface;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves public event hero artwork for Google Wallet passes.
 */
final class GoogleWalletEventHero {

  private const IMAGE_STYLE = 'mel_google_wallet_hero';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Builds a Google Wallet Image payload for a public event hero.
   *
   * @return array<string, mixed>|null
   *   The heroImage payload, or NULL when no fetchable hero is available.
   */
  public function build(Ticket $ticket, string $eventLabel): ?array {
    $event = $ticket->get('event_id')->entity;
    if (!$event instanceof NodeInterface || !$event->hasField('field_event_image') || $event->get('field_event_image')->isEmpty()) {
      return NULL;
    }

    $item = $event->get('field_event_image')->first();
    $file = $item?->entity;
    if (!$file instanceof FileInterface) {
      return NULL;
    }
    $uri = $file->getFileUri();
    if ($uri === '' || StreamWrapperManager::getScheme($uri) !== 'public') {
      return NULL;
    }

    $style = $this->entityTypeManager->getStorage('image_style')->load(self::IMAGE_STYLE);
    if (!$style instanceof ImageStyleInterface) {
      $this->logger->warning('Google Wallet hero image style @style is unavailable.', [
        '@style' => self::IMAGE_STYLE,
      ]);
      return NULL;
    }

    try {
      $derivative_uri = $style->buildUri($uri);
      if (!is_file($derivative_uri) && !$style->createDerivative($uri, $derivative_uri)) {
        $this->logger->warning('Google Wallet hero derivative failed for event @event.', [
          '@event' => (string) $event->id(),
        ]);
        return NULL;
      }
      $dimensions = @getimagesize($derivative_uri);
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Google Wallet hero derivative failed for event @event: @message', [
        '@event' => (string) $event->id(),
        '@message' => $exception->getMessage(),
      ]);
      return NULL;
    }
    if (!is_array($dimensions) || $dimensions[0] !== 1280 || $dimensions[1] !== 400) {
      $this->logger->warning('Google Wallet hero derivative is invalid for event @event.', [
        '@event' => (string) $event->id(),
      ]);
      return NULL;
    }

    $url = $style->buildUrl($uri);
    if (!str_starts_with($url, 'https://')) {
      $this->logger->warning('Google Wallet hero for event @event does not have a public HTTPS URL.', [
        '@event' => (string) $event->id(),
      ]);
      return NULL;
    }

    $description = trim((string) ($item?->alt ?? ''));
    if ($description === '') {
      $description = $eventLabel !== '' ? $eventLabel : 'Event hero image';
    }

    return [
      'sourceUri' => ['uri' => $url],
      'contentDescription' => [
        'defaultValue' => [
          'language' => 'en-AU',
          'value' => $description,
        ],
      ],
    ];
  }

}
