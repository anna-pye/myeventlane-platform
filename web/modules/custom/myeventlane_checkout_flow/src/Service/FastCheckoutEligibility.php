<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_stripe\Plugin\Commerce\PaymentGateway\StripePaymentElementInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\myeventlane_checkout_paragraph\CheckoutAttendeeSchemaInspectorInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\profile\Entity\ProfileInterface;

/**
 * Determines whether a MEL checkout order can use Stripe express checkout.
 */
final class FastCheckoutEligibility {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CheckoutAttendeeSchemaInspectorInterface $checkoutAttendeeSchema,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Returns TRUE only when the order can use the fast checkout layer.
   */
  public function isFastCheckoutEligible(OrderInterface $order, ?PaymentGatewayInterface $gateway = NULL): bool {
    if (!$this->hasTicketSelection($order)) {
      return FALSE;
    }

    $total = $order->getTotalPrice();
    if ($total === NULL || !$total->isPositive()) {
      return FALSE;
    }

    if ($gateway !== NULL) {
      if (!$this->isUsableStripeGateway($order, $gateway)) {
        return FALSE;
      }
    }
    elseif (!$this->getStripeGateway($order) instanceof PaymentGatewayInterface) {
      return FALSE;
    }

    return $this->attendeeQuestionsAreFastCheckoutCompatible($order);
  }

  /**
   * Returns TRUE when the order contains MEL ticket line items.
   */
  public function hasTicketSelection(OrderInterface $order): bool {
    foreach ($order->getItems() as $order_item) {
      if (!$this->checkoutAttendeeSchema->shouldCollectTicketHolders($order_item)) {
        continue;
      }
      if ((int) $order_item->getQuantity() > 0) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns TRUE when basic name/email data must be confirmed before payment.
   */
  public function requiresMinimalData(OrderInterface $order): bool {
    foreach ($this->getQuestionTemplates($order) as $question) {
      if ($this->classifyBasicQuestion($question) !== NULL) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns TRUE when the lightweight details step has already populated data.
   */
  public function hasConfirmedMinimalData(OrderInterface $order): bool {
    if (!$this->requiresMinimalData($order)) {
      return TRUE;
    }

    return (bool) $order->getData('mel_fast_checkout_minimal_data_confirmed', FALSE)
      && trim((string) $order->getEmail()) !== '';
  }

  /**
   * Returns TRUE when attendee questions prevent fast checkout.
   */
  public function hasFastCheckoutBlockingAttendeeQuestions(OrderInterface $order): bool {
    foreach ($this->getQuestionTemplates($order) as $question) {
      if ($this->classifyBasicQuestion($question) === NULL) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns TRUE when a ticket line item has more than one attendee.
   */
  public function hasMultipleTicketQuantity(OrderInterface $order): bool {
    foreach ($order->getItems() as $order_item) {
      if (!$this->checkoutAttendeeSchema->shouldCollectTicketHolders($order_item)) {
        continue;
      }
      if ((int) $order_item->getQuantity() > 1) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns TRUE when the order may proceed through express confirmation.
   */
  public function canConfirmFastCheckout(OrderInterface $order, PaymentGatewayInterface $gateway): bool {
    return $this->isFastCheckoutEligible($order, $gateway)
      && $this->hasConfirmedMinimalData($order);
  }

  /**
   * Finds the Stripe Payment Element gateway available for this order.
   */
  public function getStripeGateway(OrderInterface $order): ?PaymentGatewayInterface {
    $storage = $this->entityTypeManager->getStorage('commerce_payment_gateway');
    if (!method_exists($storage, 'loadMultipleForOrder')) {
      $this->logger->error('Fast checkout could not load payment gateways for order @order_id because the gateway storage does not support loadMultipleForOrder().', [
        '@order_id' => (string) $order->id(),
      ]);
      return NULL;
    }

    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface[] $gateways */
    $gateways = $storage->loadMultipleForOrder($order);
    foreach ($gateways as $gateway) {
      if ($this->isUsableStripeGateway($order, $gateway)) {
        return $gateway;
      }
    }

    return NULL;
  }

  /**
   * Saves lightweight name/email data for fast checkout.
   */
  public function saveMinimalData(OrderInterface $order, string $name, string $email): void {
    $name = trim($name);
    $email = trim($email);
    [$first_name, $last_name] = $this->splitName($name);

    $order->setEmail($email);
    $this->saveBillingProfile($order, $first_name, $last_name);
    $this->saveTicketHolderParagraphs($order, $name, $first_name, $last_name, $email);
    $order->setData('mel_fast_checkout_minimal_data_confirmed', TRUE);
    $order->save();
  }

  /**
   * Checks whether this gateway can create Stripe Payment Element intents.
   */
  private function isUsableStripeGateway(OrderInterface $order, PaymentGatewayInterface $gateway): bool {
    if (!$gateway->status()) {
      return FALSE;
    }
    if (!$gateway->applies($order)) {
      return FALSE;
    }
    return $gateway->getPlugin() instanceof StripePaymentElementInterface;
  }

  /**
   * Ensures the order has no custom attendee questions beyond name/email.
   */
  private function attendeeQuestionsAreFastCheckoutCompatible(OrderInterface $order): bool {
    return !$this->hasFastCheckoutBlockingAttendeeQuestions($order);
  }

  /**
   * Gets all merged question templates attached to the order's ticket items.
   *
   * @return \Drupal\paragraphs\ParagraphInterface[]
   *   Attendee question template paragraphs.
   */
  private function getQuestionTemplates(OrderInterface $order): array {
    return $this->checkoutAttendeeSchema->collectUniqueQuestionTemplatesForOrder($order);
  }

  /**
   * Classifies name/email-only attendee question templates.
   */
  private function classifyBasicQuestion(ParagraphInterface $question): ?string {
    $machine_name = $question->hasField('field_question_machine_name')
      ? mb_strtolower(trim((string) ($question->get('field_question_machine_name')->value ?? '')))
      : '';
    $label = $question->hasField('field_question_label')
      ? mb_strtolower(trim((string) ($question->get('field_question_label')->value ?? '')))
      : '';
    $type = $question->hasField('field_question_type')
      ? mb_strtolower(trim((string) ($question->get('field_question_type')->value ?? 'textfield')))
      : 'textfield';

    $key = $machine_name !== '' ? $machine_name : $label;
    $key = str_replace(['-', ' '], '_', $key);

    $textTypes = ['textfield', 'text'];

    if (in_array($key, ['name', 'full_name', 'attendee_name'], TRUE)
      && in_array($type, $textTypes, TRUE)) {
      return 'name';
    }

    if (in_array($key, ['first_name', 'firstname', 'given_name'], TRUE)
      && in_array($type, $textTypes, TRUE)) {
      return 'first_name';
    }

    if (in_array($key, ['last_name', 'lastname', 'family_name', 'surname'], TRUE)
      && in_array($type, $textTypes, TRUE)) {
      return 'last_name';
    }

    if (in_array($key, ['email', 'email_address', 'attendee_email'], TRUE)
      && in_array($type, ['email', 'textfield', 'text'], TRUE)) {
      return 'email';
    }

    return NULL;
  }

  /**
   * Saves buyer details to the order billing profile.
   */
  private function saveBillingProfile(OrderInterface $order, string $first_name, string $last_name): void {
    $billing_profile = $order->getBillingProfile();
    if (!$billing_profile instanceof ProfileInterface) {
      $billing_profile = $this->entityTypeManager->getStorage('profile')->create([
        'type' => 'customer',
        'uid' => $order->getCustomerId(),
      ]);
    }

    $address = $billing_profile->get('address')->first();
    if ($address === NULL) {
      $address = $billing_profile->get('address')->appendItem();
    }
    $address->set('given_name', $first_name);
    $address->set('family_name', $last_name);

    $billing_profile->save();
    $order->setBillingProfile($billing_profile);
  }

  /**
   * Saves lightweight attendee holder paragraphs for each ticket quantity.
   */
  private function saveTicketHolderParagraphs(OrderInterface $order, string $name, string $first_name, string $last_name, string $email): void {
    foreach ($order->getItems() as $order_item) {
      if (!$this->checkoutAttendeeSchema->shouldCollectTicketHolders($order_item)) {
        continue;
      }

      $templates = $this->checkoutAttendeeSchema->getMergedQuestionTemplatesForOrderItem($order_item);
      $holders = [];
      $quantity = (int) $order_item->getQuantity();
      for ($delta = 0; $delta < $quantity; $delta++) {
        $holders[] = $this->createMinimalHolder($templates, $name, $first_name, $last_name, $email);
      }
      $order_item->set('field_ticket_holder', $holders);
      $order_item->save();
    }
  }

  /**
   * Creates an attendee holder paragraph from lightweight details.
   *
   * @param \Drupal\paragraphs\ParagraphInterface[] $templates
   *   Basic name/email question templates.
   */
  private function createMinimalHolder(array $templates, string $name, string $first_name, string $last_name, string $email): ParagraphInterface {
    $holder = Paragraph::create(['type' => 'attendee_answer']);
    $holder->set('field_first_name', $first_name);
    $holder->set('field_last_name', $last_name);
    $holder->set('field_email', $email);
    if ($holder->hasField('field_phone')) {
      $holder->set('field_phone', '');
    }

    $question_answers = [];
    foreach ($templates as $template) {
      if (!$template instanceof ParagraphInterface) {
        continue;
      }
      $answer_type = $this->classifyBasicQuestion($template);
      if ($answer_type === NULL) {
        continue;
      }
      $clone = $template->createDuplicate();
      if ($clone->hasField('field_attendee_extra_field')) {
        $clone->set('field_attendee_extra_field', match ($answer_type) {
          'name' => $name,
          'first_name' => $first_name,
          'last_name' => $last_name,
          'email' => $email,
          default => '',
        });
      }
      $clone->save();
      $question_answers[] = $clone;
    }

    if ($holder->hasField('field_attendee_questions') && $question_answers !== []) {
      $holder->set('field_attendee_questions', $question_answers);
    }

    $holder->save();
    return $holder;
  }

  /**
   * Splits a display name into first and last name storage values.
   *
   * @return array{0: string, 1: string}
   *   First name and last name.
   */
  private function splitName(string $name): array {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first_name = array_shift($parts) ?: $name;
    $last_name = trim(implode(' ', $parts));

    return [$first_name, $last_name !== '' ? $last_name : $first_name];
  }

}
