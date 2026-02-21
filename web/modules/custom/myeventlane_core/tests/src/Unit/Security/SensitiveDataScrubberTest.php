<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_core\Unit\Security;

use Drupal\myeventlane_core\Security\SensitiveDataScrubber;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the SensitiveDataScrubber utility.
 *
 * @group myeventlane_core
 * @coversDefaultClass \Drupal\myeventlane_core\Security\SensitiveDataScrubber
 */
final class SensitiveDataScrubberTest extends UnitTestCase {

  /**
   * @covers ::scrub
   */
  public function testScrubRemovesSensitiveKeys(): void {
    $input = [
      '@message' => 'Payment failed',
      'number' => '4242424242424242',
      'cvc' => '123',
      'exp_month' => '12',
      'exp_year' => '2027',
      'card_number' => '4111111111111111',
    ];

    $result = SensitiveDataScrubber::scrub($input);

    $this->assertSame('Payment failed', $result['@message']);
    $this->assertSame('[REDACTED]', $result['number']);
    $this->assertSame('[REDACTED]', $result['cvc']);
    $this->assertSame('[REDACTED]', $result['exp_month']);
    $this->assertSame('[REDACTED]', $result['exp_year']);
    $this->assertSame('[REDACTED]', $result['card_number']);
  }

  /**
   * @covers ::scrub
   */
  public function testScrubHandlesNestedArrays(): void {
    $input = [
      'card' => [
        'number' => '4242424242424242',
        'cvc' => '123',
        'brand' => 'visa',
      ],
      'metadata' => [
        'order_id' => '42',
      ],
    ];

    $result = SensitiveDataScrubber::scrub($input);

    $this->assertSame('[REDACTED]', $result['card']['number']);
    $this->assertSame('[REDACTED]', $result['card']['cvc']);
    $this->assertSame('visa', $result['card']['brand']);
    $this->assertSame('42', $result['metadata']['order_id']);
  }

  /**
   * @covers ::scrub
   */
  public function testScrubPreservesNonSensitiveData(): void {
    $input = [
      '@id' => 'pi_123',
      '@amount' => 5000,
      '@currency' => 'aud',
    ];

    $result = SensitiveDataScrubber::scrub($input);

    $this->assertSame($input, $result);
  }

  /**
   * @covers ::containsSensitiveKeys
   */
  public function testContainsSensitiveKeysReturnsTrueForMatch(): void {
    $data = [
      'payment_method' => 'pm_123',
      'card_number' => '4242424242424242',
    ];

    $this->assertTrue(SensitiveDataScrubber::containsSensitiveKeys($data));
  }

  /**
   * @covers ::containsSensitiveKeys
   */
  public function testContainsSensitiveKeysReturnsFalseForCleanData(): void {
    $data = [
      'payment_method' => 'pm_123',
      'amount' => 5000,
      'currency' => 'aud',
    ];

    $this->assertFalse(SensitiveDataScrubber::containsSensitiveKeys($data));
  }

  /**
   * @covers ::containsSensitiveKeys
   */
  public function testContainsSensitiveKeysDetectsNestedKeys(): void {
    $data = [
      'card' => [
        'cvc' => '123',
      ],
    ];

    $this->assertTrue(SensitiveDataScrubber::containsSensitiveKeys($data));
  }

  /**
   * @covers ::scrub
   */
  public function testScrubHandlesEmptyArray(): void {
    $this->assertSame([], SensitiveDataScrubber::scrub([]));
  }

  /**
   * @covers ::scrub
   */
  public function testScrubIsCaseInsensitiveForKeys(): void {
    $input = [
      'CVC' => '123',
      'Card_Number' => '4242424242424242',
      'CVV' => '456',
    ];

    $result = SensitiveDataScrubber::scrub($input);

    $this->assertSame('[REDACTED]', $result['CVC']);
    $this->assertSame('[REDACTED]', $result['Card_Number']);
    $this->assertSame('[REDACTED]', $result['CVV']);
  }

}
