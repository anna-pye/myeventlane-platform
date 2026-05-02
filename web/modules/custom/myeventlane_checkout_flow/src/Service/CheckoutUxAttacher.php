<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

/**
 * MEL LANGUAGE STANDARD:
 * - Australian English
 * - "Attendee" not "Customer"
 * - "Organiser" not "Vendor"
 * - Friendly, non-corporate tone
 */

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Psr\Log\LoggerInterface;

/**
 * Attaches MEL checkout UX enhancements: grouped summary, confidence, reassurance.
 */
final class CheckoutUxAttacher {

  use StringTranslationTrait;

  public function __construct(
    private readonly CheckoutGroupedSummaryBuilder $groupedSummaryBuilder,
    private readonly RouteMatchInterface $routeMatch,
    private readonly LoggerInterface $logger,
    private readonly \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager,
  ) {}

/**
 * Attaches grouped summary and payment confidence sidebar elements to the form.
 */
  public function attach(array &$form): void {
    $order = $this->resolveOrder();
    if (!$order instanceof OrderInterface) {
      $this->logger->warning('MEL checkout UX: commerce_order route parameter missing or invalid while altering checkout form.');
      return;
    }

    $this->replaceSidebarWithGroupedSummary($form, $order);
    $this->addPaymentConfidence($form);
  }

  private function resolveOrder(): ?OrderInterface {
    $order = $this->routeMatch->getParameter('commerce_order');
    if (is_numeric($order)) {
      $loaded = $this->entityTypeManager->getStorage('commerce_order')->load((int) $order);
      return $loaded instanceof OrderInterface ? $loaded : NULL;
    }
    return $order instanceof OrderInterface ? $order : NULL;
  }

  private function replaceSidebarWithGroupedSummary(array &$form, OrderInterface $order): void {
    if (!isset($form['sidebar']['order_summary']['summary']) || !is_array($form['sidebar']['order_summary']['summary'])) {
      return;
    }

    $summary = &$form['sidebar']['order_summary']['summary'];
    $is_view_embed = ($summary['#type'] ?? '') === 'view';
    $is_core_theme = ($summary['#theme'] ?? '') === 'commerce_checkout_order_summary';

    if (!$is_view_embed && !$is_core_theme) {
      return;
    }

    $built = $this->groupedSummaryBuilder->build($order);
    // Avoid replacing the summary with an empty theme: the pane wrapper would
    // still render as a blank glass card above other checkout content.
    if ($built['grouped_items'] === []) {
      return;
    }

    $form['sidebar']['order_summary']['#attributes']['class'][] = 'mel-checkout-summary-pane';
    $form['sidebar']['order_summary']['summary'] = [
      '#theme' => 'mel_checkout_order_summary_grouped',
      '#grouped_items' => $built['grouped_items'],
      '#order_total' => $built['order_total'],
      '#subtotal_formatted' => $built['subtotal_formatted'],
      '#tax_rows' => $built['tax_rows'],
      '#fee_rows' => $built['fee_rows'],
      '#platform_fee_absorbed' => $built['platform_fee_absorbed'],
      '#show_includes_gst_note' => $built['show_includes_gst_note'],
      '#cache' => [
        'tags' => Cache::mergeTags($order->getCacheTags(), (array) ($built['cache_tags'] ?? [])),
        'contexts' => $order->getCacheContexts(),
      ],
    ];
  }

  private function addPaymentConfidence(array &$form): void {
    // Rendered in sidebar via template for better conversion (trust near summary).
    $form['mel_checkout_confidence'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-checkout-confidence']],
      '#weight' => 3.7,
      'secure' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $this->t('🔒 Secure payment via Stripe'),
      ],
      'instant' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $this->t('🧾 You\'ll receive tickets instantly'),
      ],
      'calendar' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $this->t('📅 Add to your calendar after booking'),
      ],
    ];
  }

}
