<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\profile\Entity\ProfileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides buyer details pane (email-first, guest-friendly).
 *
 * @CommerceCheckoutPane(
 *   id = "mel_buyer_details",
 *   label = @Translation("Buyer details"),
 *   default_step = "checkout",
 *   wrapper_element = "fieldset",
 * )
 */
final class BuyerDetailsPane extends CheckoutPaneBase {

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
    $customer = $order->getCustomer();

    // Load or create billing profile.
    $billing_profile = $order->getBillingProfile();
    if (!$billing_profile) {
      $billing_profile = $this->entityTypeManager->getStorage('profile')->create([
        'type' => 'customer',
        'uid' => $customer->id(),
      ]);
    }

    $pane_form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email address'),
      '#description' => $this->t('We\'ll send your order confirmation and tickets to this address.'),
      '#required' => TRUE,
      '#default_value' => $customer->getEmail() ?: $billing_profile->get('address')->first()?->get('email')?->value ?? '',
      '#attributes' => [
        'autocomplete' => 'email',
        'class' => ['mel-buyer-email'],
      ],
    ];

    $pane_form['first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First name'),
      '#required' => TRUE,
      '#default_value' => $billing_profile->get('address')->first()?->get('given_name')?->value ?? '',
      '#attributes' => [
        'autocomplete' => 'given-name',
        'class' => ['mel-buyer-first-name'],
      ],
    ];

    $pane_form['last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last name'),
      '#required' => TRUE,
      '#default_value' => $billing_profile->get('address')->first()?->get('family_name')?->value ?? '',
      '#attributes' => [
        'autocomplete' => 'family-name',
        'class' => ['mel-buyer-last-name'],
      ],
    ];

    $pane_form['mobile'] = [
      '#type' => 'tel',
      '#title' => $this->t('Mobile number'),
      '#description' => $this->t('Optional. We may use this to contact you about your order.'),
      '#default_value' => $billing_profile->get('field_phone')?->value ?? '',
      '#attributes' => [
        'autocomplete' => 'tel',
        'class' => ['mel-buyer-mobile'],
      ],
    ];

    return $pane_form;
  }

  /**
   * {@inheritdoc}
   */
  public function validatePaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
    $values = $form_state->getValue($pane_form['#parents']);

    if (empty($values['email'])) {
      $form_state->setError($pane_form['email'], $this->t('Email address is required.'));
    }
    elseif (!\Drupal::service('email.validator')->isValid($values['email'])) {
      $form_state->setError($pane_form['email'], $this->t('Please enter a valid email address.'));
    }

    if (empty($values['first_name'])) {
      $form_state->setError($pane_form['first_name'], $this->t('First name is required.'));
    }

    if (empty($values['last_name'])) {
      $form_state->setError($pane_form['last_name'], $this->t('Last name is required.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
    $values = $form_state->getValue($pane_form['#parents']);
    $order = $this->order;

    // Update customer email if anonymous or different.
    $customer = $order->getCustomer();
    if ($customer->isAnonymous() || $customer->getEmail() !== $values['email']) {
      $order->setEmail($values['email']);
    }

    // Update or create billing profile.
    $billing_profile = $order->getBillingProfile();
    if (!$billing_profile) {
      $billing_profile = $this->entityTypeManager->getStorage('profile')->create([
        'type' => 'customer',
        'uid' => $customer->id(),
      ]);
    }

    $address = $billing_profile->get('address')->first();
    if (!$address) {
      $address = $billing_profile->get('address')->create();
      $billing_profile->set('address', $address);
    }

    $address->set('given_name', $values['first_name']);
    $address->set('family_name', $values['last_name']);

    if (!empty($values['mobile'])) {
      if ($billing_profile->hasField('field_phone')) {
        $billing_profile->set('field_phone', $values['mobile']);
      }
    }

    $billing_profile->save();
    $order->setBillingProfile($billing_profile);
    $order->save();
  }

}

