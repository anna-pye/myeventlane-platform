<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_auth\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\myeventlane_auth\Service\SocialLoginProviderAvailability;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\myeventlane_auth\Service\SocialLoginProviderAvailability
 * @group myeventlane_auth
 */
final class SocialLoginProviderAvailabilityTest extends UnitTestCase {

  /**
   * @covers ::googleIsAvailable
   * @covers ::appleIsAvailable
   */
  public function testProvidersFailClosedUntilEveryCredentialExists(): void {
    $google = $this->config([
      'client_id' => 'google-id.apps.googleusercontent.com',
      'client_secret' => '',
      'scopes' => '',
      'endpoints' => '',
    ]);
    $apple = $this->config([
      'client_id' => 'apple-id',
      'team_id' => 'team-id',
      'key_file_id' => 'key-id',
      'key_file_path' => '',
      'scopes' => '',
      'endpoints' => '',
    ]);
    $factory = $this->configFactory($google, $apple);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);

    $availability = new SocialLoginProviderAvailability($factory, $modules);
    self::assertFalse($availability->googleIsAvailable());
    self::assertFalse($availability->appleIsAvailable());
  }

  /**
   * @covers ::googleIsAvailable
   * @covers ::appleIsAvailable
   */
  public function testConfiguredProvidersAreAvailable(): void {
    $google = $this->config([
      'client_id' => 'google-id.apps.googleusercontent.com',
      'client_secret' => 'secret',
      'scopes' => '',
      'endpoints' => '',
    ]);
    $apple = $this->config([
      'client_id' => 'apple-id',
      'team_id' => 'team-id',
      'key_file_id' => 'key-id',
      'key_file_path' => __FILE__,
      'scopes' => '',
      'endpoints' => '',
    ]);
    $factory = $this->configFactory($google, $apple);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);

    $availability = new SocialLoginProviderAvailability($factory, $modules);
    self::assertTrue($availability->googleIsAvailable());
    self::assertTrue($availability->appleIsAvailable());
  }

  /**
   * @covers ::googleIsAvailable
   * @covers ::appleIsAvailable
   */
  public function testProvidersFailClosedWithUninitialisedOAuth2Settings(): void {
    $google = $this->config([
      'client_id' => 'google-id.apps.googleusercontent.com',
      'client_secret' => 'secret',
    ]);
    $apple = $this->config([
      'client_id' => 'apple-id',
      'team_id' => 'team-id',
      'key_file_id' => 'key-id',
      'key_file_path' => __FILE__,
    ]);
    $factory = $this->configFactory($google, $apple);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);

    $availability = new SocialLoginProviderAvailability($factory, $modules);
    self::assertFalse($availability->googleIsAvailable());
    self::assertFalse($availability->appleIsAvailable());
  }

  /**
   * @covers ::googleIsAvailable
   */
  public function testGoogleFailsClosedWithPlaceholderClientId(): void {
    $google = $this->config([
      'client_id' => 'google-id',
      'client_secret' => 'secret',
      'scopes' => '',
      'endpoints' => '',
    ]);
    $factory = $this->configFactory($google, $this->config([]));
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);

    $availability = new SocialLoginProviderAvailability($factory, $modules);
    self::assertFalse($availability->googleIsAvailable());
  }

  /**
   * Creates a config object with deterministic key values.
   */
  private function config(array $values): ImmutableConfig {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      static fn (string $key): mixed => $values[$key] ?? NULL,
    );
    return $config;
  }

  /**
   * Creates a config factory for both providers.
   */
  private function configFactory(ImmutableConfig $google, ImmutableConfig $apple): ConfigFactoryInterface {
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnMap([
      ['social_auth_google.settings', $google],
      ['social_auth_apple.settings', $apple],
    ]);
    return $factory;
  }

}
