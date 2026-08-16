<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_launch\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the authenticated ownership boundary for the Stripe payout webhook.
 *
 * @group myeventlane_launch
 */
final class StripeWebhookOwnershipContractTest extends TestCase {

  /**
   * The launch module must not decorate or retry the payout webhook.
   */
  public function testLaunchDoesNotDecoratePayoutWebhook(): void {
    $modulePath = dirname(__DIR__, 3);
    $services = file_get_contents($modulePath . '/myeventlane_launch.services.yml');

    self::assertIsString($services);
    self::assertStringNotContainsString('stripe_webhook_controller', $services);
    self::assertStringNotContainsString('webhook_processor', $services);
    self::assertFileDoesNotExist($modulePath . '/src/Decorator/StripeWebhookControllerDecorator.php');
    self::assertFileDoesNotExist($modulePath . '/src/Service/StripeWebhookProcessor.php');
  }

  /**
   * The owning controller must authenticate before handling an event.
   */
  public function testOwnerVerifiesSignatureBeforeDispatch(): void {
    $controllerPath = dirname(__DIR__, 4)
      . '/myeventlane_admin_dashboard/src/Controller/StripeWebhookController.php';
    $controller = file_get_contents($controllerPath);

    self::assertIsString($controller);
    $verificationPosition = strpos($controller, 'Webhook::constructEvent(');
    $dispatchPosition = strpos($controller, 'return $this->dispatch($event);');

    self::assertNotFalse($verificationPosition);
    self::assertNotFalse($dispatchPosition);
    self::assertLessThan($dispatchPosition, $verificationPosition);
  }

  /**
   * Duplicate paid transfers remain harmless at the mutation boundary.
   */
  public function testOwnerRetainsMutationLocalDuplicateGuard(): void {
    $controllerPath = dirname(__DIR__, 4)
      . '/myeventlane_admin_dashboard/src/Controller/StripeWebhookController.php';
    $controller = file_get_contents($controllerPath);

    self::assertIsString($controller);
    self::assertStringContainsString("if (\$row->status === 'paid')", $controller);
    self::assertStringContainsString('$row->transfer_id === $transferId', $controller);
    self::assertStringContainsString('Idempotent skip.', $controller);
  }

}
