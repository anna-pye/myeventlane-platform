<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates optional vendor → MyEventLane donations from the event wizard.
 *
 * Reuses PlatformDonationService and platform_donation orders only; no parallel
 * payment flows. Percentage pledges are stored on the event only (future billing).
 */
final class VendorEventMelSupportService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly PlatformDonationService $platformDonationService,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  private function logger(): LoggerInterface {
    return $this->loggerFactory->get('myeventlane_donations');
  }

  /**
   * Applies wizard POST values for MEL support fields to the event entity.
   *
   * Only updates when mel_mel_support form values are present (current step).
   */
  public function applyMelSupportFormValues(NodeInterface $event, array $form_state_values): void {
    if (!$this->fieldsExist($event)) {
      return;
    }
    $support = $form_state_values['mel_mel_support'] ?? NULL;
    if (!is_array($support)) {
      return;
    }

    $mode = (string) ($support['mode'] ?? 'none');
    $eventType = $event->get('field_event_type')->value ?? 'rsvp';
    $allowed = $this->allowedModesForEventType((string) $eventType);
    if (!in_array($mode, $allowed, TRUE)) {
      $mode = 'none';
    }

    $event->set('field_mel_sup_mode', $mode);

    // If the vendor moves away from a one-time checkout before paying, clear the
    // pending marker so we do not block a future attempt (completed is preserved).
    if ($mode !== 'onetime') {
      $prev = (string) ($event->get('field_mel_sup_chk')->value ?? 'none');
      if ($prev !== 'completed') {
        $event->set('field_mel_sup_chk', 'none');
        $event->set('field_mel_sup_oid', []);
      }
    }

    if ($mode === 'onetime') {
      $raw = $support['amount'] ?? '';
      $amount = is_numeric($raw) ? (float) $raw : 0.0;
      if ($amount > 0) {
        $event->set('field_mel_sup_amt', number_format($amount, 2, '.', ''));
      }
      else {
        $event->set('field_mel_sup_amt', []);
      }
      $event->set('field_mel_sup_pct', []);
    }
    elseif ($mode === 'percent') {
      $raw = $support['percent'] ?? '';
      $pct = is_numeric($raw) ? (float) $raw : 0.0;
      if ($pct > 0) {
        $event->set('field_mel_sup_pct', number_format($pct, 2, '.', ''));
      }
      else {
        $event->set('field_mel_sup_pct', []);
      }
      $event->set('field_mel_sup_amt', []);
    }
    else {
      $event->set('field_mel_sup_amt', []);
      $event->set('field_mel_sup_pct', []);
    }
  }

  /**
   * After a successful publish, returns checkout URL if a one-time donation should start.
   *
   * Creates or reuses a platform_donation Commerce order; never touches attendee orders.
   */
  public function getPostPublishCheckoutUrl(NodeInterface $event, int $vendorUid): ?Url {
    $donationConfig = $this->configFactory->get('myeventlane_donations.settings');
    if (!(bool) ($donationConfig->get('enable_platform_donations') ?? TRUE)) {
      return NULL;
    }

    if (!$this->fieldsExist($event)) {
      return NULL;
    }

    $mode = (string) ($event->get('field_mel_sup_mode')->value ?? 'none');
    if ($mode !== 'onetime') {
      return NULL;
    }

    if ($event->get('field_mel_sup_amt')->isEmpty()) {
      return NULL;
    }

    $amount = (float) $event->get('field_mel_sup_amt')->value;
    if ($amount <= 0) {
      return NULL;
    }

    $min = (float) ($donationConfig->get('min_amount') ?? 1.0);
    if ($amount < $min) {
      return NULL;
    }

    $checkoutState = (string) ($event->get('field_mel_sup_chk')->value ?? 'none');
    if ($checkoutState === 'completed') {
      return NULL;
    }

    $eventId = (int) $event->id();
    $orderId = !$event->get('field_mel_sup_oid')->isEmpty()
      ? (int) $event->get('field_mel_sup_oid')->value
      : 0;

    if ($checkoutState === 'pending' && $orderId > 0) {
      $existing = $this->loadPlatformDonationOrder($orderId);
      if ($existing) {
        if ($existing->isPaid()) {
          $this->markEventCheckoutCompleted($event);
          return NULL;
        }
        if ($this->orderMatchesWizardAmount($existing, $amount)) {
          return Url::fromRoute('commerce_checkout.form', [
            'commerce_order' => $existing->id(),
          ]);
        }
      }
    }

    try {
      $order = $this->platformDonationService->createDonationOrder($vendorUid, $amount, $eventId, TRUE);
    }
    catch (\Throwable $e) {
      $this->logger()->error('Vendor event wizard: failed to create platform donation order for event @nid: @message', [
        '@nid' => (string) $eventId,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }

    $event->set('field_mel_sup_chk', 'pending');
    $event->set('field_mel_sup_oid', (int) $order->id());
    $event->save();

    return Url::fromRoute('commerce_checkout.form', [
      'commerce_order' => $order->id(),
    ]);
  }

  /**
   * Marks event checkout completed (e.g. after payment). Public for subscriber use.
   */
  public function markEventCheckoutCompleted(NodeInterface $event): void {
    if (!$this->fieldsExist($event)) {
      return;
    }
    $event->set('field_mel_sup_chk', 'completed');
    $event->save();
  }

  /**
   * Allowed list_string values for the current event type.
   *
   * @return array<string>
   */
  public function allowedModesForEventType(string $eventType): array {
    if (in_array($eventType, ['paid', 'both'], TRUE)) {
      return ['none', 'onetime', 'percent'];
    }
    return ['none', 'onetime'];
  }

  private function fieldsExist(NodeInterface $event): bool {
    return $event->hasField('field_mel_sup_mode')
      && $event->hasField('field_mel_sup_amt')
      && $event->hasField('field_mel_sup_pct')
      && $event->hasField('field_mel_sup_chk')
      && $event->hasField('field_mel_sup_oid');
  }

  private function loadPlatformDonationOrder(int $orderId): ?OrderInterface {
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($orderId);
    return $order instanceof OrderInterface && $order->bundle() === 'platform_donation' ? $order : NULL;
  }

  private function orderMatchesWizardAmount(OrderInterface $order, float $expectedAmount): bool {
    foreach ($order->getItems() as $item) {
      if ($item->bundle() !== 'platform_donation') {
        continue;
      }
      $price = $item->getTotalPrice();
      if ($price && abs((float) $price->getNumber() - $expectedAmount) < 0.005) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
