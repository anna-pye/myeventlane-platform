<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Service;

use Dompdf\Dompdf;
use Drupal\Core\Render\RendererInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Generates the public Boost performance PDF guide.
 */
final class BoostPerformanceGuidePdfGenerator {

  /**
   * Constructs the generator.
   */
  public function __construct(
    private readonly RendererInterface $renderer,
    private readonly BoostHelpContent $helpContent,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Builds the PDF response.
   */
  public function generate(): Response {
    $filename = 'boost-performance-guide.pdf';

    try {
      $content = $this->helpContent->getPdfGuideContent();
      $tooltips = $this->helpContent->getInlineTooltipCopy();

      $build = [
        '#theme' => 'boost_performance_guide_pdf',
        '#content' => $content,
        '#tooltips' => $tooltips,
      ];

      $html = $this->renderer->renderInIsolation($build);

      $dompdf = new Dompdf(['isHtml5ParserEnabled' => TRUE]);
      $dompdf->loadHtml($html);
      $dompdf->setPaper('A4', 'portrait');
      $dompdf->render();
      $pdfContent = $dompdf->output();
    }
    catch (\Throwable $e) {
      $this->logger->error('Boost performance guide PDF generation failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }

    $response = new Response($pdfContent);
    $response->headers->set('Content-Type', 'application/pdf');
    $response->headers->set(
      'Content-Disposition',
      ResponseHeaderBag::DISPOSITION_ATTACHMENT . '; filename="' . $filename . '"'
    );

    return $response;
  }

}
