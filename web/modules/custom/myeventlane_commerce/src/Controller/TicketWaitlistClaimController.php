<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\mel_ticket\Entity\TicketWaitlistEntry;
use Drupal\myeventlane_commerce\Service\TicketBookingSessionService;
use Drupal\myeventlane_commerce\Service\TicketTierWaitlistService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Activates a session-scoped waitlist purchase offer from a token link.
 */
final class TicketWaitlistClaimController extends ControllerBase {

  public function __construct(
    private readonly TicketTierWaitlistService $tierWaitlist,
    private readonly TicketBookingSessionService $bookingSession,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_commerce.ticket_tier_waitlist'),
      $container->get('myeventlane_commerce.ticket_booking_session'),
    );
  }

  /**
   * Claims a waitlist offer and redirects back to the book page.
   */
  public function claim(NodeInterface $node, string $token): RedirectResponse {
    if ($node->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }

    $bookUrl = Url::fromRoute('myeventlane_commerce.event_book', ['node' => $node->id()], ['absolute' => TRUE])->toString();

    $entry = $this->tierWaitlist->loadOfferByToken($token);
    if (!$entry instanceof TicketWaitlistEntry) {
      $this->messenger()->addError($this->t('This waitlist link is not valid. Request a new offer from the event page if tickets are still waitlisted.'));
      return new RedirectResponse($bookUrl);
    }

    if ((int) $entry->get('event')->target_id !== (int) $node->id()) {
      $this->messenger()->addError(
        $this->t('This waitlist link doesn’t match this event. Please return to the event page and request a new offer if needed.')
      );
      return new RedirectResponse($bookUrl);
    }

    if ($entry->get('status')->value !== TicketWaitlistEntry::STATUS_OFFERED) {
      $this->messenger()->addWarning($this->t('This waitlist offer is no longer active.'));
      return new RedirectResponse($bookUrl);
    }

    if (!$this->tierWaitlist->isOfferClaimable($entry)) {
      $this->messenger()->addWarning($this->t('This waitlist offer has expired. You can join the waitlist again from the event page if it is still available.'));
      return new RedirectResponse($bookUrl);
    }

    $this->bookingSession->setWaitlistClaimEntryId((int) $node->id(), (int) $entry->id());
    $this->messenger()->addStatus($this->t('Your waitlist spot is active for this event. You can complete your purchase on this device — the booking page will only unlock the tier linked to this offer.'));

    return new RedirectResponse($bookUrl);
  }

}
