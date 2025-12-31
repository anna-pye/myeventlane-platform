<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Plugin\Commerce\CheckoutFlow;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowWithPanesBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a single-page checkout flow for MyEventLane events.
 *
 * This flow presents all checkout panes in a single step with a sidebar
 * for order summary and fee transparency, creating a "single page feel"
 * similar to Humanitix.
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
    return [
      'checkout' => [
        'label' => $this->t('Checkout'),
        'previous_label' => $this->t('Go back'),
        'has_sidebar' => TRUE,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $step_id = NULL): array {
    $form = parent::buildForm($form, $form_state, $step_id);

    // Add wrapper classes for single-page styling.
    $form['#attributes']['class'][] = 'mel-checkout-single-page';
    $form['#attributes']['class'][] = 'mel-checkout-flow-mel-event';

    return $form;
  }

}

