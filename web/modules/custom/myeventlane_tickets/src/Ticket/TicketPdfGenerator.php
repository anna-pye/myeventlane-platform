<?php

namespace Drupal\myeventlane_tickets\Ticket;

use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Render\RendererInterface;

class TicketPdfGenerator {

  protected $renderer;
  protected $themeHandler;

  public function __construct(RendererInterface $renderer, ThemeHandlerInterface $theme_handler) {
    $this->renderer = $renderer;
    $this->themeHandler = $theme_handler;
  }

  public function buildPdf($ticket_code, $event, $holder) {
    $render = [
      '#theme' => 'ticket_pdf',
      '#event' => $event,
      '#holder' => $holder,
      '#code' => $ticket_code,
    ];

    $html = $this->renderer->renderPlain($render);

    // DomPDF recommended for Drupal
    $pdf = new \Dompdf\Dompdf();
    $pdf->loadHtml($html);
    $pdf->render();

    return $pdf->output();
  }

}