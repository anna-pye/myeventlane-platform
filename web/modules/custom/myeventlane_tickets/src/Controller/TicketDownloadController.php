<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Controller;

use Drupal\Core\Controller\ControllerBase;
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
  ) {}

  /**
   *
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('myeventlane_tickets.ticket_pdf_generator'),
    );
  }

  /**
   * LEGACY: Download ticket PDF by order item ID.
   *
   * ⚠️ One order item may represent multiple tickets.
   * Generates a combined PDF for backward compatibility only.
   */
  public function download(int $order_item_id) {
    $order_item = $this->entityTypeManager()
      ->getStorage('commerce_order_item')
      ->load($order_item_id);

    if (!$order_item) {
      throw new NotFoundHttpException();
    }

    $order = $order_item->getOrder();
    if (!$order || (int) $order->getCustomerId() !== (int) $this->currentUser()->id()) {
      throw new AccessDeniedHttpException();
    }

    return $this->pdfGenerator->generatePdfForOrderItem($order_item);
  }

  /**
   * CANONICAL v2: Download ticket PDF by ticket code.
   *
   * Route: /ticket/{ticket_code}/pdf.
   */
  public function downloadByCode(string $ticket_code) {
    $storage = $this->entityTypeManager()->getStorage('myeventlane_ticket');

    $ids = $storage->getQuery()
      ->condition('ticket_code', $ticket_code)
      ->accessCheck(FALSE)
      ->execute();

    if (!$ids) {
      throw new NotFoundHttpException();
    }

    $ticket = $storage->load(reset($ids));

    if (!$this->canAccessTicket($ticket)) {
      throw new AccessDeniedHttpException();
    }

    return $this->pdfGenerator->generatePdfForTicket($ticket);
  }

  /**
   * Access control for ticket PDFs.
   */
  private function canAccessTicket($ticket): bool {
    $account = $this->currentUser();
    if ($account->hasPermission('administer myeventlane tickets')) {
      return TRUE;
    }

    if ((int) $ticket->get('purchaser_uid')->target_id === (int) $account->id()) {
      return TRUE;
    }

    return FALSE;
  }

}
