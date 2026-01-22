<?php

namespace Drupal\myeventlane_rsvp\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Render\RendererInterface;

/**
 *
 */
final class VendorDigestGenerator {

  public function __construct(
    private readonly EntityTypeManagerInterface $etm,
    private readonly MailManagerInterface $mail,
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * Main sender called from queue.
   */
  public function sendDigest(int $vendor_uid): void {
    $events = $this->loadVendorEvents($vendor_uid);

    if (!$events) {
      return;
    }

    $render = [
      '#theme' => 'mel_vendor_digest_email',
      '#events' => [],
    ];

    foreach ($events as $event) {
      /** @var \Drupal\node\NodeInterface $event */

      $start = $event->get('field_event_start')->date?->format('Y-m-d H:i');
      $end = $event->get('field_event_end')->date?->format('Y-m-d H:i');

      $venue = $event->get('field_venue_name')->value ?? '';
      $address = $event->get('field_venue_address')->view('default');

      $render['#events'][] = [
        'title' => $event->label(),
        'url'   => $event->toUrl()->setAbsolute()->toString(),
        'start' => $start,
        'end'   => $end,
        'venue' => $venue,
        'address' => $address,
        'rsvps'  => $this->countRsvps($event->id()),
        'waitlist' => $this->countWaitlist($event->id()),
        'sales'  => $this->countTicketSales($event->id()),
      ];
    }

    $body = $this->renderer->renderRoot($render);

    $this->mail->mail(
      'myeventlane_rsvp',
      'vendor_digest',
      $this->loadVendorEmail($vendor_uid),
      \Drupal::languageManager()->getDefaultLanguage()->getId(),
      [
        'subject' => 'Your Daily Event Digest',
        'body' => $body,
      ]
    );
  }

  /**
   *
   */
  private function loadVendorEvents(int $uid): array {
    $nids = \Drupal::entityQuery('node')
      ->condition('type', 'event')
      ->condition('uid', $uid)
      ->condition('status', 1)
      ->execute();

    return $nids
      ? $this->etm->getStorage('node')->loadMultiple($nids)
      : [];
  }

  /**
   *
   */
  private function loadVendorEmail(int $uid): string {
    $user = $this->etm->getStorage('user')->load($uid);
    return $user?->getEmail() ?: '';
  }

  /**
   *
   */
  private function countRsvps(int $nid): int {
    return \Drupal::entityQuery('rsvp_submission')
      ->condition('event_id', $nid)
      ->condition('status', 'confirmed')
      ->count()
      ->execute();
  }

  /**
   *
   */
  private function countWaitlist(int $nid): int {
    return \Drupal::entityQuery('rsvp_submission')
      ->condition('event_id', $nid)
      ->condition('status', 'waitlist')
      ->count()
      ->execute();
  }

  /**
   *
   */
  private function countTicketSales(int $nid): int {
    $query = \Drupal::database()->select('commerce_order_item', 'oi');
    $query->join('commerce_product_variation', 'pv', 'pv.variation_id = oi.purchased_entity');
    $query->fields('oi', ['order_item_id']);
    $query->condition('pv.field_event', $nid);
    return (int) $query->countQuery()->execute()->fetchField();
  }

}
