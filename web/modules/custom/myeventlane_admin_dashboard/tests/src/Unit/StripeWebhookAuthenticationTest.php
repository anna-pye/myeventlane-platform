<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_admin_dashboard\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\myeventlane_admin_dashboard\Controller\StripeWebhookController;
use Drupal\myeventlane_core\Service\StripeService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies that untrusted payout webhook payloads cannot reach the database.
 *
 * @group myeventlane_admin_dashboard
 */
final class StripeWebhookAuthenticationTest extends TestCase {

  /**
   * Covers missing and invalid Stripe signatures.
   */
  #[DataProvider('invalidSignatureProvider')]
  public function testUntrustedPayloadIsRejectedBeforeDatabaseAccess(string $signature): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('stripe_webhook_secret')
      ->willReturn('whsec_test_only');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('myeventlane_admin_dashboard.settings')
      ->willReturn($config);

    $database = $this->createMock(Connection::class);
    $database->expects(self::never())->method('schema');

    $stripeService = (new \ReflectionClass(StripeService::class))
      ->newInstanceWithoutConstructor();
    $controller = new StripeWebhookController(
      $database,
      $this->createMock(TimeInterface::class),
      $this->createMock(LoggerInterface::class),
      $configFactory,
      $stripeService,
    );

    $request = Request::create(
      '/admin/myeventlane/payouts/webhook',
      'POST',
      server: ['HTTP_STRIPE_SIGNATURE' => $signature],
      content: '{"id":"evt_unsigned","type":"transfer.paid"}',
    );

    self::assertSame(Response::HTTP_BAD_REQUEST, $controller->handle($request)->getStatusCode());
  }

  /**
   * Invalid signature cases.
   */
  public static function invalidSignatureProvider(): array {
    return [
      'missing signature' => [''],
      'invalid signature' => ['t=1,v1=not-a-valid-signature'],
    ];
  }

}
