<?php

declare(strict_types=1);

namespace Drupal\myeventlane_vendor\Hook;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\node\NodeInterface;

/**
 * Builds preprocess variables for the vendor console page theme hook.
 */
final class VendorConsolePagePreprocess {

  use StringTranslationTrait;

  /**
   * Constructs the vendor console preprocess service.
   */
  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    TranslationInterface $stringTranslation,
    private readonly ?DomainDetector $domainDetector = NULL,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Preprocess callback for myeventlane_vendor_console_page.
   *
   * @param array<string, mixed> $variables
   *   Template variables.
   */
  public function preprocess(array &$variables): void {
    $event = $this->routeMatch->getParameter('event');
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      return;
    }

    $event_id = (int) $event->id();
    $title = $event->label();

    $status_key = 'draft';
    $status_label = $this->t('Draft');
    if ($event->isPublished()) {
      $status_key = 'published';
      $status_label = $this->t('Published');
    }
    if ($event->hasField('moderation_state') && !$event->get('moderation_state')->isEmpty()) {
      $moderation_state = (string) $event->get('moderation_state')->value;
      if ($moderation_state === 'review') {
        $status_key = 'review';
        $status_label = $this->t('In review');
      }
    }

    $checklist = [
      'title' => trim($title) !== '',
      'date' => $event->hasField('field_event_start') && !$event->get('field_event_start')->isEmpty(),
      'image' => $event->hasField('field_event_image') && !$event->get('field_event_image')->isEmpty(),
      'location' => ($event->hasField('field_location') && !$event->get('field_location')->isEmpty())
        || ($event->hasField('field_event_venue') && !$event->get('field_event_venue')->isEmpty())
        || ($event->hasField('field_venue_name') && !$event->get('field_venue_name')->isEmpty()),
      'tickets' => TRUE,
      'promotion' => TRUE,
    ];

    if ($event->hasField('field_ticket_config')) {
      $checklist['tickets'] = !$event->get('field_ticket_config')->isEmpty();
    }
    if ($event->hasField('field_promo_config')) {
      $checklist['promotion'] = !$event->get('field_promo_config')->isEmpty();
    }

    $health_status = $this->t('Ready to publish');
    if (!$checklist['tickets']) {
      $health_status = $this->t('Needs ticket setup');
    }
    elseif (!$checklist['image']) {
      $health_status = $this->t('Missing image');
    }
    elseif (!$checklist['location']) {
      $health_status = $this->t('Missing location');
    }
    elseif (!$checklist['promotion']) {
      $health_status = $this->t('Promotion incomplete');
    }

    $workspace_tabs = [
      ['key' => 'overview', 'route' => 'myeventlane_vendor.console.event_overview', 'label' => $this->t('Overview'), 'url' => Url::fromRoute('myeventlane_vendor.console.event_overview', ['event' => $event_id])->toString()],
      ['key' => 'tickets', 'route' => 'myeventlane_vendor.console.event_tickets', 'label' => $this->t('Tickets'), 'url' => Url::fromRoute('myeventlane_vendor.console.event_tickets', ['event' => $event_id])->toString()],
      ['key' => 'attendees', 'route' => 'myeventlane_event_attendees.vendor_list', 'label' => $this->t('Attendees'), 'url' => Url::fromRoute('myeventlane_event_attendees.vendor_list', ['node' => $event_id])->toString()],
      ['key' => 'orders', 'route' => 'myeventlane_vendor.console.event_orders', 'label' => $this->t('Orders'), 'url' => Url::fromRoute('myeventlane_vendor.console.event_orders', ['event' => $event_id])->toString()],
      ['key' => 'promotion', 'route' => 'myeventlane_vendor.console.event_promotion', 'label' => $this->t('Promotion'), 'url' => Url::fromRoute('myeventlane_vendor.console.event_promotion', ['event' => $event_id])->toString()],
      ['key' => 'analytics', 'route' => 'myeventlane_vendor.console.event_analytics', 'label' => $this->t('Analytics'), 'url' => Url::fromRoute('myeventlane_vendor.console.event_analytics', ['event' => $event_id])->toString()],
      ['key' => 'settings', 'route' => 'myeventlane_vendor.console.event_settings', 'label' => $this->t('Settings'), 'url' => Url::fromRoute('myeventlane_vendor.console.event_settings', ['event' => $event_id])->toString()],
      ['key' => 'publish', 'route' => 'myeventlane_vendor.console.event_publish', 'label' => $this->t('Publish'), 'url' => Url::fromRoute('myeventlane_vendor.console.event_publish', ['event' => $event_id])->toString()],
    ];

    $current_route_name = (string) $this->routeMatch->getRouteName();
    foreach ($workspace_tabs as &$tab) {
      $tab['active'] = ($tab['route'] === $current_route_name);
    }
    unset($tab);

    $variables['workspace'] = [
      'title' => $title,
      'status_key' => $status_key,
      'status_label' => $status_label,
      'health_status' => $health_status,
      'tabs' => $workspace_tabs,
      'checklist' => $checklist,
      'actions' => [
        [
          'label' => $this->t('Save changes'),
          'url' => Url::fromRoute('myeventlane_vendor.console.event_settings', ['event' => $event_id])->toString(),
          'class' => 'mel-btn--secondary',
        ],
        [
          'label' => $this->t('Publish event'),
          'url' => Url::fromRoute('myeventlane_vendor.console.event_publish', ['event' => $event_id])->toString(),
          'class' => 'mel-btn--primary',
        ],
      ],
      'quick_actions' => [
        [
          'label' => $this->t('View event page'),
          'url' => $this->buildPublicEventUrl($event_id),
        ],
        [
          'label' => $this->t('Review attendees'),
          'url' => Url::fromRoute('myeventlane_event_attendees.vendor_list', ['node' => $event_id])->toString(),
        ],
        [
          'label' => $this->t('Open ticket setup'),
          'url' => Url::fromRoute('myeventlane_vendor.console.event_tickets', ['event' => $event_id])->toString(),
        ],
      ],
    ];
  }

  private function buildPublicEventUrl(int $event_id): string {
    $path = Url::fromRoute('entity.node.canonical', ['node' => $event_id])->toString();
    return $this->domainDetector?->publicUrl($path) ?? $path;
  }

}
