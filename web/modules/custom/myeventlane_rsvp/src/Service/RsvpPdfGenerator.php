<?php

namespace Drupal\myeventlane_rsvp\Service;

use Dompdf\Dompdf;
use Symfony\Component\HttpFoundation\Response;

/**
 *
 */
final class RsvpPdfGenerator {

  /**
   *
   */
  public function generate(string $html, string $filename): Response {
    $dompdf = new Dompdf(['isHtml5ParserEnabled' => TRUE]);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    return new Response(
      $dompdf->output(),
      200,
      [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => "inline; filename=\"$filename\"",
      ]
    );
  }

}
