<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\myeventlane_event_studio\Service\EventStudioSaveService;
use Drupal\node\NodeInterface;

/**
 * Safe archive/delete decisions and thumbnail data for vendor console cards.
 *
 * Booking activity blocks permanent deletion; archive uses Event Studio publish
 * state (aligned with vendor bulk unpublish).
 */
final class VendorEventRemovalService {

  use StringTranslationTrait;

  private const THUMB_STYLE = 'thumbnail';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TicketSalesService $ticketSalesService,
    private readonly RsvpStatsService $rsvpStatsService,
    private readonly EventStudioSaveService $eventStudioSaveService,
    private readonly EventVendorAccessChecker $eventVendorAccessChecker,
    private readonly CsrfTokenGenerator $csrfToken,
    TranslationInterface $string_translation,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds event card image data for Twig (no entity loading in templates).
   *
   * @return array<string, mixed>
   *   Keys: url (string|null), alt (string), is_placeholder (bool).
   */
  public function buildEventThumbnailData(NodeInterface $event): array {
    $alt = trim((string) $event->getTitle());
    if ($alt === '') {
      $alt = (string) $this->t('Event image');
    }

    $url = NULL;
    if ($event->hasField('field_event_image') && !$event->get('field_event_image')->isEmpty()) {
      $file = $event->get('field_event_image')->entity;
      if ($file instanceof FileInterface) {
        try {
          $style = $this->entityTypeManager->getStorage('image_style')->load(self::THUMB_STYLE);
          if ($style) {
            // ImageStyle::buildUrl() returns the usable URL; do not pass it to
            // FileUrlGenerator (expects a stream wrapper URI).
            $url = $style->buildUrl($file->getFileUri());
          }
        }
        catch (\Throwable $e) {
          $this->loggerFactory->get('myeventlane_vendor')->warning('Event card thumbnail URL failed for nid @nid: @message', [
            '@nid' => (string) $event->id(),
            '@message' => $e->getMessage(),
          ]);
        }
      }
    }

    return [
      'url' => $url,
      'alt' => $alt,
      'is_placeholder' => $url === NULL,
    ];
  }

  /**
   * @return array<string, int|bool>
   */
  public function getBookingActivitySummary(NodeInterface $event): array {
    $nid = (int) $event->id();
    $sales = $this->ticketSalesService->getSalesSummary($event);
    $ticketsSold = (int) ($sales['tickets_sold'] ?? 0);
    $ordersCount = (int) ($sales['orders_count'] ?? 0);
    $rsvpCount = 0;
    try {
      $rsvpCount = $this->rsvpStatsService->getEventRsvpCount($nid);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_vendor')->warning('Removal activity: RSVP count failed for nid @nid: @message', [
        '@nid' => (string) $nid,
        '@message' => $e->getMessage(),
      ]);
    }

    $attendeeCount = $this->countNonCancelledAttendees($nid);

    $hasActivity = $ticketsSold > 0
      || $ordersCount > 0
      || $rsvpCount > 0
      || $attendeeCount > 0;

    return [
      'tickets_sold' => $ticketsSold,
      'orders_count' => $ordersCount,
      'rsvp_count' => $rsvpCount,
      'attendee_count' => $attendeeCount,
      'has_booking_activity' => $hasActivity,
    ];
  }

  private function countNonCancelledAttendees(int $eventNid): int {
    if ($eventNid <= 0 || !$this->entityTypeManager->hasDefinition('event_attendee')) {
      return 0;
    }
    try {
      $storage = $this->entityTypeManager->getStorage('event_attendee');
      return (int) $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event', $eventNid)
        ->condition('status', 'cancelled', '<>')
        ->count()
        ->execute();
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_vendor')->warning('Removal activity: attendee count failed for nid @nid: @message', [
        '@nid' => (string) $eventNid,
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  public function accountMayDeleteNode(NodeInterface $event, AccountInterface $account): bool {
    if ($event->bundle() !== 'event') {
      return FALSE;
    }
    return $event->access('delete', $account, TRUE)->isAllowed();
  }

  /**
   * Performs archive (unpublish) and/or delete per platform rules.
   *
   * @return array{action: string, message: string}
   *   action is one of: archived, deleted, noop, error.
   */
  public function executeRemovalFromRequest(NodeInterface $event, AccountInterface $account): array {
    $summary = $this->getBookingActivitySummary($event);
    $hasActivity = (bool) ($summary['has_booking_activity'] ?? FALSE);
    $mayDelete = $this->accountMayDeleteNode($event, $account);

    try {
      if ($event->isPublished()) {
        if ($hasActivity || !$mayDelete) {
          $this->eventStudioSaveService->setNodePublishedState(
            $event,
            $account,
            FALSE,
            'Vendor console: archive/unpublish from event card (booking activity or delete not permitted).',
          );
          return [
            'action' => 'archived',
            'message' => $hasActivity
              ? (string) $this->t('This event had booking activity, so it was archived instead of permanently deleted.')
              : (string) $this->t('Event archived (unpublished).'),
          ];
        }
        $event->delete();
        return [
          'action' => 'deleted',
          'message' => (string) $this->t('Event deleted.'),
        ];
      }

      // Unpublished / draft.
      if ($hasActivity) {
        return [
          'action' => 'noop',
          'message' => (string) $this->t('This event already has reduced visibility and still has booking records. It was not removed.'),
        ];
      }
      if ($mayDelete) {
        $event->delete();
        return [
          'action' => 'deleted',
          'message' => (string) $this->t('Event deleted.'),
        ];
      }
      return [
        'action' => 'error',
        'message' => (string) $this->t('You do not have permission to delete this event.'),
      ];
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('myeventlane_vendor')->error('Event removal failed for nid @nid: @message', [
        '@nid' => (string) $event->id(),
        '@message' => $e->getMessage(),
      ]);
      return [
        'action' => 'error',
        'message' => (string) $this->t('The event could not be updated. Please try again or use Event settings.'),
      ];
    }
  }

  /**
   * @return array<string, mixed>|null
   *   Removal UI payload, or NULL when the account must not see removal.
   */
  public function buildRemovalUiPayload(NodeInterface $event, AccountInterface $account): ?array {
    if (!$this->eventVendorAccessChecker->accountHasWorkspaceParityForEvent($event, $account)) {
      return NULL;
    }

    $summary = $this->getBookingActivitySummary($event);
    $hasActivity = (bool) $summary['has_booking_activity'];
    $mayDelete = $this->accountMayDeleteNode($event, $account);

    if (!$event->isPublished() && $hasActivity) {
      return NULL;
    }
    if (!$event->isPublished() && !$mayDelete) {
      return NULL;
    }

    $nid = (int) $event->id();
    $tokenId = 'vendor_event_remove_' . $nid;
    $token = $this->csrfToken->get($tokenId);

    try {
      $formAction = Url::fromRoute('myeventlane_vendor.console.event_archive', ['event' => $nid])->toString();
    }
    catch (\Throwable) {
      return NULL;
    }

    if ($event->isPublished()) {
      $dangerLabel = ($hasActivity || !$mayDelete)
        ? (string) $this->t('Archive')
        : (string) $this->t('Delete');
    }
    else {
      $dangerLabel = (string) $this->t('Delete');
    }

    $confirmPrimary = $hasActivity
      ? (string) $this->t('Archive event')
      : ($mayDelete ? (string) $this->t('Delete event') : (string) $this->t('Archive event'));

    $modalTitle = (string) $this->t('Delete this event?');
    $modalBody = (string) $this->t('Are you sure you want to delete this event? This action may affect tickets, RSVPs, attendees, and reporting.');
    $modalActivity = $hasActivity
      ? (string) $this->t('This event has booking activity, so it cannot be permanently deleted. You can archive it instead.')
      : '';

    return [
      'form_action' => $formAction,
      'form_token' => $token,
      'form_token_id' => $tokenId,
      'danger_label' => $dangerLabel,
      'confirm_primary_label' => $confirmPrimary,
      'cancel_label' => (string) $this->t('Cancel'),
      'modal_title' => $modalTitle,
      'modal_body' => $modalBody,
      'modal_activity_notice' => $modalActivity,
      'has_booking_activity' => $hasActivity,
      'dialog_id' => 'mel-event-remove-' . $nid,
    ];
  }

}
