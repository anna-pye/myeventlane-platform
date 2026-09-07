<?php

declare(strict_types=1);

namespace Drupal\myeventlane_checkout_flow\Service;

use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\PaymentOption;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Makes repeated saved-card choices easier to scan without removing them.
 */
final class SavedPaymentMethodPresenter {

  /**
   * Constructs the saved payment method presenter.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TranslationInterface $stringTranslation,
  ) {}

  /**
   * Applies customer-safe labels and duplicate disclosure metadata.
   */
  public function apply(array &$paneForm): void {
    if (
      !isset($paneForm['payment_method']['#options']) ||
      !is_array($paneForm['payment_method']['#options']) ||
      !isset($paneForm['#payment_options']) ||
      !is_array($paneForm['#payment_options'])
    ) {
      return;
    }

    $storedOptions = array_filter(
      $paneForm['#payment_options'],
      static fn (mixed $option): bool => $option instanceof PaymentOption && $option->getPaymentMethodId() !== NULL,
    );
    if ($storedOptions === []) {
      return;
    }

    $methodIds = array_map(
      static fn (PaymentOption $option): string => (string) $option->getPaymentMethodId(),
      $storedOptions,
    );
    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface[] $paymentMethods */
    $paymentMethods = $this->entityTypeManager
      ->getStorage('commerce_payment_method')
      ->loadMultiple($methodIds);

    $selectedId = (string) ($paneForm['payment_method']['#default_value'] ?? '');
    $groups = [];
    foreach ($storedOptions as $optionId => $option) {
      $methodId = (string) $option->getPaymentMethodId();
      $paymentMethod = $paymentMethods[$methodId] ?? NULL;
      if (!$paymentMethod instanceof PaymentMethodInterface) {
        continue;
      }

      $expiry = $this->getCardExpiry($paymentMethod);
      if ($expiry !== NULL) {
        $paneForm['payment_method']['#options'][$optionId] = $this->stringTranslation->translate(
          '@card — expires @expiry',
          [
            '@card' => $option->getLabel(),
            '@expiry' => $expiry,
          ],
        );
      }

      $signature = $this->getVisibleCardSignature($paymentMethod);
      if ($signature === NULL) {
        continue;
      }
      $groups[$signature][] = (string) $optionId;
    }

    $duplicateIds = [];
    foreach ($groups as $optionIds) {
      if (count($optionIds) < 2) {
        continue;
      }
      $winner = in_array($selectedId, $optionIds, TRUE)
        ? $selectedId
        : reset($optionIds);
      foreach ($optionIds as $optionId) {
        if ($optionId !== $winner) {
          $duplicateIds[] = $optionId;
        }
      }
    }

    if ($duplicateIds === []) {
      return;
    }

    $paneForm['payment_method']['#attributes']['data-mel-saved-card-list'] = 'true';
    foreach ($duplicateIds as $optionId) {
      if (!isset($paneForm['payment_method'][$optionId]['#attributes'])) {
        $paneForm['payment_method'][$optionId]['#attributes'] = [];
      }
      $paneForm['payment_method'][$optionId]['#attributes']['class'][] = 'mel-saved-card-choice--older';
      $paneForm['payment_method'][$optionId]['#attributes']['data-mel-saved-card-older'] = 'true';
    }

    $count = count($duplicateIds);
    $showLabel = $this->stringTranslation->formatPlural(
      $count,
      'Show 1 older saved card',
      'Show @count older saved cards',
    );
    $hideLabel = $this->stringTranslation->translate('Hide older saved cards');
    $paneForm['mel_saved_cards_disclosure'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $showLabel,
      '#weight' => -9,
      '#attributes' => [
        'type' => 'button',
        'class' => ['mel-saved-card-toggle'],
        'data-mel-saved-card-toggle' => 'true',
        'data-show-label' => (string) $showLabel,
        'data-hide-label' => (string) $hideLabel,
        'aria-expanded' => 'false',
      ],
    ];
  }

  /**
   * Returns the visible identity used to group repeated card entries.
   */
  private function getVisibleCardSignature(PaymentMethodInterface $paymentMethod): ?string {
    $fieldNames = $this->getCardFieldNames($paymentMethod);
    if ($fieldNames === NULL) {
      return NULL;
    }

    $cardType = $paymentMethod->get($fieldNames['type'])->getString();
    $lastFour = $paymentMethod->get($fieldNames['number'])->getString();
    $expiryMonth = $paymentMethod->get($fieldNames['month'])->getString();
    $expiryYear = $paymentMethod->get($fieldNames['year'])->getString();
    if ($cardType === '' || $lastFour === '' || $expiryMonth === '' || $expiryYear === '') {
      return NULL;
    }

    $walletType = '';
    if ($paymentMethod->hasField('stripe_card_wallet_type')) {
      $walletType = $paymentMethod->get('stripe_card_wallet_type')->getString();
    }

    return implode('|', [
      $paymentMethod->getPaymentGatewayId(),
      $paymentMethod->bundle(),
      $cardType,
      $lastFour,
      $expiryMonth,
      $expiryYear,
      $walletType,
    ]);
  }

  /**
   * Returns an MM/YY expiry label when the stored card provides one.
   */
  private function getCardExpiry(PaymentMethodInterface $paymentMethod): ?string {
    $fieldNames = $this->getCardFieldNames($paymentMethod);
    if ($fieldNames === NULL) {
      return NULL;
    }

    $month = (int) $paymentMethod->get($fieldNames['month'])->getString();
    $year = (int) $paymentMethod->get($fieldNames['year'])->getString();
    if ($month < 1 || $month > 12 || $year < 1) {
      return NULL;
    }

    return sprintf('%02d/%02d', $month, $year % 100);
  }

  /**
   * Resolves legacy Stripe and Payment Element card field names.
   *
   * @return array{type: string, number: string, month: string, year: string}|null
   *   The card field names, or NULL for a non-card payment method.
   */
  private function getCardFieldNames(PaymentMethodInterface $paymentMethod): ?array {
    $sets = [
      [
        'type' => 'stripe_card_type',
        'number' => 'stripe_card_number',
        'month' => 'stripe_card_exp_month',
        'year' => 'stripe_card_exp_year',
      ],
      [
        'type' => 'card_type',
        'number' => 'card_number',
        'month' => 'card_exp_month',
        'year' => 'card_exp_year',
      ],
    ];

    foreach ($sets as $fieldNames) {
      if (array_reduce(
        $fieldNames,
        static fn (bool $hasFields, string $fieldName): bool => $hasFields && $paymentMethod->hasField($fieldName),
        TRUE,
      )) {
        return $fieldNames;
      }
    }

    return NULL;
  }

}
