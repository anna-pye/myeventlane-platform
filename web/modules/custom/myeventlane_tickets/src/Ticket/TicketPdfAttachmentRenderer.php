<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Ticket;

use Drupal\Core\Render\RendererInterface;
use Dompdf\Dompdf;

/**
 * Renders the ticket_pdf theme build array into PDF bytes (Dompdf).
 *
 * Shared by canonical ticket PDF generation and legacy compatibility adapters.
 */
final class TicketPdfAttachmentRenderer {

  public function __construct(
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * Renders a ticket_pdf render array to raw PDF bytes.
   *
   * @param array<string, mixed> $build
   *   A render array using theme ticket_pdf.
   */
  public function renderBytes(array $build): string {
    $html = $this->renderer->renderInIsolation($build);

    $dompdf = new Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->render();

    return $dompdf->output();
  }

}
