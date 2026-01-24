<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_boost\Service\BoostPerformanceGuidePdfGenerator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public controller for the Boost performance PDF guide.
 */
final class BoostPerformanceGuidePdfController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly BoostPerformanceGuidePdfGenerator $pdfGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_boost.performance_guide_pdf'),
    );
  }

  /**
   * Downloads the one-page Boost performance guide PDF.
   */
  public function download(): Response {
    return $this->pdfGenerator->generate();
  }

}
