<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Controller;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_event_attendees\Entity\EventAttendee;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Ticket\TicketPdfGenerator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Handles ticket PDF downloads.
 */
final class TicketDownloadController extends ControllerBase {

  public function __construct(
    private readonly TicketPdfGenerator $pdfGenerator,
    private readonly EntityTypeManagerInterface $ticketsEntityTypeManager,
    private readonly ConfigFactoryInterface $ticketsConfigFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_tickets.ticket_pdf_generator'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
    );
  }

  /**
   * LEGACY: Download ticket PDF by order item ID.
   *
   * ⚠️ One order item may represent multiple tickets.
   * Generates a combined PDF for backward compatibility only.
   */
  public function download(int $order_item_id) {
    $order_item = $this->ticketsEntityTypeManager
      ->getStorage('commerce_order_item')
      ->load($order_item_id);

    if (!$order_item) {
      throw new NotFoundHttpException();
    }

    $order = $order_item->getOrder();
    if (!$order || (int) $order->getCustomerId() !== (int) $this->currentUser()->id()) {
      throw new AccessDeniedHttpException();
    }
    if ($this->isExpired((int) $order_item->getCreatedTime())) {
      throw new NotFoundHttpException();
    }

    return $this->pdfGenerator->generatePdfForOrderItem($order_item);
  }

  /**
   * CANONICAL v2: Download ticket PDF by ticket code.
   *
   * Route: /ticket/{ticket_code}/pdf.
   *
   * Looks up myeventlane_ticket.ticket_code first. Event attendees use a
   * separate MEL-{event}-{order}-{item}-{rand} code at checkout; those are
   * resolved via event_attendee when no issued ticket matches.
   */
  public function downloadByCode(string $ticket_code) {
    $storage = $this->ticketsEntityTypeManager->getStorage('myeventlane_ticket');

    $ids = $storage->getQuery()
      ->condition('ticket_code', $ticket_code)
      ->accessCheck(FALSE)
      ->execute();

    if ($ids) {
      $ticket = $storage->load(reset($ids));
      return $this->respondWithIssuedTicket($ticket);
    }

    return $this->downloadByAttendeeTicketCode($ticket_code);
  }

  /**
   * Resolves PDF when the path code matches event_attendee.ticket_code.
   */
  private function downloadByAttendeeTicketCode(string $ticket_code) {
    $attendeeStorage = $this->ticketsEntityTypeManager->getStorage('event_attendee');
    $attendeeIds = $attendeeStorage->getQuery()
      ->condition('ticket_code', $ticket_code)
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();

    if (!$attendeeIds) {
      throw new NotFoundHttpException();
    }

    /** @var \Drupal\myeventlane_event_attendees\Entity\EventAttendee $attendee */
    $attendee = $attendeeStorage->load(reset($attendeeIds));
    if (!$attendee instanceof EventAttendee) {
      throw new NotFoundHttpException();
    }

    if (!$this->canAccessAttendeeForPdf($attendee)) {
      throw new AccessDeniedHttpException();
    }

    if ($attendee->get('order_item')->isEmpty()) {
      throw new NotFoundHttpException();
    }

    /** @var \Drupal\commerce_order\Entity\OrderItemInterface|null $order_item */
    $order_item = $attendee->get('order_item')->entity;
    if (!$order_item instanceof OrderItemInterface) {
      throw new NotFoundHttpException();
    }

    if ($this->isExpired((int) $order_item->getCreatedTime())) {
      throw new NotFoundHttpException();
    }

    $ticketStorage = $this->ticketsEntityTypeManager->getStorage('myeventlane_ticket');
    $ticketIds = $ticketStorage->getQuery()
      ->condition('order_item_id', $order_item->id())
      ->accessCheck(FALSE)
      ->execute();

    if ($ticketIds) {
      /** @var \Drupal\myeventlane_tickets\Entity\Ticket[] $candidates */
      $candidates = $ticketStorage->loadMultiple($ticketIds);
      $attendeeEmail = '';
      if ($attendee->hasField('email') && !$attendee->get('email')->isEmpty()) {
        $attendeeEmail = strtolower(trim((string) $attendee->get('email')->value));
      }
      foreach ($candidates as $candidate) {
        if ($candidate->get('holder_email')->isEmpty()) {
          continue;
        }
        $holderEmail = strtolower(trim((string) $candidate->get('holder_email')->value));
        if ($holderEmail !== '' && $holderEmail === $attendeeEmail) {
          if (!$candidate->get('holder_name')->isEmpty()) {
            return $this->respondWithIssuedTicket($candidate);
          }
        }
      }

      if (count($candidates) === 1) {
        $only = reset($candidates);
        if (!$only->get('holder_name')->isEmpty() && !$only->get('holder_email')->isEmpty()) {
          return $this->respondWithIssuedTicket($only);
        }
      }
    }

    return $this->pdfGenerator->generatePdfForOrderItem($order_item);
  }

  /**
   * Returns a PDF for an issued ticket entity after access and expiry checks.
   */
  private function respondWithIssuedTicket($ticket) {
    if (!$this->canAccessTicket($ticket)) {
      throw new AccessDeniedHttpException();
    }
    if ($this->isExpired((int) $ticket->getCreatedTime())) {
      throw new NotFoundHttpException();
    }

    return $this->pdfGenerator->generatePdfForTicket($ticket);
  }

  /**
   * Access control for ticket PDFs.
   */
  private function canAccessTicket(Ticket $ticket): bool {
    $account = $this->currentUser();
    if ($account->hasPermission('administer myeventlane tickets')) {
      return TRUE;
    }

    if ((int) $ticket->get('purchaser_uid')->target_id === (int) $account->id()) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Whether the current user may download a PDF for this attendee record.
   */
  private function canAccessAttendeeForPdf(EventAttendee $attendee): bool {
    $account = $this->currentUser();
    if ($account->hasPermission('administer myeventlane tickets')) {
      return TRUE;
    }

    $uid = (int) $attendee->getOwnerId();
    if ($uid > 0 && $uid === (int) $account->id()) {
      return TRUE;
    }

    if (!$attendee->get('order_item')->isEmpty()) {
      $order_item = $attendee->get('order_item')->entity;
      if ($order_item instanceof OrderItemInterface) {
        $order = $order_item->getOrder();
        if ($order && (int) $order->getCustomerId() === (int) $account->id()) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * Returns TRUE when configured ticket PDF expiry has elapsed.
   */
  private function isExpired(int $created_timestamp): bool {
    $days = (int) ($this->ticketsConfigFactory->get('myeventlane_tickets.settings')->get('pdf_expiry_days') ?? 0);
    if ($days <= 0 || $created_timestamp <= 0) {
      return FALSE;
    }
    $expires_at = $created_timestamp + ($days * 86400);
    return time() > $expires_at;
  }

}
