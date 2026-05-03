<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\myeventlane_event\Service\EventIcalendarBuilder;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Delivers calendar (.ics) files for published event nodes.
 */
final class EventIcsDownloadController extends ControllerBase {

  public function __construct(
    protected EventIcalendarBuilder $icalendarBuilder,
  ) {
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_event.icalendar_builder'),
    );
  }

  /**
   * Returns an iCalendar attachment for viewing or saving client-side.
   */
  public function download(NodeInterface $node): Response {
    if ($node->bundle() !== 'event') {
      throw new NotFoundHttpException();
    }

    $ics = $this->icalendarBuilder->buildCalendar($node);
    if ($ics === NULL) {
      throw new NotFoundHttpException();
    }

    $filename = $this->icalendarBuilder->downloadFilename($node);
    $response = new Response($ics, Response::HTTP_OK, [
      'Content-Type' => 'text/calendar; charset=utf-8',
      // Allow inline landing (some clients open .ics from anchors); filename helps native apps.
      'Content-Disposition' => 'attachment; filename="' . $filename . '"',
      'Cache-Control' => 'private, no-store',
    ]);

    return $response;
  }

}
