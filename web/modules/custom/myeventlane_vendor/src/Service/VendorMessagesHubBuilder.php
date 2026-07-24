<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_vendor\Entity\Vendor;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the organiser Messages Hub view model (Communication Centre).
 *
 * Deep-links existing compose, brand, templates, and Studio Messages.
 * Does not invent a new mail architecture.
 */
final class VendorMessagesHubBuilder {

  use StringTranslationTrait;

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly CurrentVendorResolverInterface $vendorResolver,
    private readonly TicketSalesService $ticketSales,
    private readonly VendorMessagesHistoryService $history,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LoggerInterface $logger,
    TranslationInterface $stringTranslation,
    private readonly ?object $attendeeResolver = NULL,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Builds the global Messages Hub payload.
   *
   * @return array<string, mixed>
   *   Hub view model for Twig.
   */
  public function build(): array {
    $uid = (int) $this->currentUser->id();
    $eventIds = $this->ticketSales->getManagedEventNidsForUser($uid);
    $historyRows = $this->history->loadForVendor($uid, $eventIds, 12);
    $summary = $this->history->summariseStatuses($historyRows);
    $events = $this->buildEventPicker($eventIds);
    $brandReady = $this->isBrandConfigured();
    $composeAvailable = $this->moduleHandler->moduleExists('myeventlane_vendor_comms');

    $needsAttention = !$composeAvailable || $summary['failed'] > 0 || (!$brandReady && $events !== []);
    $tone = $needsAttention ? 'attention' : ($historyRows === [] ? 'muted' : 'success');

    if ($summary['failed'] > 0) {
      $headline = (string) $this->t('Some messages need attention');
      $summaryText = (string) $this->t('One or more messages did not reach everyone. Open History to review.');
      $next = (string) $this->t('Check failed messages, then send an update if needed.');
    }
    elseif (!$composeAvailable) {
      $headline = (string) $this->t('Messages is almost ready');
      $summaryText = (string) $this->t('Messaging tools are not available on this site yet.');
      $next = (string) $this->t('Contact support if you need to reach guests urgently.');
    }
    elseif ($events === []) {
      $headline = (string) $this->t('Create an event to message guests');
      $summaryText = (string) $this->t('Once people book or RSVP, you can send announcements and reminders from one place.');
      $next = (string) $this->t('Add an event, then come back to send your first message.');
    }
    elseif ($historyRows === []) {
      $headline = (string) $this->t('Ready to keep your guests informed');
      $summaryText = (string) $this->t('Send announcements, reminders, and important updates — never marketing spam.');
      $next = (string) $this->t('Choose an event and write a clear, warm message.');
    }
    else {
      $headline = (string) $this->t('Your messages are in good shape');
      $summaryText = (string) $this->t('Recent updates are listed below. Send another when something changes.');
      $next = (string) $this->t('Pick an event to send a new message.');
    }

    $primaryEvent = $events[0] ?? NULL;
    $primaryCompose = is_array($primaryEvent) ? ($primaryEvent['compose_url'] ?? NULL) : NULL;

    $this->logger->info('messages_hub_opened uid=@uid events=@events history=@history', [
      '@uid' => (string) $uid,
      '@events' => (string) count($events),
      '@history' => (string) count($historyRows),
    ]);

    return [
      'health' => [
        'tone' => $tone,
        'headline' => $headline,
        'summary' => $summaryText,
        'next_step' => $next,
        'needs_attention' => $needsAttention,
        'brand_ready' => $brandReady,
        'cta_label' => $primaryCompose
          ? (string) $this->t('New message')
          : (string) $this->t('Create an event'),
        'cta_url' => $primaryCompose
        ?? $this->safeRouteUrl('myeventlane_vendor.console.events')
        ?? '/vendor/events',
      ],
      'overview' => [
        'events' => count($events),
        'sent' => $summary['sent'],
        'sending' => $summary['sending'],
        'failed' => $summary['failed'],
      ],
      'compose' => [
        'title' => (string) $this->t('Compose'),
        'body' => (string) $this->t('Choose who you need to talk to, then write a clear update.'),
        'events' => $events,
        'empty_title' => (string) $this->t('No events yet'),
        'empty_body' => (string) $this->t('Create an event first. Guests who book or RSVP will appear as your audience.'),
        'types' => $this->messageTypes(),
      ],
      'scheduled' => [
        'title' => (string) $this->t('Scheduled'),
        'body' => (string) $this->t('MyEventLane also sends automatic reminders before events when guests have tickets or RSVPs.'),
        'items' => $this->filterSendingRows($historyRows),
        'empty_title' => (string) $this->t('Nothing scheduled right now'),
        'empty_body' => (string) $this->t('Organiser-scheduled sends will show here. Automatic reminders still go out when guests need them.'),
      ],
      'history' => [
        'title' => (string) $this->t('History'),
        'items' => $historyRows,
        'empty_title' => (string) $this->t('No messages yet'),
        'empty_body' => (string) $this->t('When you send an announcement or update, it will appear in this timeline.'),
      ],
      'audience' => [
        'title' => (string) $this->t('Audience'),
        'body' => (string) $this->t('You can message everyone who booked or RSVPed. More filters (ticket type, checked in, waitlist) are expanding.'),
        'options' => $this->audienceOptions(),
      ],
      'templates' => [
        'title' => (string) $this->t('Templates'),
        'body' => (string) $this->t('Start from a message type — announcement, reminder, important update, cancellation, or thank you. Pro organisers can also refine system email wording.'),
        'cta_label' => (string) $this->t('Open Pro templates'),
        'cta_url' => $this->safeRouteUrl('myeventlane_pro.vendor_comms'),
        'available' => $this->moduleHandler->moduleExists('myeventlane_pro')
        && $this->safeRouteUrl('myeventlane_pro.vendor_comms') !== NULL,
      ],
      'branding' => [
        'title' => (string) $this->t('Branding'),
        'body' => (string) $this->t('Set the sender name, reply-to, and look of emails guests receive from you.'),
        'ready' => $brandReady,
        'cta_label' => (string) $this->t('Edit Messages brand'),
        'cta_url' => $this->safeRouteUrl('myeventlane_vendor.console.messaging_brand'),
      ],
      'support' => [
        'title' => (string) $this->t('Need a hand?'),
        'body' => (string) $this->t('If a message failed or guests did not receive an update, support can help.'),
        'cta_label' => (string) $this->t('Contact support'),
        'cta_url' => $this->safeRouteUrl('myeventlane_escalations_portal.vendor_list')
        ?? $this->safeRouteUrl('myeventlane_help_centre.help')
        ?? '/help',
        'help_label' => (string) $this->t('Help Centre'),
        'help_url' => '/help',
      ],
      'analytics' => [
        'messages_hub_opened' => TRUE,
      ],
    ];
  }

  /**
   * Builds an event-scoped Messages panel payload.
   *
   * @return array<string, mixed>
   *   Event Messages view model.
   */
  public function buildForEvent(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $historyRows = $this->history->loadForEvent($eventId, 20);
    $summary = $this->history->summariseStatuses($historyRows);
    $composeUrl = $this->safeRouteUrl('myeventlane_vendor.console.event_promotion', ['event' => $eventId]);
    $brandUrl = $this->safeRouteUrl('myeventlane_vendor_comms.branding', ['event' => $eventId]);
    $cancelUrl = $this->safeRouteUrl('myeventlane_refunds.vendor_cancel_event', ['node' => $eventId]);
    $recipientCount = $this->countRecipients($event);

    $this->logger->info('messages_hub_opened uid=@uid event=@eid scope=event', [
      '@uid' => (string) $this->currentUser->id(),
      '@eid' => (string) $eventId,
    ]);

    return [
      'event_title' => (string) $event->label(),
      'recipient_count' => $recipientCount,
      'compose_url' => $composeUrl,
      'brand_url' => $brandUrl,
      'cancel_url' => $cancelUrl,
      'overview' => [
        'audience' => $recipientCount,
        'sent' => $summary['sent'],
        'sending' => $summary['sending'],
        'failed' => $summary['failed'],
      ],
      'types' => $this->messageTypes(),
      'history' => [
        'items' => $historyRows,
        'empty_title' => (string) $this->t('No messages for this event yet'),
        'empty_body' => (string) $this->t('Send an announcement when times change, or a reminder before doors open.'),
      ],
      'scheduled' => [
        'items' => $this->filterSendingRows($historyRows),
        'empty_title' => (string) $this->t('Nothing sending right now'),
        'empty_body' => (string) $this->t('Automatic reminders still go out when guests need them.'),
      ],
      'audience' => [
        'options' => $this->audienceOptions(),
        'body' => (string) $this->t('@count guest(s) can receive messages for this event right now.', [
          '@count' => $recipientCount,
        ]),
      ],
      'analytics' => [
        'messages_hub_opened' => TRUE,
        'event_id' => $eventId,
      ],
    ];
  }

  /**
   * Builds the event picker for Compose.
   *
   * @param list<int> $eventIds
   *   Managed event IDs.
   *
   * @return list<array<string, mixed>>
   *   Event picker rows.
   */
  private function buildEventPicker(array $eventIds): array {
    if ($eventIds === []) {
      return [];
    }
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($eventIds);
    $rows = [];
    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface || $node->bundle() !== 'event') {
        continue;
      }
      $nid = (int) $node->id();
      $rows[] = [
        'id' => $nid,
        'title' => (string) $node->label(),
        'compose_url' => $this->safeRouteUrl('myeventlane_vendor.console.event_promotion', ['event' => $nid]),
        'hub_url' => $this->safeRouteUrl('myeventlane_event_studio.workspace_messaging', ['node' => $nid])
        ?? $this->safeRouteUrl('myeventlane_event_studio.workspace_messages', ['node' => $nid]),
        'audience_count' => $this->countRecipients($node),
      ];
    }
    usort($rows, static fn(array $a, array $b): int => strcasecmp((string) $a['title'], (string) $b['title']));
    return $rows;
  }

  /**
   * Message type cards with when / who / impact copy.
   *
   * @return list<array<string, string>>
   *   Type cards.
   */
  private function messageTypes(): array {
    return [
      [
        'key' => 'announcement',
        'label' => (string) $this->t('Announcement'),
        'when' => (string) $this->t('Share helpful news — lineup adds, what to bring, parking tips.'),
        'who' => (string) $this->t('Everyone who booked or RSVPed.'),
        'impact' => (string) $this->t('Keeps guests confident without sounding like marketing.'),
      ],
      [
        'key' => 'reminder',
        'label' => (string) $this->t('Reminder'),
        'when' => (string) $this->t('Nudge guests before the event — start time, doors, or how to get there.'),
        'who' => (string) $this->t('Everyone who booked or RSVPed.'),
        'impact' => (string) $this->t('Reduces no-shows and last-minute confusion.'),
      ],
      [
        'key' => 'important_update',
        'label' => (string) $this->t('Important update'),
        'when' => (string) $this->t('Something material changed — time, venue, or entry instructions.'),
        'who' => (string) $this->t('Everyone who booked or RSVPed.'),
        'impact' => (string) $this->t('Guests need this to attend successfully.'),
      ],
      [
        'key' => 'cancellation',
        'label' => (string) $this->t('Cancellation'),
        'when' => (string) $this->t('The event will not go ahead. Prefer Cancel event for refunds.'),
        'who' => (string) $this->t('Everyone who booked or RSVPed.'),
        'impact' => (string) $this->t('Clear, respectful notice protects trust.'),
      ],
      [
        'key' => 'thank_you',
        'label' => (string) $this->t('Thank you'),
        'when' => (string) $this->t('After the event — gratitude, photos, or what’s next.'),
        'who' => (string) $this->t('Everyone who booked or RSVPed.'),
        'impact' => (string) $this->t('Warm close to the community experience.'),
      ],
    ];
  }

  /**
   * Audience options shown on the hub (plain organiser language).
   *
   * @return list<array<string, mixed>>
   *   Audience cards.
   */
  private function audienceOptions(): array {
    return [
      [
        'key' => 'everyone',
        'label' => (string) $this->t('Everyone'),
        'description' => (string) $this->t('Ticket holders and RSVP guests.'),
        'available' => TRUE,
      ],
      [
        'key' => 'ticket_holders',
        'label' => (string) $this->t('Ticket holders'),
        'description' => (string) $this->t('People who purchased a ticket.'),
        'available' => TRUE,
      ],
      [
        'key' => 'rsvp',
        'label' => (string) $this->t('RSVP guests'),
        'description' => (string) $this->t('People who RSVPed — not ticket purchasers.'),
        'available' => TRUE,
      ],
      [
        'key' => 'ticket_type',
        'label' => (string) $this->t('Ticket type'),
        'description' => (string) $this->t('Coming soon — message one ticket type at a time.'),
        'available' => FALSE,
      ],
      [
        'key' => 'checked_in',
        'label' => (string) $this->t('Checked in'),
        'description' => (string) $this->t('Coming soon — message guests who have arrived.'),
        'available' => FALSE,
      ],
      [
        'key' => 'not_checked_in',
        'label' => (string) $this->t('Not checked in'),
        'description' => (string) $this->t('Coming soon — nudge guests who have not arrived yet.'),
        'available' => FALSE,
      ],
      [
        'key' => 'waitlist',
        'label' => (string) $this->t('Waitlist'),
        'description' => (string) $this->t('Coming soon — message people waiting for a spot.'),
        'available' => FALSE,
      ],
      [
        'key' => 'custom',
        'label' => (string) $this->t('Custom selection'),
        'description' => (string) $this->t('Coming soon — choose guests from Attendees.'),
        'available' => FALSE,
      ],
    ];
  }

  /**
   * Counts reachable guests for an event.
   */
  private function countRecipients(NodeInterface $event): int {
    if ($this->attendeeResolver !== NULL && method_exists($this->attendeeResolver, 'getCount')) {
      try {
        return (int) $this->attendeeResolver->getCount($event);
      }
      catch (\Throwable $e) {
        $this->logger->warning('Messages hub audience count failed: @m', ['@m' => $e->getMessage()]);
      }
    }
    return 0;
  }

  /**
   * Whether the organiser has a sender name configured.
   *
   * Matches VendorBrandResolver: field_msg_from_name, else vendor name.
   */
  private function isBrandConfigured(): bool {
    $vendor = $this->vendorResolver->resolveFromCurrentUser();
    if (!$vendor instanceof Vendor) {
      return FALSE;
    }
    $from = '';
    if ($vendor->hasField('field_msg_from_name') && !$vendor->get('field_msg_from_name')->isEmpty()) {
      $from = trim((string) $vendor->get('field_msg_from_name')->value);
    }
    if ($from === '') {
      $from = trim((string) ($vendor->getName() ?? ''));
    }
    return $from !== '';
  }

  /**
   * Filters history rows that are still sending.
   *
   * @param list<array<string, mixed>> $historyRows
   *   History rows.
   *
   * @return list<array<string, mixed>>
   *   Sending rows only.
   */
  private function filterSendingRows(array $historyRows): array {
    return array_values(array_filter(
      $historyRows,
      static function (array $row): bool {
        return in_array($row['status_key'] ?? '', ['pending', 'sending'], TRUE);
      },
    ));
  }

  /**
   * Builds a route URL, or NULL if the route is missing.
   */
  private function safeRouteUrl(string $route, array $params = [], array $options = []): ?string {
    try {
      return Url::fromRoute($route, $params, $options)->toString();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}
