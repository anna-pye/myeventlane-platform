<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_messaging\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\myeventlane_messaging\Service\Delivery\DeliveryProviderManager;
use Drupal\myeventlane_messaging\Service\Delivery\DrupalMailProvider;
use Drupal\myeventlane_messaging\Service\Delivery\PostmarkDeliveryProvider;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\myeventlane_messaging\Service\Delivery\DeliveryProviderManager
 *
 * @group myeventlane_messaging
 */
final class DeliveryProviderManagerTest extends UnitTestCase {

  /**
   * @covers ::getProvider
   */
  public function testConfiguredDrupalMailReturnsDrupalMailProvider(): void {
    $manager = $this->manager('drupal_mail');
    $this->assertSame('drupal_mail', $manager->getProvider()->id());
  }

  /**
   * @covers ::getProvider
   */
  public function testConfiguredPostmarkReturnsPostmarkProvider(): void {
    $manager = $this->manager('postmark');
    $this->assertSame('postmark', $manager->getProvider()->id());
  }

  /**
   * @covers ::getProvider
   */
  public function testExplicitProviderOverridesConfig(): void {
    $manager = $this->manager('drupal_mail');
    $this->assertSame('postmark', $manager->getProvider('postmark')->id());
  }

  /**
   * @covers ::getProvider
   */
  public function testUnknownProviderFallsBackToDrupalMail(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        'Unknown delivery provider "@id"; falling back to drupal_mail.',
        ['@id' => 'invalid-provider'],
      );

    $manager = $this->manager('drupal_mail', $logger);
    $this->assertSame('drupal_mail', $manager->getProvider('invalid-provider')->id());
  }

  /**
   * @covers ::getProvider
   */
  public function testUnknownConfiguredProviderFallsBackToDrupalMail(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        'Unknown delivery provider "@id"; falling back to drupal_mail.',
        ['@id' => 'invalid-provider'],
      );

    $manager = $this->manager('invalid-provider', $logger);
    $this->assertSame('drupal_mail', $manager->getProvider()->id());
  }

  private function manager(string $deliveryProvider, ?LoggerInterface $logger = NULL): DeliveryProviderManager {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static function (string $key) use ($deliveryProvider) {
      return $key === 'delivery_provider' ? $deliveryProvider : NULL;
    });
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('myeventlane_messaging.settings')->willReturn($config);

    $channelLogger = $logger ?? $this->createMock(LoggerInterface::class);
    $mailManager = $this->createMock(MailManagerInterface::class);
    $drupalMail = new DrupalMailProvider($mailManager, $channelLogger);
    $httpFactory = $this->createMock(ClientFactory::class);
    $postmark = new PostmarkDeliveryProvider($httpFactory, $factory, $channelLogger);

    return new DeliveryProviderManager($drupalMail, $postmark, $factory, $channelLogger);
  }

}
