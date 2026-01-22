<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_price\Price;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides donation pane with preset and custom amounts.
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
   * Preset donation amounts in AUD.
   *
   * @var array
   */
  private const PRESET_AMOUNTS = [5, 10, 20, 50, 100];

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

    // Check if donation already exists in order.
    $existing_donation = $this->getExistingDonation($order);
    $existing_amount = $existing_donation ? (float) $existing_donation->getUnitPrice()->getNumber() : 0.0;

    $pane_form['donation_intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-donation-intro']],
      'text' => [
        '#markup' => '<p class="mel-donation-description">' . $this->t('Help support the event with a donation') . '</p>',
      ],
    ];

    $pane_form['donation_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Donation amount'),
      '#options' => [
        'none' => $this->t('No donation'),
        'preset' => $this->t('Preset amount'),
        'custom' => $this->t('Custom amount'),
      ],
      '#default_value' => $existing_amount > 0 ? ($this->isPresetAmount($existing_amount) ? 'preset' : 'custom') : 'none',
      '#required' => TRUE,
      '#attributes' => ['class' => ['mel-donation-type']],
    ];

    $pane_form['preset_amount'] = [
      '#type' => 'select',
      '#title' => $this->t('Select amount'),
      '#options' => array_combine(
        self::PRESET_AMOUNTS,
        array_map(fn($amt) => '$' . number_format($amt, 2), self::PRESET_AMOUNTS)
      ),
      '#default_value' => $existing_amount > 0 && $this->isPresetAmount($existing_amount) ? (string) $existing_amount : '',
      '#states' => [
        'visible' => [
          ':input[name="panes[mel_donation][donation_type]"]' => ['value' => 'preset'],
        ],
        'required' => [
          ':input[name="panes[mel_donation][donation_type]"]' => ['value' => 'preset'],
        ],
      ],
      '#attributes' => ['class' => ['mel-donation-preset']],
    ];

    $pane_form['custom_amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Custom amount (AUD)'),
      '#min' => 0.01,
      '#step' => 0.01,
      '#default_value' => $existing_amount > 0 && !$this->isPresetAmount($existing_amount) ? number_format($existing_amount, 2, '.', '') : '',
      '#field_prefix' => '$',
      '#states' => [
        'visible' => [
          ':input[name="panes[mel_donation][donation_type]"]' => ['value' => 'custom'],
        ],
        'required' => [
          ':input[name="panes[mel_donation][donation_type]"]' => ['value' => 'custom'],
        ],
      ],
      '#attributes' => ['class' => ['mel-donation-custom']],
    ];

    return $pane_form;
  }

  /**
   * {@inheritdoc}
   */
  public function validatePaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
    $values = $form_state->getValue($pane_form['#parents']);
    $donation_type = $values['donation_type'] ?? 'none';

    if ($donation_type === 'preset' && empty($values['preset_amount'])) {
      $form_state->setError($pane_form['preset_amount'], $this->t('Please select a donation amount.'));
    }

    if ($donation_type === 'custom') {
      $custom_amount = (float) ($values['custom_amount'] ?? 0);
      if ($custom_amount <= 0) {
        $form_state->setError($pane_form['custom_amount'], $this->t('Please enter a valid donation amount.'));
      }
      elseif ($custom_amount < 0.01) {
        $form_state->setError($pane_form['custom_amount'], $this->t('Donation amount must be at least $0.01.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
    $values = $form_state->getValue($pane_form['#parents']);
    $order = $this->order;
    $donation_type = $values['donation_type'] ?? 'none';

    // Remove existing donation if present.
    $existing_donation = $this->getExistingDonation($order);
    if ($existing_donation) {
      $order->removeItem($existing_donation);
      $existing_donation->delete();
    }

    // Add new donation if selected.
    if ($donation_type !== 'none') {
      $amount = 0.0;
      if ($donation_type === 'preset') {
        $amount = (float) ($values['preset_amount'] ?? 0);
      }
      elseif ($donation_type === 'custom') {
        $amount = (float) ($values['custom_amount'] ?? 0);
      }

      if ($amount > 0) {
        $this->addDonationToOrder($order, $amount);
      }
    }

    $order->save();
    $order->recalculateTotalPrice();
  }

  /**
   * Gets existing donation order item from order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Drupal\commerce_order\Entity\OrderItemInterface|null
   *   The donation order item, or NULL if not found.
   */
  private function getExistingDonation($order): ?OrderItemInterface {
    foreach ($order->getItems() as $item) {
      if ($item->bundle() === 'checkout_donation') {
        return $item;
      }
    }
    return NULL;
  }

  /**
   * Checks if amount is a preset value.
   *
   * @param float $amount
   *   The amount to check.
   *
   * @return bool
   *   TRUE if amount matches a preset, FALSE otherwise.
   */
  private function isPresetAmount(float $amount): bool {
    return in_array($amount, self::PRESET_AMOUNTS, TRUE);
  }

  /**
   * Adds donation order item to order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param float $amount
   *   The donation amount in AUD.
   */
  private function addDonationToOrder($order, float $amount): void {
    $order_item_storage = $this->entityTypeManager->getStorage('commerce_order_item');
    $order_item = $order_item_storage->create([
      'type' => 'checkout_donation',
      'title' => $this->t('Donation'),
      'unit_price' => new Price((string) $amount, 'AUD'),
      'quantity' => 1,
    ]);

    $order_item->save();
    $order->addItem($order_item);
  }

}
