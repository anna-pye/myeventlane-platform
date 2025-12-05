<?php

namespace Drupal\myeventlane_tickets\Ticket;

use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Render\RendererInterface;
use Dompdf\Dompdf;

/**
 * Generates ticket PDFs.
 */
class TicketPdfGenerator {

  /**
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * @var \Drupal\Core\Extension\ThemeHandlerInterface
   */
  protected $themeHandler;

  public function __construct(RendererInterface $renderer, ThemeHandlerInterface $theme_handler) {
    $this->renderer = $renderer;
    $this->themeHandler = $theme_handler;
  }

  /**
   * Build PDF output for a ticket.
   */
  public function buildPdf(string $ticket_code, $event, string $holder) : string {
    // Extract event data to avoid render array issues with entity fields.
    $event_title = $event->label();
    
    // Extract event start date.
    $event_start = NULL;
    if ($event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty()) {
      $event_start = $event->get('field_event_start')->value;
    }
    
    // Extract location address as formatted string.
    $location = '';
    if ($event->hasField('field_location') && !$event->get('field_location')->isEmpty()) {
      $address_field = $event->get('field_location')->first();
      if ($address_field) {
        $address = $address_field->getValue();
        $parts = [];
        if (!empty($address['address_line1'])) {
          $parts[] = $address['address_line1'];
        }
        if (!empty($address['address_line2'])) {
          $parts[] = $address['address_line2'];
        }
        if (!empty($address['locality'])) {
          $parts[] = $address['locality'];
        }
        if (!empty($address['administrative_area'])) {
          $parts[] = $address['administrative_area'];
        }
        if (!empty($address['postal_code'])) {
          $parts[] = $address['postal_code'];
        }
        $location = implode(', ', $parts);
      }
    }

    $render = [
      '#theme' => 'ticket_pdf',
      '#event_title' => $event_title,
      '#event_start' => $event_start,
      '#location' => $location,
      '#holder' => $holder,
      '#code'  => $ticket_code,
    ];

    $html = $this->renderer->renderInIsolation($render);

    $dompdf = new Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->render();

    return $dompdf->output();
  }

}