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
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\myeventlane_surface\MelReadinessHelper;
use Psr\Log\LoggerInterface;

/**
 * Attaches MEL checkout UX enhancements: grouped summary, confidence, reassurance.
 */
final class CheckoutUxAttacher {

  public function __construct(
    private readonly MelCheckoutSummaryPresenter $checkoutSummaryPresenter,
    private readonly RouteMatchInterface $routeMatch,
    private readonly LoggerInterface $logger,
    private readonly \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager,
    private readonly MelReadinessHelper $readinessHelper,
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

    $this->replaceSidebarWithGroupedSummary($form, $order, ['surface' => 'checkout']);
    $this->addPaymentConfidence($form);
  }

  /**
   * Replaces Commerce sidebar summary on the completion step with the same presenter build.
   */
  public function attachCompleteStepSidebar(array &$form): void {
    $order = $this->resolveOrder();
    if (!$order instanceof OrderInterface) {
      $this->logger->warning('MEL checkout UX: commerce_order route parameter missing or invalid on checkout complete.');
      return;
    }
    $this->replaceSidebarWithGroupedSummary($form, $order, ['surface' => 'complete']);
  }

  private function resolveOrder(): ?OrderInterface {
    $order = $this->routeMatch->getParameter('commerce_order');
    if (is_numeric($order)) {
      $loaded = $this->entityTypeManager->getStorage('commerce_order')->load((int) $order);
      return $loaded instanceof OrderInterface ? $loaded : NULL;
    }
    return $order instanceof OrderInterface ? $order : NULL;
  }

  /**
   * @param array<string, mixed> $presenter_options
   *   Passed to MelCheckoutSummaryPresenter::buildGroupedSummaryRenderArray().
   */
  private function replaceSidebarWithGroupedSummary(array &$form, OrderInterface $order, array $presenter_options = []): void {
    if (!isset($form['sidebar']['order_summary']['summary']) || !is_array($form['sidebar']['order_summary']['summary'])) {
      return;
    }

    $summary = &$form['sidebar']['order_summary']['summary'];
    $is_view_embed = ($summary['#type'] ?? '') === 'view';
    $is_core_theme = ($summary['#theme'] ?? '') === 'commerce_checkout_order_summary';

    if (!$is_view_embed && !$is_core_theme) {
      return;
    }

    $form['sidebar']['order_summary']['#attributes']['class'][] = 'mel-checkout-summary-pane';
    $form['sidebar']['order_summary']['summary'] = $this->checkoutSummaryPresenter->buildGroupedSummaryRenderArray($order, $presenter_options);
  }

  private function addPaymentConfidence(array &$form): void {
    $lines = $this->readinessHelper->customerCheckoutSidebarConfidenceLines();
    // Rendered in sidebar via template for better conversion (trust near summary).
    $form['mel_checkout_confidence'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mel-checkout-confidence']],
      '#weight' => 3.7,
      'secure' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $lines['secure'],
      ],
      'instant' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $lines['instant'],
      ],
      'calendar' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $lines['calendar_hint'],
      ],
    ];
  }

}
