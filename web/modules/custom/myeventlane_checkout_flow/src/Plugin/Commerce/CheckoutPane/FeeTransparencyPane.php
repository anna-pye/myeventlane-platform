<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\commerce_order\OrderRefreshInterface;
use Drupal\commerce_price\CurrencyFormatter;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides fee transparency pane showing breakdown of costs.
 *
 * @CommerceCheckoutPane(
 *   id = "mel_fee_transparency",
 *   label = @Translation("Order summary"),
 *   default_step = "_sidebar",
 *   wrapper_element = "fieldset",
 * )
 */
final class FeeTransparencyPane extends CheckoutPaneBase {

  /**
   * The currency formatter.
   *
   * @var \Drupal\commerce_price\CurrencyFormatter
   */
  private CurrencyFormatter $currencyFormatter;

  /**
   * The order refresh service.
   *
   * @var \Drupal\commerce_order\OrderRefreshInterface
   */
  private OrderRefreshInterface $orderRefresh;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  private ConfigFactoryInterface $configFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?CheckoutFlowInterface $checkout_flow = NULL) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition, $checkout_flow);
    $instance->currencyFormatter = $container->get('commerce_price.currency_formatter');
    $instance->orderRefresh = $container->get('commerce_order.order_refresh');
    $instance->configFactory = $container->get('config.factory');
    return $instance;
  }

  /**
   * Formats a price for display.
   *
   * @param \Drupal\commerce_price\Price|null $price
   *   The price to format.
   *
   * @return string
   *   The formatted price string.
   */
  private function formatPrice($price): string {
    if (!$price) {
      return '$0.00';
    }
    return $this->currencyFormatter->format($price->getNumber(), $price->getCurrencyCode());
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form): array {
    $order = $this->order;

    // Ensure order is refreshed so platform fee and other adjustments are
    // applied. Commerce only refreshes when refresh_frequency has elapsed;
    // we run it here so the fee always appears in the order summary.
    if ($order->getState()->getId() === 'draft') {
      $this->orderRefresh->refresh($order);
      $order->recalculateTotalPrice();
    }

    // Calculate breakdown.
    $subtotal = $this->calculateSubtotal($order);
    $donation = $this->calculateDonation($order);
    $fees = $this->calculateFees($order);
    $tax = $this->calculateTax($order);
    $total = $order->getTotalPrice();

    $pane_form['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-fee-summary']],
    ];

    // Subtotal (tickets only).
    $pane_form['summary']['subtotal'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-summary-row', 'mel-summary-subtotal']],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $this->t('Tickets'),
        '#attributes' => ['class' => ['mel-summary-label']],
      ],
      'amount' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $this->formatPrice($subtotal),
        '#attributes' => ['class' => ['mel-summary-amount']],
      ],
    ];

    // Donation (if present).
    if ($donation && $donation->getNumber() > 0) {
      $pane_form['summary']['donation'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-summary-row', 'mel-summary-donation']],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->t('Donation'),
          '#attributes' => ['class' => ['mel-summary-label']],
        ],
        'amount' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->formatPrice($donation),
          '#attributes' => ['class' => ['mel-summary-amount']],
        ],
      ];
    }

    // Fees (if present).
    if ($fees && $fees->getNumber() > 0) {
      $pane_form['summary']['fees'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-summary-row', 'mel-summary-fees']],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->t('Fees'),
          '#attributes' => ['class' => ['mel-summary-label']],
        ],
        'amount' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->formatPrice($fees),
          '#attributes' => ['class' => ['mel-summary-amount']],
        ],
      ];
    }
    elseif ($subtotal && $subtotal->getNumber() > 0 && $this->configFactory->get('myeventlane_core.settings')->get('fee_payer') === 'organizer_absorbs') {
      $pane_form['summary']['organizer_absorbs'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-summary-row', 'mel-summary-organizer-absorbs']],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->t('Platform fee'),
          '#attributes' => ['class' => ['mel-summary-label']],
        ],
        'amount' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->t('Organiser absorbs'),
          '#attributes' => ['class' => ['mel-summary-amount']],
        ],
      ];
    }

    // Tax (if present).
    if ($tax && $tax->getNumber() > 0) {
      $pane_form['summary']['tax'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mel-summary-row', 'mel-summary-tax']],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->t('Tax'),
          '#attributes' => ['class' => ['mel-summary-label']],
        ],
        'amount' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->formatPrice($tax),
          '#attributes' => ['class' => ['mel-summary-amount']],
        ],
      ];
    }

    // Total.
    $pane_form['summary']['total'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-summary-row', 'mel-summary-total']],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'strong',
        '#value' => $this->t('Total'),
        '#attributes' => ['class' => ['mel-summary-label']],
      ],
      'amount' => [
        '#type' => 'html_tag',
        '#tag' => 'strong',
        '#value' => $this->formatPrice($total),
        '#attributes' => ['class' => ['mel-summary-amount']],
      ],
    ];

    return $pane_form;
  }

  /**
   * Calculates subtotal (tickets only, excluding donations).
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Drupal\commerce_price\Price|null
   *   The subtotal price, or NULL if no ticket items.
   */
  private function calculateSubtotal($order) {
    $subtotal = NULL;
    foreach ($order->getItems() as $item) {
      if ($item->bundle() !== 'checkout_donation') {
        $item_total = $item->getTotalPrice();
        if ($item_total) {
          if (!$subtotal) {
            $subtotal = $item_total;
          }
          else {
            $subtotal = $subtotal->add($item_total);
          }
        }
      }
    }
    return $subtotal;
  }

  /**
   * Calculates donation total.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Drupal\commerce_price\Price|null
   *   The donation total, or NULL if no donations.
   */
  private function calculateDonation($order) {
    $donation = NULL;
    foreach ($order->getItems() as $item) {
      if ($item->bundle() === 'checkout_donation') {
        $item_total = $item->getTotalPrice();
        if ($item_total) {
          if (!$donation) {
            $donation = $item_total;
          }
          else {
            $donation = $donation->add($item_total);
          }
        }
      }
    }
    return $donation;
  }

  /**
   * Calculates fees from order adjustments.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Drupal\commerce_price\Price|null
   *   The fees total, or NULL if no fees.
   */
  private function calculateFees($order) {
    $fees = NULL;
    foreach ($order->getAdjustments() as $adjustment) {
      if ($adjustment->getType() === 'fee') {
        $amount = $adjustment->getAmount();
        if ($amount) {
          if (!$fees) {
            $fees = $amount;
          }
          else {
            $fees = $fees->add($amount);
          }
        }
      }
    }
    return $fees;
  }

  /**
   * Calculates tax from order adjustments.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Drupal\commerce_price\Price|null
   *   The tax total, or NULL if no tax.
   */
  private function calculateTax($order) {
    $tax = NULL;
    foreach ($order->getAdjustments() as $adjustment) {
      if ($adjustment->getType() === 'tax') {
        $amount = $adjustment->getAmount();
        if ($amount) {
          if (!$tax) {
            $tax = $amount;
          }
          else {
            $tax = $tax->add($amount);
          }
        }
      }
    }
    return $tax;
  }

}
