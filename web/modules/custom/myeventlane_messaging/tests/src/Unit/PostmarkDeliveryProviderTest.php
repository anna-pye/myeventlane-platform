<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_messaging\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Http\ClientFactory;
use Drupal\myeventlane_messaging\Service\Delivery\PostmarkDeliveryProvider;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;

/**
 * Tests Postmark delivery metadata and uncertain outcomes.
 *
 * @coversDefaultClass \Drupal\myeventlane_messaging\Service\Delivery\PostmarkDeliveryProvider
 *
 * @group myeventlane_messaging
 */
final class PostmarkDeliveryProviderTest extends UnitTestCase {

  /**
   * @covers ::send
   */
  public function testInternalMessageIdIsSentAsMetadata(): void {
    $history = [];
    $provider = $this->provider([
      new Response(200, [], json_encode([
        'MessageID' => 'provider-message-id',
      ], JSON_THROW_ON_ERROR)),
    ], $history);

    $this->assertTrue($provider->send($this->params()));
    $this->assertSame('provider-message-id', $provider->getLastProviderMessageId());

    $payload = json_decode(
      (string) $history[0]['request']->getBody(),
      TRUE,
      flags: JSON_THROW_ON_ERROR,
    );
    $this->assertSame(
      'internal-message-id',
      $payload['Metadata']['mel_message_id'],
    );
  }

  /**
   * @covers ::send
   */
  public function testTransportExceptionIsNotReportedAsConfirmedFailure(): void {
    $request = new Request('POST', 'https://api.postmarkapp.com/email');
    $history = [];
    $provider = $this->provider([
      new RequestException('Connection interrupted', $request),
    ], $history);

    $this->expectException(RequestException::class);
    $provider->send($this->params());
  }

  /**
   * Builds a provider backed by a deterministic Guzzle handler.
   *
   * @param array $responses
   *   Mock handler responses or exceptions.
   * @param array $history
   *   Captured Guzzle transactions.
   */
  private function provider(array $responses, array &$history): PostmarkDeliveryProvider {
    $handler = HandlerStack::create(new MockHandler($responses));
    $handler->push(Middleware::history($history));
    $client = new Client(['handler' => $handler]);

    $httpFactory = $this->createMock(ClientFactory::class);
    $httpFactory->method('fromOptions')->willReturn($client);

    $config = $this->createMock(ImmutableConfig::class);
    $values = [
      'postmark.server_token' => 'test-token',
      'postmark.message_stream' => 'outbound',
      'from_email' => 'hello@example.com',
      'from_name' => 'MyEventLane',
      'reply_to' => 'support@example.com',
    ];
    $config->method('get')->willReturnCallback(
      static fn(string $key): mixed => $values[$key] ?? NULL,
    );
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('myeventlane_messaging.settings')
      ->willReturn($config);

    return new PostmarkDeliveryProvider(
      $httpFactory,
      $configFactory,
      $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * Returns a valid Postmark delivery payload.
   */
  private function params(): array {
    return [
      'to' => 'anna@example.com',
      'subject' => 'Test message',
      'html' => '<p>Test</p>',
      'mel_message_id' => 'internal-message-id',
    ];
  }

}
