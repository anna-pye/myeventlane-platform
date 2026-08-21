<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_core\Unit;

use Drupal\myeventlane_core\Service\StripeService;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_core\Service\StripeService
 *
 * @group myeventlane_core
 */
final class StripeManageDestinationTest extends UnitTestCase {

  /**
   * @covers ::resolveDirectChargeManageDestinationFromEligibility
   * @dataProvider directChargeDestinationProvider
   *
   * @param array<string, mixed> $eligibility
   */
  public function testResolveDirectChargeManageDestinationFromEligibility(array $eligibility, string $expected): void {
    $this->assertSame($expected, StripeService::resolveDirectChargeManageDestinationFromEligibility($eligibility));
  }

  /**
   * @return array<string, array{0: array<string, mixed>, 1: string}>
   */
  public static function directChargeDestinationProvider(): array {
    return [
      'legacy Express account must reconnect' => [
        [
          'eligible' => FALSE,
          'configuration_compatible' => FALSE,
          'reason' => 'The organiser cannot manage this account through the full Stripe Dashboard.',
          'account_type' => 'express',
        ],
        StripeService::MANAGE_DEST_RECONNECT,
      ],
      'compatible ready account uses full Dashboard' => [
        [
          'eligible' => TRUE,
          'configuration_compatible' => TRUE,
          'reason' => NULL,
          'account_type' => 'standard',
        ],
        StripeService::MANAGE_DEST_STRIPE_DASHBOARD,
      ],
      'compatible incomplete account resumes onboarding' => [
        [
          'eligible' => FALSE,
          'configuration_compatible' => TRUE,
          'reason' => 'Stripe has not enabled card charges for this account.',
          'account_type' => 'standard',
        ],
        StripeService::MANAGE_DEST_ONBOARDING,
      ],
      'failed verification fails closed' => [
        [
          'eligible' => FALSE,
          'configuration_compatible' => NULL,
          'reason' => 'Stripe account eligibility could not be verified.',
          'account_type' => NULL,
        ],
        StripeService::MANAGE_DEST_UNSUPPORTED,
      ],
      'deleted compatible account fails closed' => [
        [
          'eligible' => FALSE,
          'configuration_compatible' => TRUE,
          'reason' => 'The connected Stripe account has been deleted.',
          'account_type' => 'standard',
        ],
        StripeService::MANAGE_DEST_UNSUPPORTED,
      ],
    ];
  }

  /**
   * @covers ::resolveManageDestinationFromEligibility
   * @dataProvider destinationProvider
   *
   * @param array<string, mixed> $eligibility
   */
  public function testResolveManageDestinationFromEligibility(array $eligibility, string $expected): void {
    $this->assertSame($expected, StripeService::resolveManageDestinationFromEligibility($eligibility));
  }

  /**
   * @return array<string, array{0: array<string, mixed>, 1: string}>
   */
  public static function destinationProvider(): array {
    return [
      'express charge-ready uses login link' => [
        [
          'eligible' => TRUE,
          'account' => NULL,
          'reason' => NULL,
          'account_type' => 'express',
        ],
        StripeService::MANAGE_DEST_LOGIN_LINK,
      ],
      'standard charge-ready uses vendor dashboard' => [
        [
          'eligible' => TRUE,
          'account' => NULL,
          'reason' => NULL,
          'account_type' => 'standard',
        ],
        StripeService::MANAGE_DEST_STRIPE_DASHBOARD,
      ],
      'incomplete details resumes onboarding' => [
        [
          'eligible' => FALSE,
          'account' => NULL,
          'reason' => 'Account details not yet submitted',
          'account_type' => 'standard',
        ],
        StripeService::MANAGE_DEST_ONBOARDING,
      ],
      'incomplete charges resumes onboarding' => [
        [
          'eligible' => FALSE,
          'account' => NULL,
          'reason' => 'Account charges not yet enabled',
          'account_type' => 'standard',
        ],
        StripeService::MANAGE_DEST_ONBOARDING,
      ],
      'deleted account fails closed' => [
        [
          'eligible' => FALSE,
          'account' => NULL,
          'reason' => 'Account has been deleted',
          'account_type' => 'standard',
        ],
        StripeService::MANAGE_DEST_UNSUPPORTED,
      ],
      'retrieve failure fails closed' => [
        [
          'eligible' => FALSE,
          'account' => NULL,
          'reason' => 'Failed to retrieve account',
          'account_type' => NULL,
        ],
        StripeService::MANAGE_DEST_UNSUPPORTED,
      ],
      'custom type fails closed' => [
        [
          'eligible' => TRUE,
          'account' => NULL,
          'reason' => NULL,
          'account_type' => 'custom',
        ],
        StripeService::MANAGE_DEST_UNSUPPORTED,
      ],
      'unknown type fails closed' => [
        [
          'eligible' => TRUE,
          'account' => NULL,
          'reason' => NULL,
          'account_type' => '',
        ],
        StripeService::MANAGE_DEST_UNSUPPORTED,
      ],
    ];
  }

  /**
   * Eligibility reasons must not embed account IDs for manage messaging paths.
   */
  public function testRetrieveFailureReasonIsSanitized(): void {
    $reason = StripeService::resolveManageDestinationFromEligibility([
      'eligible' => FALSE,
      'account' => NULL,
      'reason' => 'Failed to retrieve account',
      'account_type' => NULL,
    ]);
    $this->assertSame(StripeService::MANAGE_DEST_UNSUPPORTED, $reason);
    $this->assertStringNotContainsString('acct_', 'Failed to retrieve account');
  }

}
