<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides legal consent pane with required checkbox.
 *
 * @CommerceCheckoutPane(
 *   id = "mel_legal_consent",
 *   label = @Translation("Terms and conditions"),
 *   default_step = "checkout",
 *   wrapper_element = "fieldset",
 * )
 */
final class LegalConsentPane extends CheckoutPaneBase {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?CheckoutFlowInterface $checkout_flow = NULL) {
    return parent::create($container, $configuration, $plugin_id, $plugin_definition, $checkout_flow);
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form): array {
    $order = $this->order;

    // Check if consent already recorded.
    $consent_given = FALSE;
    $consent_timestamp = NULL;
    if ($order->hasField('field_legal_consent_given') && !$order->get('field_legal_consent_given')->isEmpty()) {
      $consent_given = (bool) $order->get('field_legal_consent_given')->value;
    }
    if ($order->hasField('field_legal_consent_timestamp') && !$order->get('field_legal_consent_timestamp')->isEmpty()) {
      $consent_timestamp = (int) $order->get('field_legal_consent_timestamp')->value;
    }

    $pane_form['consent_text'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-consent-text']],
      'markup' => [
        '#markup' => '<p>' . $this->t('By proceeding, you agree to our Terms of Service, Privacy Policy, and Refund Policy.') . '</p>',
      ],
    ];

    $pane_form['consent_checkbox'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I agree to the Terms of Service, Privacy Policy, and Refund Policy'),
      '#required' => TRUE,
      '#default_value' => $consent_given,
      '#attributes' => [
        'class' => ['mel-consent-checkbox'],
        'aria-required' => 'true',
      ],
    ];

    // Store timestamp in hidden field for submission.
    $pane_form['consent_timestamp'] = [
      '#type' => 'hidden',
      '#value' => $consent_timestamp ?? time(),
    ];

    return $pane_form;
  }

  /**
   * {@inheritdoc}
   */
  public function validatePaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
    $values = $form_state->getValue($pane_form['#parents']);
    $consent_given = (bool) ($values['consent_checkbox'] ?? FALSE);

    if (!$consent_given) {
      $form_state->setError($pane_form['consent_checkbox'], $this->t('You must agree to the Terms of Service, Privacy Policy, and Refund Policy to continue.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
    $values = $form_state->getValue($pane_form['#parents']);
    $order = $this->order;

    $consent_given = (bool) ($values['consent_checkbox'] ?? FALSE);
    $consent_timestamp = (int) ($values['consent_timestamp'] ?? time());

    // Store consent data if fields exist.
    // Note: These fields should be added to commerce_order entity type via field UI or install hook.
    if ($order->hasField('field_legal_consent_given')) {
      $order->set('field_legal_consent_given', $consent_given);
    }

    if ($order->hasField('field_legal_consent_timestamp')) {
      $order->set('field_legal_consent_timestamp', $consent_timestamp);
    }

    // Fallback: store in order data if fields don't exist.
    if (!$order->hasField('field_legal_consent_given')) {
      $order->setData('legal_consent_given', $consent_given);
      $order->setData('legal_consent_timestamp', $consent_timestamp);
    }

    $order->save();
  }

}
