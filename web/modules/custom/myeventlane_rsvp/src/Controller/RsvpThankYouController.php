<?php

declare(strict_types=1);

namespace Drupal\myeventlane_rsvp\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RsvpThankYouController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManagerService,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
    );
  }

  public function page(RouteMatchInterface $route_match): array {
    // Adjust this if your route uses a different param name.
    $event_param = $route_match->getParameter('event');
    if (!$event_param) {
      throw new NotFoundHttpException();
    }

    // Handle both Node object and ID.
    if ($event_param instanceof NodeInterface) {
      $event = $event_param;
    }
    else {
      $event = $this->entityTypeManagerService->getStorage('node')->load((int) $event_param);
      if (!$event instanceof NodeInterface) {
        throw new NotFoundHttpException();
      }
    }

    $event_link = NULL;
    try {
      $url = $event->toUrl();
      $event_link = Link::fromTextAndUrl('View event', $url)->toRenderable();
      $event_link['#attributes']['class'][] = 'mel-link';
    }
    catch (\Throwable) {
      // No link if URL building fails.
      $event_link = NULL;
    }

    return [
      '#theme' => 'mel_rsvp_thankyou',
      '#title' => 'RSVP confirmed',
      '#message' => 'You are booked in. Check your email for confirmation.',
      '#event_title' => $event->label(),
      '#event_link' => $event_link,
      // If you already compute donation state elsewhere, pass it here.
      '#donation_enabled' => FALSE,
      '#cache' => [
        'tags' => $event->getCacheTags(),
        'contexts' => ['url', 'user'],
      ],
    ];
  }

}
