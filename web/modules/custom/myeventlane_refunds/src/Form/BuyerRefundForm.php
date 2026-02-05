<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Form;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_refunds\Service\BuyerRefundEligibilityService;
use Drupal\myeventlane_refunds\Service\RefundOrderInspector;
use Drupal\myeventlane_refunds\Service\RefundProcessor;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for buyers to request a self-service refund.
 */
final class BuyerRefundForm extends ConfirmFormBase {

  /**
   * Constructs BuyerRefundForm.
   *
   * @param \Drupal\myeventlane_refunds\Service\BuyerRefundEligibilityService $eligibility
   *   The buyer refund eligibility service.
   * @param \Drupal\myeventlane_refunds\Service\RefundOrderInspector $orderInspector
   *   The order inspector.
   * @param \Drupal\myeventlane_refunds\Service\RefundProcessor $refundProcessor
   *   The refund processor.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly BuyerRefundEligibilityService $eligibility,
    private readonly RefundOrderInspector $orderInspector,
    private readonly RefundProcessor $refundProcessor,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_refunds.buyer_eligibility'),
      $container->get('myeventlane_refunds.order_inspector'),
      $container->get('myeventlane_refunds.processor'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'myeventlane_refunds_buyer_refund_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    $event = $this->getEvent();
    return (string) $this->t('Request refund for @event?', [
      '@event' => $event ? $event->label() : $this->t('this event'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    $order = $this->getOrder();
    $event = $this->getEvent();
    if (!$order || !$event) {
      return '';
    }

    $eventId = (int) $event->id();
    $amountCents = $this->orderInspector->calculateTicketSubtotalCents($order, $eventId);
    $amount = $amountCents > 0 ? '$' . number_format($amountCents / 100, 2) : $this->t('your tickets');

    return (string) $this->t('This will refund @amount for your tickets to this event. The refund will be processed within 2–5 business days.', [
      '@amount' => $amount,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    $order = $this->getOrder();
    if ($order) {
      return Url::fromRoute('myeventlane_checkout_flow.order_detail', [
        'commerce_order' => $order->id(),
      ]);
    }
    return Url::fromRoute('myeventlane_checkout_flow.my_tickets');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?OrderInterface $commerce_order = NULL): array {
    $event = $this->getEvent();
    if (!$event) {
      $form['error'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Event parameter required. Add ?event=@id to the URL.', ['@id' => 'EVENT_ID']) . '</p>',
      ];
      return $form;
    }

    $form = parent::buildForm($form, $form_state);

    $backUrl = $this->getCancelUrl();
    $question = Html::escape($this->getQuestion());
    $form['#prefix'] = '<div class="mel-order-detail mel-refund-confirm"><div class="mel-page-header"><a href="' . Html::escape($backUrl->toString()) . '" class="mel-link mel-back-link">← ' . Html::escape((string) $this->t('Back to order')) . '</a><h1 class="mel-page-title">' . $question . '</h1></div><div class="mel-card"><div class="mel-card-body">';
    $form['#suffix'] = '</div></div></div>';

    $form['#attributes']['class'][] = 'mel-refund-confirm-form';
    $form['description']['#wrapper_attributes'] = ['class' => ['mel-refund-description']];
    $form['description']['#prefix'] = '<p class="mel-text mel-mb-4">';
    $form['description']['#suffix'] = '</p>';

    $form['actions']['#prefix'] = '<div class="mel-form-actions mel-mt-4 mel-pt-4 mel-border-top">';
    $form['actions']['#suffix'] = '</div>';
    $form['actions']['submit']['#attributes']['class'] = ['mel-btn', 'mel-btn-primary'];
    $form['actions']['cancel']['#attributes']['class'] = ['mel-btn', 'mel-btn-secondary'];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $order = $this->getOrder();
    $event = $this->getEvent();

    if (!$order || !$event) {
      $this->messenger()->addError($this->t('Unable to process refund request.'));
      return;
    }

    try {
      $this->refundProcessor->requestBuyerRefund($order, $event, $this->currentUser());
      $this->messenger()->addStatus($this->t('Your refund has been requested. You will receive an email confirmation once it is processed (typically within 2–5 business days).'));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Refund request failed: @message', [
        '@message' => $e->getMessage(),
      ]));
      return;
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * Gets the order from the route.
   */
  private function getOrder(): ?OrderInterface {
    $order = $this->getRouteMatch()->getParameter('commerce_order');
    if ($order instanceof OrderInterface) {
      return $order;
    }
    if (is_numeric($order)) {
      $loaded = $this->entityTypeManager->getStorage('commerce_order')->load($order);
      return $loaded instanceof OrderInterface ? $loaded : NULL;
    }
    return NULL;
  }

  /**
   * Gets the event from the query parameter.
   */
  private function getEvent(): ?NodeInterface {
    $eventId = (int) ($_GET['event'] ?? 0);
    if (!$eventId) {
      return NULL;
    }

    $event = $this->entityTypeManager->getStorage('node')->load($eventId);
    return $event instanceof NodeInterface && $event->bundle() === 'event' ? $event : NULL;
  }

}
