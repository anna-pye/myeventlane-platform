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
use Drupal\myeventlane_core\MelReadinessHelper;
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
    private readonly OrganiserCheckoutContext $organiserCheckoutContext,
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
    $this->addPaymentConfidence($form, $order);
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
    if (!isset($form['sidebar']['order_summary']) || !is_array($form['sidebar']['order_summary'])) {
      return;
    }

    $render = $this->checkoutSummaryPresenter->buildGroupedSummaryRenderArray($order, $presenter_options);
    $form['sidebar']['order_summary']['#attributes']['class'][] = 'mel-checkout-summary-pane';

    if (isset($form['sidebar']['order_summary']['summary']) && is_array($form['sidebar']['order_summary']['summary'])) {
      $form['sidebar']['order_summary']['summary'] = $render;
      return;
    }

    // Complete step panes may expose the View embed without a summary child.
    $form['sidebar']['order_summary']['summary'] = $render;
  }

  private function addPaymentConfidence(array &$form, OrderInterface $order): void {
    $context = $this->organiserCheckoutContext->build($order);
    $lines = is_array($context['confidence'] ?? NULL)
      ? $context['confidence']
      : $this->readinessHelper->customerCheckoutSidebarConfidenceLines();
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
