<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Disabled legacy checkout donation pane.
 *
 * Per-order booking contributions are stored as Commerce order adjustments by
 * the ticket selection form. This pane remains only so stale active checkout
 * configuration cannot recreate legacy donation order items.
 *
 * @CommerceCheckoutPane(
 *   id = "mel_donation",
 *   label = @Translation("Donation"),
 *   default_step = "checkout",
 *   wrapper_element = "fieldset",
 * )
 */
final class DonationPane extends CheckoutPaneBase {

  /**
   * {@inheritdoc}
   */
  public function isVisible(): bool {
    // Keep the plugin registered for legacy flow config, but never expose an
    // empty donation control (or its Twig heading wrapper) on checkout.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form): array {
    $pane_form['#access'] = FALSE;
    return $pane_form;
  }

  /**
   * {@inheritdoc}
   */
  public function validatePaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
  }

  /**
   * {@inheritdoc}
   */
  public function submitPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
  }

}
