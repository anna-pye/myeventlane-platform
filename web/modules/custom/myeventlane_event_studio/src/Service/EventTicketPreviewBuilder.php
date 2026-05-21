<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\commerce_price\CurrencyFormatter;
use Drupal\commerce_price\Price;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\myeventlane_commerce\Service\TicketCapacityService;
use Drupal\myeventlane_event\Service\BookingFlowResolver;
use Drupal\myeventlane_event\Service\EventModeManager;
use Drupal\myeventlane_event\Service\TicketTierLifecycleService;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\NodeInterface;

/**
 * Builds a customer-safe booking preview for Event Studio ticket setup.
 */
final class EventTicketPreviewBuilder {

  use StringTranslationTrait;

  public const PREVIEW_NOT_CONFIGURED = 'not_configured';

  public const PREVIEW_RSVP = 'rsvp';

  public const PREVIEW_PAID = 'paid';

  public const PREVIEW_EXTERNAL = 'external';

  public function __construct(
    private readonly BookingFlowResolver $bookingFlowResolver,
    private readonly TicketTierLifecycleService $ticketTierLifecycle,
    private readonly EventModeManager $eventModeManager,
    private readonly CurrencyFormatter $currencyFormatter,
    TranslationInterface $string_translation,
    private readonly ?TicketCapacityService $ticketCapacity = NULL,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Render array for the tickets section preview panel.
   *
   * @return array<string, mixed>
   */
  public function build(NodeInterface $event): array {
    if ($event->bundle() !== 'event' || $event->isNew() || $event->id() === NULL) {
      return [];
    }

    $configuredMode = $this->resolveConfiguredMode($event);
    $previewState = $this->resolvePreviewState($event, $configuredMode);
    $cta = $this->resolveCtaPreview($event, $previewState);
    $availability = $this->resolveAvailabilityPreview($event, $previewState);

    $build = [
      '#theme' => 'mel_event_ticket_preview',
      '#preview_state' => $previewState,
      '#mode_badge' => $this->modeBadgeLabel($previewState),
      '#heading' => $this->previewHeading($previewState),
      '#cta' => $cta,
      '#availability' => $availability,
      '#ticket_rows' => $this->buildTicketRows($event, $previewState),
      '#rsvp_summary' => $this->buildRsvpSummary($event, $previewState),
      '#trust_rows' => $this->buildTrustRows($previewState),
      '#show_empty_state' => $previewState === self::PREVIEW_NOT_CONFIGURED,
      '#wrapper_classes' => ['mel-event-ticket-preview'],
      '#attached' => [
        'library' => ['myeventlane_event_studio/mel_ticket_preview'],
        'drupalSettings' => [
          'melEventTicketPreview' => [
            'modeLabels' => [
              self::PREVIEW_NOT_CONFIGURED => (string) $this->t('Not configured'),
              self::PREVIEW_RSVP => (string) $this->t('RSVP'),
              self::PREVIEW_PAID => (string) $this->t('Paid tickets'),
              self::PREVIEW_EXTERNAL => (string) $this->t('External'),
            ],
            'saveReminder' => (string) $this->t('Save to refresh ticket rows.'),
          ],
        ],
      ],
      '#cache' => [
        'tags' => $this->buildCacheTags($event),
        'contexts' => ['user.permissions'],
      ],
    ];

    return $build;
  }

  /**
   * @return list<string>
   */
  private function buildCacheTags(NodeInterface $event): array {
    $tags = $event->getCacheTags();
    if ($event->hasField('field_product_target') && !$event->get('field_product_target')->isEmpty()) {
      $product = $event->get('field_product_target')->entity;
      if ($product !== NULL) {
        $tags = array_merge($tags, $product->getCacheTags());
      }
    }
    foreach ($this->ticketTierLifecycle->loadOrderedTicketsForEvent($event) as $ticket) {
      $tags = array_merge($tags, $ticket->getCacheTags());
    }
    return array_values(array_unique($tags));
  }

  private function resolveConfiguredMode(NodeInterface $event): string {
    if (!$event->hasField('field_event_type') || $event->get('field_event_type')->isEmpty()) {
      return '';
    }
    return (string) $event->get('field_event_type')->value;
  }

  private function resolvePreviewState(NodeInterface $event, string $configuredMode): string {
    return match ($configuredMode) {
      'rsvp' => self::PREVIEW_RSVP,
      'paid' => self::PREVIEW_PAID,
      'external' => self::PREVIEW_EXTERNAL,
      default => self::PREVIEW_NOT_CONFIGURED,
    };
  }

  private function modeBadgeLabel(string $previewState): string {
    return match ($previewState) {
      self::PREVIEW_RSVP => (string) $this->t('RSVP'),
      self::PREVIEW_PAID => (string) $this->t('Paid tickets'),
      self::PREVIEW_EXTERNAL => (string) $this->t('External'),
      default => (string) $this->t('Not configured'),
    };
  }

  private function previewHeading(string $previewState): string {
    return match ($previewState) {
      self::PREVIEW_RSVP => (string) $this->t('Free RSVP'),
      self::PREVIEW_PAID => (string) $this->t('Tickets'),
      self::PREVIEW_EXTERNAL => (string) $this->t('External booking'),
      default => '',
    };
  }

  /**
   * @return array{label: string, disabled: bool, reason: string|null}
   */
  private function resolveCtaPreview(NodeInterface $event, string $previewState): array {
    if ($previewState === self::PREVIEW_NOT_CONFIGURED) {
      return [
        'label' => '',
        'disabled' => TRUE,
        'reason' => NULL,
      ];
    }

    if ($event->isPublished()) {
      $primary = $this->bookingFlowResolver->getPrimaryCta($event);
      return [
        'label' => (string) ($primary['label'] ?? ''),
        'disabled' => !empty($primary['disabled']),
        'reason' => isset($primary['reason']) && $primary['reason'] !== '' ? (string) $primary['reason'] : NULL,
      ];
    }

    return match ($previewState) {
      self::PREVIEW_RSVP => [
        'label' => (string) $this->t('RSVP free'),
        'disabled' => FALSE,
        'reason' => NULL,
      ],
      self::PREVIEW_PAID => [
        'label' => (string) $this->t('Get your tickets'),
        'disabled' => FALSE,
        'reason' => NULL,
      ],
      self::PREVIEW_EXTERNAL => [
        'label' => (string) $this->t('View details'),
        'disabled' => FALSE,
        'reason' => NULL,
      ],
      default => [
        'label' => '',
        'disabled' => TRUE,
        'reason' => NULL,
      ],
    };
  }

  /**
   * @return array{state: string, message: string|null}
   */
  private function resolveAvailabilityPreview(NodeInterface $event, string $previewState): array {
    if ($previewState === self::PREVIEW_NOT_CONFIGURED) {
      return ['state' => 'none', 'message' => NULL];
    }

    if (!$event->isPublished()) {
      return ['state' => 'draft', 'message' => NULL];
    }

    $availability = $this->bookingFlowResolver->getAvailabilityState($event);
    $message = match ($availability) {
      BookingFlowResolver::AVAILABILITY_SOLD_OUT => (string) $this->t('This event is sold out.'),
      BookingFlowResolver::AVAILABILITY_ENDED => (string) $this->t('This event has ended.'),
      BookingFlowResolver::AVAILABILITY_NOT_STARTED => (string) $this->t('Booking is not open yet.'),
      BookingFlowResolver::AVAILABILITY_UNAVAILABLE => (string) $this->t('Booking is not available for this event.'),
      default => NULL,
    };

    return [
      'state' => $availability,
      'message' => $message,
    ];
  }

  /**
   * @return list<array{name: string, price: string, availability: string}>
   */
  private function buildTicketRows(NodeInterface $event, string $previewState): array {
    if ($previewState !== self::PREVIEW_PAID) {
      return [];
    }

    $rows = [];
    $eventId = (int) $event->id();
    foreach ($this->ticketTierLifecycle->loadOrderedTicketsForEvent($event) as $ticket) {
      if ($ticket->getTicketKind() !== 'paid' || !$ticket->isPublished()) {
        continue;
      }
      $rows[] = [
        'name' => $ticket->getTitle(),
        'price' => $this->formatTicketPrice($ticket),
        'availability' => $this->formatTierAvailability($event, $eventId, $ticket),
      ];
    }

    return $rows;
  }

  /**
   * @return array{capacity_line: string|null}|null
   */
  private function buildRsvpSummary(NodeInterface $event, string $previewState): ?array {
    if ($previewState !== self::PREVIEW_RSVP) {
      return NULL;
    }

    $rsvp = $this->eventModeManager->getRsvpAvailability($event);
    $line = NULL;
    if (isset($rsvp['spots_remaining']) && $rsvp['spots_remaining'] !== NULL) {
      $remaining = (int) $rsvp['spots_remaining'];
      if ($remaining === 0) {
        $line = (string) $this->t('No spots remaining.');
      }
      else {
        $line = (string) $this->t('@count spot(s) remaining.', ['@count' => $remaining]);
      }
    }
    elseif (!empty($rsvp['reason']) && is_string($rsvp['reason'])) {
      $line = $rsvp['reason'];
    }

    return [
      'capacity_line' => $line,
    ];
  }

  /**
   * @return list<string>
   */
  private function buildTrustRows(string $previewState): array {
    $confirmation = (string) $this->t('Confirmation email');
    return match ($previewState) {
      self::PREVIEW_PAID => [
        (string) $this->t('Secure checkout'),
        $confirmation,
      ],
      self::PREVIEW_RSVP => [
        (string) $this->t('No payment required'),
        $confirmation,
      ],
      default => [$confirmation],
    };
  }

  private function formatTicketPrice(TicketTypeInterface $ticket): string {
    $price = $ticket->toPriceValue();
    if (!$price instanceof Price) {
      return (string) $this->t('Free');
    }
    if ($price->isZero()) {
      return (string) $this->t('Free');
    }
    return $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode());
  }

  private function formatTierAvailability(
    NodeInterface $event,
    int $eventId,
    TicketTypeInterface $ticket,
  ): string {
    if ($ticket->get('capacity')->isEmpty()) {
      return (string) $this->t('Available');
    }

    $cap = (int) $ticket->get('capacity')->value;
    if ($cap < 1) {
      return (string) $this->t('Available');
    }

    if (!$this->ticketCapacity instanceof TicketCapacityService) {
      return (string) $this->t('Available');
    }

    $variationId = $ticket->get('commerce_variation')->isEmpty()
      ? 0
      : (int) $ticket->get('commerce_variation')->target_id;
    if ($variationId < 1) {
      return (string) $this->t('Available');
    }

    $pool = $this->ticketCapacity->getRemaining($eventId, (int) $ticket->id(), $variationId, $ticket);
    if ($pool < 1) {
      return (string) $this->t('Limited availability');
    }
    if ($pool === 1) {
      return (string) $this->t('Only 1 left');
    }
    if ($pool <= 10) {
      return (string) $this->t('Only @count left', ['@count' => (string) $pool]);
    }

    return (string) $this->t('Available');
  }

}
