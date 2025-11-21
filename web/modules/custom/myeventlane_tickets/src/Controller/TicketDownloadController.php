<?php

namespace Drupal\myeventlane_tickets\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\myeventlane_tickets\Ticket\TicketPdfGenerator;
use Drupal\myeventlane_tickets\Ticket\TicketCodeGenerator;

class TicketDownloadController extends ControllerBase {

  protected $pdf;
  protected $codeGen;

  public function __construct(TicketPdfGenerator $pdf, TicketCodeGenerator $codeGen) {
    $this->pdf = $pdf;
    $this->codeGen = $codeGen;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('myeventlane_tickets.pdf'),
      $container->get('myeventlane_tickets.code')
    );
  }

  /**
   * PDF Ticket.
   */
  public function download($order_item_id) {
    $storage = $this->entityTypeManager()->getStorage('commerce_order_item');
    $order_item = $storage->load($order_item_id);

    $event = $order_item->get('field_event')->entity;

    // Create or load ticket code.
    $ticket_code = $this->loadOrCreateCode($order_item, $event);

    // Generate PDF.
    $pdf_output = $this->pdf->buildPdf($ticket_code->get('code')->value, $event, $order_item);

    $response = new Response($pdf_output);
    $response->headers->set('Content-Type', 'application/pdf');
    $response->headers->set('Content-Disposition', 'attachment; filename="ticket.pdf"');

    return $response;
  }

  protected function loadOrCreateCode($order_item, $event) {
    $storage = $this->entityTypeManager()->getStorage('ticket_code');

    $existing = $storage->loadByProperties([
      'order_item_id' => $order_item->id(),
    ]);

    if ($existing) {
      return reset($existing);
    }

    return $this->codeGen->create($order_item, $event);
  }
}