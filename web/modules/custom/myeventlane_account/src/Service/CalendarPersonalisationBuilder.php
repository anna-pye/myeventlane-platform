<?php

declare(strict_types=1);

namespace Drupal\myeventlane_account\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds the user-specific planner and bookings panels on the public calendar.
 */
final class CalendarPersonalisationBuilder implements TrustedCallbackInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly CustomerHubDataBuilder $customerHubDataBuilder,
    private readonly TimeInterface $time,
    private readonly RequestStack $requestStack,
    private readonly UrlGeneratorInterface $urlGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['build'];
  }

  /**
   * Builds both personal panels inside one user-varied lazy placeholder.
   */
  public function build(): array {
    $destination = $this->requestStack->getCurrentRequest()?->getRequestUri() ?? '/calendar';
    $authenticated = $this->currentUser->isAuthenticated();
    $savedEventCards = [];
    $upcomingBookingCards = [];

    if ($authenticated) {
      $userId = (int) $this->currentUser->id();
      $now = $this->time->getRequestTime();
      $savedEvents = array_values(array_filter(
        $this->customerHubDataBuilder->buildSavedEventsPreview($userId, 0),
        static function (array $event) use ($now): bool {
          $eventEnds = (int) (($event['end_timestamp'] ?? 0) ?: ($event['start_timestamp'] ?? 0));
          return $eventEnds >= $now;
        },
      ));
      $savedEvents = array_slice($savedEvents, 0, 12);
      foreach ($savedEvents as &$event) {
        // A saved event is an idea, not a confirmed booking.
        unset($event['status_key'], $event['status_label']);
        $event['event_save_flag'] = $this->customerHubDataBuilder->buildEventSaveFlagLink((int) ($event['id'] ?? 0));
        $savedEventCards[] = [
          '#theme' => 'mel_account_event_card',
          '#event' => $event,
          '#card_variant' => 'saved',
          '#event_save_flag' => $event['event_save_flag'],
        ];
      }
      unset($event);

      $participation = $this->customerHubDataBuilder->buildParticipationLists(
        $userId,
        (string) $this->currentUser->getEmail(),
        $now,
      );
      $upcomingBookings = array_slice($participation['unified_upcoming'], 0, 12);
      foreach ($upcomingBookings as $event) {
        $upcomingBookingCards[] = [
          '#theme' => 'mel_account_event_card',
          '#event' => $event,
          '#card_variant' => ($event['source'] ?? '') === 'rsvp' ? 'rsvp' : 'ticket',
          '#hub_user_id' => ($event['source'] ?? '') === 'rsvp' ? $userId : 0,
        ];
      }
    }

    return [
      '#theme' => 'mel_calendar_personalisation',
      '#authenticated' => $authenticated,
      '#saved_event_cards' => $savedEventCards,
      '#upcoming_booking_cards' => $upcomingBookingCards,
      '#saved_event_count' => count($savedEventCards),
      '#upcoming_booking_count' => count($upcomingBookingCards),
      '#saved_events_url' => $this->urlGenerator->generateFromRoute('view.mel_saved_events.page_1'),
      '#bookings_url' => $this->urlGenerator->generateFromRoute('myeventlane_account.dashboard'),
      '#discover_url' => $this->urlGenerator->generateFromRoute('view.upcoming_events.page_events'),
      '#login_url' => $this->urlGenerator->generateFromRoute('user.login', [], [
        'query' => ['destination' => $destination],
      ]),
      '#register_url' => $this->urlGenerator->generateFromRoute('user.register', [], [
        'query' => ['destination' => $destination],
      ]),
      '#cache' => [
        'contexts' => ['user'],
        'max-age' => 0,
      ],
    ];
  }

}
