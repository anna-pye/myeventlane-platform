<?php

declare(strict_types=1);

/**
 * Disable duplicate summary panes; keep order_summary + coupon only (Plan B).
 */
function myeventlane_checkout_flow_post_update_checkout_shell(array &$sandbox): void {
  $config = \Drupal::configFactory()->getEditable('commerce_checkout.commerce_checkout_flow.mel_event_checkout');
  $panes = $config->get('configuration.panes') ?? [];

  $updates = [
    'grouped_order_summary' => ['step' => '_disabled', 'weight' => 98],
    'mel_fee_transparency' => ['step' => '_disabled', 'weight' => 99],
    'coupon_redemption' => ['step' => '_sidebar', 'weight' => 0],
    'order_summary' => ['step' => '_sidebar', 'weight' => 1],
  ];

  foreach ($updates as $pane_id => $override) {
    if (isset($panes[$pane_id])) {
      $panes[$pane_id] = array_merge($panes[$pane_id], $override);
    }
  }

  $config->set('configuration.panes', $panes);
  $config->save();
}

/**
 * Switch default order type to use mel_event_checkout flow.
 *
 * Orders were using the default Commerce flow (order_information, payment, etc.)
 * instead of mel_event_checkout (single checkout step). This wires the default
 * order type to mel_event_checkout so our pane config takes effect.
 */
function myeventlane_checkout_flow_post_update_use_mel_event_checkout(array &$sandbox): void {
  $config = \Drupal::configFactory()->getEditable('commerce_order.commerce_order_type.default');
  $third_party = $config->get('third_party_settings.commerce_checkout') ?? [];
  $third_party['checkout_flow'] = 'mel_event_checkout';
  $config->set('third_party_settings.commerce_checkout', $third_party);
  $config->save();
}

/**
 * Enable the MEL Stripe review pane on the required review step.
 *
 * Commerce Stripe 2.2.1 attaches Payment Element from a Stripe review pane and
 * hardcodes return URLs to step "review". MEL's subclass also permits the
 * zero-value SetupIntent used to collect a card for a Pro trial.
 */
function myeventlane_checkout_flow_post_update_enable_stripe_review(array &$sandbox): void {
  $config = \Drupal::configFactory()->getEditable('commerce_checkout.commerce_checkout_flow.mel_event_checkout');
  $panes = $config->get('configuration.panes') ?? [];

  $existing = $panes['mel_stripe_review'] ?? $panes['stripe_review'] ?? [];
  unset($panes['stripe_review']);
  $panes['mel_stripe_review'] = array_merge([
    'display_label' => NULL,
    'wrapper_element' => 'container',
    'button_id' => 'edit-actions-next',
    'auto_submit_review_form' => FALSE,
    'setup_future_usage' => '',
  ], $existing, [
    'step' => 'review',
    'weight' => 0,
  ]);

  $panes['payment_process'] = array_merge([
    'display_label' => NULL,
    'wrapper_element' => 'container',
    'capture' => TRUE,
  ], $panes['payment_process'] ?? [], [
    'step' => 'payment',
    'weight' => 8,
  ]);

  $config->set('configuration.panes', $panes);
  $config->save();
}
