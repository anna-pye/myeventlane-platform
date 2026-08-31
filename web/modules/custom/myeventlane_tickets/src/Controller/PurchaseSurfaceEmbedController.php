<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_tickets\Entity\PurchaseSurface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Delivers the public JavaScript used by ticket widgets on external sites.
 */
final class PurchaseSurfaceEmbedController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $ticketEntityTypeManager,
  ) {}

  /**
   * Returns a script linking guests to the event's secure booking page.
   */
  public function script(string $token): Response {
    $storage = $this->ticketEntityTypeManager->getStorage('mel_purchase_surface');
    $matches = $storage->loadByProperties([
      'embed_token' => $token,
      'status' => 1,
    ]);
    $surface = $matches ? reset($matches) : NULL;
    if (!$surface instanceof PurchaseSurface) {
      throw new NotFoundHttpException();
    }

    $event = $surface->getEvent();
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event' || !$event->isPublished() || !$event->access('view')) {
      throw new NotFoundHttpException();
    }

    $payload = [
      'eventTitle' => $event->label(),
      'eventUrl' => Url::fromRoute('entity.node.canonical', ['node' => $event->id()], ['absolute' => TRUE])->toString(),
      'label' => $surface->getLabel(),
      'type' => $surface->getSurfaceType(),
    ];
    $json = json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
      throw new NotFoundHttpException();
    }

    $javascript = <<<JS
(function () {
  'use strict';
  var config = {$json};
  var script = document.currentScript;
  if (!script || !script.parentNode) { return; }

  var link = document.createElement('a');
  link.href = config.eventUrl;
  link.target = '_blank';
  link.rel = 'noopener noreferrer';
  link.setAttribute('aria-label', 'View ' + config.eventTitle + ' and book on MyEventLane');
  link.style.boxSizing = 'border-box';
  link.style.color = '#293241';
  link.style.fontFamily = 'Nunito, Inter, system-ui, sans-serif';
  link.style.textDecoration = 'none';

  if (config.type === 'popup') {
    link.textContent = 'View event and book';
    link.style.display = 'inline-flex';
    link.style.alignItems = 'center';
    link.style.justifyContent = 'center';
    link.style.minHeight = '48px';
    link.style.padding = '12px 20px';
    link.style.borderRadius = '14px';
    link.style.background = '#f26d5b';
    link.style.color = '#ffffff';
    link.style.fontWeight = '700';
  }
  else {
    link.style.display = 'flex';
    link.style.flexDirection = 'column';
    link.style.gap = '8px';
    link.style.maxWidth = config.type === 'collection' ? '360px' : '520px';
    link.style.padding = '18px';
    link.style.border = '1px solid #eadfd7';
    link.style.borderRadius = '18px';
    link.style.background = '#fffaf6';
    link.style.boxShadow = '0 10px 28px rgba(41, 50, 65, 0.08)';

    var eyebrow = document.createElement('span');
    eyebrow.textContent = 'Book on MyEventLane';
    eyebrow.style.color = '#f26d5b';
    eyebrow.style.fontSize = '12px';
    eyebrow.style.fontWeight = '800';
    eyebrow.style.letterSpacing = '0.08em';
    eyebrow.style.textTransform = 'uppercase';

    var title = document.createElement('strong');
    title.textContent = config.eventTitle;
    title.style.fontSize = '20px';
    title.style.lineHeight = '1.25';

    var action = document.createElement('span');
    action.textContent = 'View event and book →';
    action.style.color = '#f26d5b';
    action.style.fontWeight = '800';

    link.appendChild(eyebrow);
    link.appendChild(title);
    link.appendChild(action);
  }

  script.parentNode.insertBefore(link, script.nextSibling);
}());
JS;

    return new Response($javascript, 200, [
      'Content-Type' => 'application/javascript; charset=UTF-8',
      'Cache-Control' => 'public, max-age=300',
      'Access-Control-Allow-Origin' => '*',
      'X-Content-Type-Options' => 'nosniff',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

}
