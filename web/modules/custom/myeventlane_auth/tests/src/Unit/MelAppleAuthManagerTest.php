<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_auth\Unit;

use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\myeventlane_auth\SocialAuth\MelAppleAuthManager;
use League\OAuth2\Client\Provider\Apple;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * @coversDefaultClass \Drupal\myeventlane_auth\SocialAuth\MelAppleAuthManager
 * @group myeventlane_auth
 */
final class MelAppleAuthManagerTest extends TestCase {

  /**
   * @covers ::getAuthorizationUrl
   */
  public function testAuthorizationRequestStoresAndSendsSingleUseNonce(): void {
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnMap([
      ['scopes', ''],
    ]);
    $configFactory = $this->createMock(ConfigFactory::class);
    $configFactory->method('get')->with('social_auth_apple.settings')->willReturn($settings);

    $request = Request::create('/user/login/apple');
    $session = new Session(new MockArraySessionStorage());
    $request->setSession($session);
    $requestStack = new RequestStack();
    $requestStack->push($request);

    $client = $this->createMock(Apple::class);
    $client->expects(self::once())
      ->method('getAuthorizationUrl')
      ->with(self::callback(static function (array $options): bool {
        return $options['scope'] === ['name', 'email']
          && is_string($options['nonce'])
          && preg_match('/^[A-Za-z0-9_-]{43}$/', $options['nonce']) === 1;
      }))
      ->willReturn('https://appleid.apple.com/auth/authorize');

    $manager = new MelAppleAuthManager(
      $configFactory,
      $this->createMock(LoggerChannelFactoryInterface::class),
      $requestStack,
    );
    $manager->setClient($client);

    self::assertSame('https://appleid.apple.com/auth/authorize', $manager->getAuthorizationUrl());
    $nonce = $session->get(MelAppleAuthManager::NONCE_SESSION_KEY);
    self::assertIsString($nonce);
    self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $nonce);
  }

}
