<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkout_flow\Unit;

use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\PaymentOption;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\myeventlane_checkout_flow\Service\SavedPaymentMethodPresenter;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_checkout_flow\Service\SavedPaymentMethodPresenter
 *
 * @group myeventlane_checkout_flow
 */
final class SavedPaymentMethodPresenterTest extends UnitTestCase {

  /**
   * @covers ::apply
   */
  public function testGroupsOnlyMatchingVisibleCardDetailsAndPreservesSelection(): void {
    $methods = [
      '40' => $this->card('40', '12', '2029'),
      '41' => $this->card('41', '02', '2028'),
      '42' => $this->card('42', '12', '2029'),
      '43' => $this->card('43', '12', '2029'),
    ];
    $presenter = $this->presenter($methods);
    $pane = $this->pane(['40', '41', '42', '43'], '42');

    $presenter->apply($pane);

    $this->assertSame('Visa ending in 4242 — expires 12/29', (string) $pane['payment_method']['#options']['40']);
    $this->assertSame('Visa ending in 4242 — expires 02/28', (string) $pane['payment_method']['#options']['41']);
    $this->assertArrayHasKey('mel_saved_cards_disclosure', $pane);
    $this->assertSame('Show 2 older saved cards', (string) $pane['mel_saved_cards_disclosure']['#value']);
    $this->assertContains('mel-saved-card-choice--older', $pane['payment_method']['40']['#attributes']['class']);
    $this->assertContains('mel-saved-card-choice--older', $pane['payment_method']['43']['#attributes']['class']);
    $this->assertArrayNotHasKey('data-mel-saved-card-older', $pane['payment_method']['41']['#attributes']);
    $this->assertArrayNotHasKey('data-mel-saved-card-older', $pane['payment_method']['42']['#attributes']);
    $this->assertSame('Credit card', $pane['payment_method']['#options']['new--credit_card--stripe']);
  }

  /**
   * @covers ::apply
   */
  public function testDoesNotGroupCardsWithIncompleteIdentity(): void {
    $methods = [
      '50' => $this->card('50', '', ''),
      '51' => $this->card('51', '', ''),
    ];
    $presenter = $this->presenter($methods);
    $pane = $this->pane(['50', '51'], '50');

    $presenter->apply($pane);

    $this->assertArrayNotHasKey('mel_saved_cards_disclosure', $pane);
    $this->assertSame('Visa ending in 4242', $pane['payment_method']['#options']['50']);
    $this->assertSame('Visa ending in 4242', $pane['payment_method']['#options']['51']);
  }

  /**
   * Creates the presenter with a mocked payment method storage.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface[] $methods
   *   Payment methods keyed by ID.
   */
  private function presenter(array $methods): SavedPaymentMethodPresenter {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturnCallback(
      static fn (array $ids): array => array_intersect_key($methods, array_flip($ids)),
    );
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->with('commerce_payment_method')
      ->willReturn($storage);

    return new SavedPaymentMethodPresenter(
      $entityTypeManager,
      $this->getStringTranslationStub(),
    );
  }

  /**
   * Builds a legacy Stripe card payment method mock.
   */
  private function card(string $id, string $month, string $year): PaymentMethodInterface {
    $values = [
      'card_type' => 'visa',
      'card_number' => '4242',
      'card_exp_month' => $month,
      'card_exp_year' => $year,
    ];
    $fields = [];
    foreach ($values as $fieldName => $value) {
      $field = $this->createMock(FieldItemListInterface::class);
      $field->method('getString')->willReturn($value);
      $fields[$fieldName] = $field;
    }

    $method = $this->createMock(PaymentMethodInterface::class);
    $method->method('id')->willReturn($id);
    $method->method('label')->willReturn('Visa ending in 4242');
    $method->method('bundle')->willReturn('credit_card');
    $method->method('getPaymentGatewayId')->willReturn('stripe');
    $method->method('hasField')->willReturnCallback(
      static fn (string $fieldName): bool => isset($fields[$fieldName]),
    );
    $method->method('get')->willReturnCallback(
      static fn (string $fieldName): FieldItemListInterface => $fields[$fieldName],
    );
    return $method;
  }

  /**
   * Builds a payment information pane with stored and new card options.
   */
  private function pane(array $methodIds, string $selectedId): array {
    $options = [];
    $labels = [];
    $children = [];
    foreach ($methodIds as $methodId) {
      $options[$methodId] = new PaymentOption([
        'id' => $methodId,
        'label' => 'Visa ending in 4242',
        'payment_gateway_id' => 'stripe',
        'payment_method_id' => $methodId,
      ]);
      $labels[$methodId] = 'Visa ending in 4242';
      $children[$methodId] = ['#attributes' => ['class' => ['payment-method--stored']]];
    }

    $newOptionId = 'new--credit_card--stripe';
    $options[$newOptionId] = new PaymentOption([
      'id' => $newOptionId,
      'label' => 'Credit card',
      'payment_gateway_id' => 'stripe',
      'payment_method_type_id' => 'credit_card',
    ]);
    $labels[$newOptionId] = 'Credit card';
    $children[$newOptionId] = ['#attributes' => ['class' => ['payment-method--new']]];

    return [
      '#payment_options' => $options,
      'payment_method' => $children + [
        '#options' => $labels,
        '#default_value' => $selectedId,
      ],
    ];
  }

}
