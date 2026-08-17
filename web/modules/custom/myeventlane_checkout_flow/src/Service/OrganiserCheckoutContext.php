<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;

/**
 * Builds truthful checkout copy for organiser-owned platform purchases.
 */
final class OrganiserCheckoutContext {

  use StringTranslationTrait;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CurrencyFormatterInterface $currencyFormatter,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Returns organiser presentation data, or customer context for other orders.
   *
   * @return array<string, mixed>
   */
  public function build(OrderInterface $order): array {
    $kind = $this->classify($order);
    return match ($kind) {
      'pro' => $this->buildPro($order),
      'boost' => $this->buildBoost($order),
      default => ['kind' => 'customer'],
    };
  }

  /**
   * Classifies only pure Pro and pure Boost carts as organiser purchases.
   */
  private function classify(OrderInterface $order): string {
    $kind = NULL;
    foreach ($order->getItems() as $item) {
      $purchased = $item->getPurchasedEntity();
      $itemKind = NULL;
      if ($purchased instanceof ProductVariationInterface
        && $purchased->bundle() === 'mel_pro_subscription_variation') {
        $itemKind = 'pro';
      }
      elseif ($item->bundle() === 'boost'
        || ($purchased instanceof ProductVariationInterface
          && in_array($purchased->bundle(), ['boost_duration', 'boost_upgrade'], TRUE))) {
        $itemKind = 'boost';
      }

      if ($itemKind === NULL || ($kind !== NULL && $kind !== $itemKind)) {
        return 'customer';
      }
      $kind = $itemKind;
    }
    return $kind ?? 'customer';
  }

  /**
   * @return array<string, mixed>
   */
  private function buildPro(OrderInterface $order): array {
    $trialDays = (int) $this->configFactory
      ->get('commerce_recurring.commerce_billing_schedule.mel_pro_monthly')
      ->get('configuration.trial_interval.number');
    $trialDays = max(0, $trialDays);

    $monthlyPrice = '';
    $items = $order->getItems();
    $firstItem = reset($items) ?: NULL;
    $variation = $firstItem?->getPurchasedEntity();
    if ($variation instanceof ProductVariationInterface && $variation->getPrice() !== NULL) {
      $monthlyPrice = $this->formatPrice($variation->getPrice()->getNumber(), $variation->getPrice()->getCurrencyCode());
    }
    $monthlyPrice = $monthlyPrice !== '' ? $monthlyPrice : (string) $this->t('the displayed monthly price');
    $trialApplies = $trialDays > 0 && ($order->getTotalPrice()?->isZero() ?? FALSE);

    $trialText = $trialApplies
      ? (string) $this->t('@days-day free trial', ['@days' => $trialDays])
      : (string) $this->t('No free trial is applied to this order');
    $chargeTiming = $trialApplies
      ? (string) $this->t('No charge today. Then @price per month after the @days-day trial.', ['@price' => $monthlyPrice, '@days' => $trialDays])
      : (string) $this->t('@price per month, charged when you confirm.', ['@price' => $monthlyPrice]);

    return [
      'kind' => 'pro',
      'hero' => [
        'heading' => (string) $this->t('Organiser checkout'),
        'intro_primary' => (string) $this->t('Start MyEventLane Pro'),
        'intro_secondary' => (string) $this->t('Confirm your organiser billing details and securely save a card for your Pro subscription.'),
        'flow_helper' => $chargeTiming,
        'error_summary' => (string) $this->t('Please complete the required billing details to continue.'),
      ],
      'trust_chips' => [
        ['text' => $trialText],
        ['text' => (string) $this->t('@price per month after the trial', ['@price' => $monthlyPrice])],
        ['text' => (string) $this->t('Self-service cancellation before renewal')],
      ],
      'step_buyer' => [
        'eyebrow' => (string) $this->t('Step 1'),
        'heading' => (string) $this->t('Organiser billing details'),
        'intro' => (string) $this->t('We use these details for your Pro account, invoices and receipts.'),
      ],
      'step_payment' => [
        'eyebrow' => (string) $this->t('Step 2'),
        'heading' => (string) $this->t('Secure card setup'),
        'intro' => $trialApplies
          ? (string) $this->t('Save a card securely with Stripe. You will not be charged today.')
          : (string) $this->t('Enter your card securely with Stripe to start Pro.'),
      ],
      'payment_trust_lines' => [
        'text' => (string) $this->t('Secure card setup powered by Stripe'),
        'stripe_note' => (string) $this->t('Your card details are handled by Stripe'),
        'charge_timing' => $chargeTiming,
      ],
      'cta_secure_note' => $trialApplies
        ? (string) $this->t('No charge today. Cancel before the trial ends to avoid renewal.')
        : (string) $this->t('Your first monthly payment will be processed securely.'),
      'summary_title' => (string) $this->t('Pro subscription summary'),
      'purchase_details' => [
        'heading' => (string) $this->t('What this subscription covers'),
        'rows' => [
          ['label' => (string) $this->t('Plan'), 'value' => (string) $this->t('MyEventLane Pro organiser plan')],
          ['label' => (string) $this->t('Free period'), 'value' => $trialText],
          ['label' => (string) $this->t('Ongoing payment'), 'value' => (string) $this->t('@price each month', ['@price' => $monthlyPrice])],
          ['label' => (string) $this->t('Renewal'), 'value' => (string) $this->t('Renews monthly until cancelled')],
        ],
        'note' => (string) $this->t('Manage billing, invoices, receipts and cancellation from your Pro account.'),
      ],
      'trust' => [
        'heading' => (string) $this->t('Your Pro payment'),
        'line_secure' => (string) $this->t('Card details are processed securely by Stripe'),
        'line_instant' => $chargeTiming,
        'line_refund' => (string) $this->t('Manage renewal and cancellation from your Pro account'),
      ],
      'confidence' => [
        'secure' => (string) $this->t('Secure card setup processed by Stripe'),
        'instant' => $chargeTiming,
        'calendar_hint' => (string) $this->t('Invoices, receipts and cancellation are available from your Pro account'),
      ],
      'completion' => [
        'heading' => $trialApplies
          ? (string) $this->t('Your Pro trial has started')
          : (string) $this->t('Your Pro subscription is active'),
        'lead' => $trialApplies
          ? (string) $this->t('You have @days days free. Then Pro renews at @price per month until cancelled.', ['@days' => $trialDays, '@price' => $monthlyPrice])
          : (string) $this->t('Pro renews at @price per month until cancelled.', ['@price' => $monthlyPrice]),
        'summary_eyebrow' => (string) $this->t('Pro subscription'),
        'summary_title' => (string) $this->t('Your MyEventLane Pro plan'),
        'what_next_title' => (string) $this->t('What happens next'),
        'what_next_body' => $trialApplies
          ? (string) $this->t('Your Pro tools are available now. Your saved card will be charged after the free period unless you cancel beforehand.')
          : (string) $this->t('Your Pro tools are available now. Future monthly payments use your saved card.'),
        'support_title' => (string) $this->t('Manage your subscription'),
        'support_body' => (string) $this->t('View renewal status, update your card, download invoices and receipts, or schedule cancellation from your Pro account.'),
        'primary_label' => (string) $this->t('Manage MyEventLane Pro'),
        'primary_url' => $this->routeUrl('myeventlane_pro.manage', '/vendor/pro/manage'),
        'secondary_label' => (string) $this->t('Return to Organiser Studio'),
        'secondary_url' => $this->routeUrl('myeventlane_vendor.console.dashboard', '/vendor/dashboard'),
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function buildBoost(OrderInterface $order): array {
    $days = 0;
    $eventTitle = '';
    $eventId = 0;
    foreach ($order->getItems() as $item) {
      if ($item->hasField('field_boost_days_purchased')) {
        $days += (int) ($item->get('field_boost_days_purchased')->value ?? 0);
      }
      if ($eventTitle === '' && $item->hasField('field_target_event') && !$item->get('field_target_event')->isEmpty()) {
        $event = $item->get('field_target_event')->entity;
        $eventTitle = (string) $event->label();
        $eventId = (int) $event->id();
      }
    }
    $total = $order->getTotalPrice();
    $price = $total !== NULL ? $this->formatPrice($total->getNumber(), $total->getCurrencyCode()) : '';
    $duration = $days > 0
      ? (string) $this->formatPlural($days, '1 day of promotion', '@count days of promotion')
      : (string) $this->t('Selected Boost duration');
    $target = $eventTitle !== '' ? $eventTitle : (string) $this->t('Your selected event');

    return [
      'kind' => 'boost',
      'hero' => [
        'heading' => (string) $this->t('Organiser checkout'),
        'intro_primary' => (string) $this->t('Boost your event'),
        'intro_secondary' => (string) $this->t('Confirm your billing details and make a one-off payment to promote @event.', ['@event' => $target]),
        'flow_helper' => (string) $this->t('This is a one-off Boost purchase. There are no recurring charges.'),
        'error_summary' => (string) $this->t('Please complete the required billing details to continue.'),
      ],
      'trust_chips' => [
        ['text' => (string) $this->t('One-off payment')],
        ['text' => $duration],
        ['text' => (string) $this->t('No recurring charges')],
      ],
      'step_buyer' => [
        'eyebrow' => (string) $this->t('Step 1'),
        'heading' => (string) $this->t('Organiser billing details'),
        'intro' => (string) $this->t('We use these details for your Boost receipt and organiser account.'),
      ],
      'step_payment' => [
        'eyebrow' => (string) $this->t('Step 2'),
        'heading' => (string) $this->t('Pay for your Boost'),
        'intro' => (string) $this->t('Make the one-off payment securely with Stripe.'),
      ],
      'payment_trust_lines' => [
        'text' => (string) $this->t('Secure payment powered by Stripe'),
        'stripe_note' => (string) $this->t('Your card details are handled by Stripe'),
        'charge_timing' => (string) $this->t('@price will be charged once when you confirm.', ['@price' => $price]),
      ],
      'cta_secure_note' => (string) $this->t('One-off payment. No subscription or automatic renewal.'),
      'summary_title' => (string) $this->t('Boost purchase summary'),
      'purchase_details' => [
        'heading' => (string) $this->t('What this payment covers'),
        'rows' => [
          ['label' => (string) $this->t('Event'), 'value' => $target],
          ['label' => (string) $this->t('Promotion period'), 'value' => $duration],
          ['label' => (string) $this->t('Payment'), 'value' => (string) $this->t('@price once', ['@price' => $price])],
          ['label' => (string) $this->t('Renewal'), 'value' => (string) $this->t('No recurring charge')],
        ],
        'note' => (string) $this->t('Your Boost is applied to the selected event after payment is confirmed.'),
      ],
      'trust' => [
        'heading' => (string) $this->t('Your Boost payment'),
        'line_secure' => (string) $this->t('Payment is processed securely by Stripe'),
        'line_instant' => (string) $this->t('The Boost is applied after payment is confirmed'),
        'line_refund' => (string) $this->t('This is a one-off purchase with no automatic renewal'),
      ],
      'confidence' => [
        'secure' => (string) $this->t('Secure one-off payment processed by Stripe'),
        'instant' => (string) $this->t('Your Boost is applied after payment is confirmed'),
        'calendar_hint' => (string) $this->t('A receipt is sent to your organiser email'),
      ],
      'completion' => [
        'heading' => (string) $this->t('Your event Boost is confirmed'),
        'lead' => (string) $this->t('@event will be promoted for @duration.', ['@event' => $target, '@duration' => $duration]),
        'summary_eyebrow' => (string) $this->t('Boost purchase'),
        'summary_title' => (string) $this->t('Promotion confirmed for @event', ['@event' => $target]),
        'what_next_title' => (string) $this->t('What happens next'),
        'what_next_body' => (string) $this->t('Your Boost is applied after payment confirmation. This was a one-off purchase with no recurring charge.'),
        'support_title' => (string) $this->t('Track your promotion'),
        'support_body' => (string) $this->t('Return to your event Boost page to review the promotion period and available Boost options.'),
        'primary_label' => (string) $this->t('Manage this Boost'),
        'primary_url' => $eventId > 0
          ? $this->routeUrl('myeventlane_boost.boost_page', '/vendor', ['node' => $eventId])
          : '/vendor',
        'secondary_label' => (string) $this->t('Return to Organiser Studio'),
        'secondary_url' => $this->routeUrl('myeventlane_vendor.console.dashboard', '/vendor/dashboard'),
      ],
    ];
  }

  /**
   * Generates a local route URL with a safe fallback for optional modules.
   *
   * @param array<string, mixed> $parameters
   *   Route parameters.
   */
  private function routeUrl(string $routeName, string $fallback, array $parameters = []): string {
    try {
      return Url::fromRoute($routeName, $parameters)->toString();
    }
    catch (\Throwable) {
      return $fallback;
    }
  }

  private function formatPrice(string $number, string $currency): string {
    return $this->currencyFormatter->format($number, $currency, [
      'currency_display' => 'symbol',
      'minimum_fraction_digits' => 2,
      'maximum_fraction_digits' => 2,
      'locale' => 'en-AU',
    ]);
  }

}
