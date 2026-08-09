<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event\Unit;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Utility\UnroutedUrlAssemblerInterface;
use Drupal\myeventlane_event\EventSubscriber\EventCanonicalRedirectSubscriber;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * @coversDefaultClass \Drupal\myeventlane_event\EventSubscriber\EventCanonicalRedirectSubscriber
 * @group myeventlane_event
 */
final class EventCanonicalRedirectSubscriberTest extends UnitTestCase {

  /**
   * @covers ::onRequest
   */
  public function testRawEventPathRedirectsAndPreservesQuery(): void {
    $aliasManager = $this->createMock(AliasManagerInterface::class);
    $aliasManager->expects(self::once())
      ->method('getAliasByPath')
      ->with('/node/123', 'en')
      ->willReturn('/events/community-market');

    $assembler = $this->createMock(UnroutedUrlAssemblerInterface::class);
    $assembler->expects(self::once())
      ->method('assemble')
      ->with('base:events/community-market', [
        'query' => ['utm_source' => 'newsletter'],
      ])
      ->willReturn('/events/community-market?utm_source=newsletter');

    $event = $this->requestEvent('/node/123?utm_source=newsletter');
    $subscriber = new EventCanonicalRedirectSubscriber(
      $aliasManager,
      $this->createMock(AccountInterface::class),
      $assembler,
    );

    $subscriber->onRequest($event);

    self::assertTrue($event->hasResponse());
    self::assertSame(301, $event->getResponse()->getStatusCode());
    self::assertSame(
      '/events/community-market?utm_source=newsletter',
      $event->getResponse()->headers->get('Location'),
    );
  }

  /**
   * @covers ::onRequest
   */
  public function testFriendlyAliasDoesNotRedirect(): void {
    $event = $this->requestEvent('/events/community-market');
    $subscriber = new EventCanonicalRedirectSubscriber(
      $this->createMock(AliasManagerInterface::class),
      $this->createMock(AccountInterface::class),
      $this->createMock(UnroutedUrlAssemblerInterface::class),
    );

    $subscriber->onRequest($event);

    self::assertFalse($event->hasResponse());
  }

  /**
   * @covers ::onRequest
   */
  public function testInaccessibleEventDoesNotRedirect(): void {
    $event = $this->requestEvent('/node/123', FALSE);
    $aliasManager = $this->createMock(AliasManagerInterface::class);
    $aliasManager->expects(self::never())->method('getAliasByPath');
    $subscriber = new EventCanonicalRedirectSubscriber(
      $aliasManager,
      $this->createMock(AccountInterface::class),
      $this->createMock(UnroutedUrlAssemblerInterface::class),
    );

    $subscriber->onRequest($event);

    self::assertFalse($event->hasResponse());
  }

  /**
   * Builds a canonical event request.
   */
  private function requestEvent(string $uri, bool $accessible = TRUE): RequestEvent {
    $request = Request::create($uri);
    $request->attributes->set('_route', 'entity.node.canonical');

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');

    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('event');
    $node->method('id')->willReturn(123);
    $node->method('language')->willReturn($language);
    $node->method('access')->willReturn($accessible);
    $request->attributes->set('node', $node);

    return new RequestEvent(
      $this->createMock(HttpKernelInterface::class),
      $request,
      HttpKernelInterface::MAIN_REQUEST,
    );
  }

}
