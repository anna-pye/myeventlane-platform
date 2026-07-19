<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Plugin\Commerce\CheckoutFlow;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowWithPanesBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a checkout flow for MyEventLane events.
 *
 * Buyer/attendee details stay on a single checkout step. A dedicated review
 * step is required by Commerce Stripe Payment Element (hard-coded return URL
 * step ID "review" and stripe_review pane default_step).
 *
 * @CommerceCheckoutFlow(
 *   id = "mel_event_checkout",
 *   label = @Translation("MyEventLane Event Checkout"),
 * )
 */
final class MelEventCheckoutFlow extends CheckoutFlowWithPanesBase {

  /**
   * {@inheritdoc}
   */
  public function getSteps(): array {
    $steps = parent::getSteps();
    // Commerce sets the primary submit label from the *destination* step's
    // next_label (see CheckoutFlowBase::actions()), not the current step's.
    $steps['complete']['next_label'] = $this->t('Complete booking');

    return [
      'checkout' => [
        'label' => $this->t('Checkout'),
        'previous_label' => $this->t('Back to cart'),
        'next_label' => $this->t('Continue to checkout'),
        'has_sidebar' => TRUE,
      ],
      // Machine ID must remain "review": Commerce Stripe hardcodes
      // step => 'review' in Payment Element returnUrl, PaymentOffsiteForm,
      // and Express Checkout. Label is "Payment" to keep customer UX clear.
      'review' => [
        'label' => $this->t('Payment'),
        'next_label' => $this->t('Continue to payment'),
        'previous_label' => $this->t('Back to details'),
        'has_sidebar' => TRUE,
      ],
    ] + $steps;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $step_id = NULL): array {
    $form = parent::buildForm($form, $form_state, $step_id);

    // Add wrapper classes for single-page styling on the details step.
    $form['#attributes']['class'][] = 'mel-checkout-flow-mel-event';
    if ($step_id === 'checkout') {
      $form['#attributes']['class'][] = 'mel-checkout-single-page';
    }
    if ($step_id === 'review') {
      $form['#attributes']['class'][] = 'mel-checkout-payment-step';
    }

    return $form;
  }

}
