<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_checkout_flow\Unit;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityAccessControlHandlerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_checkout_flow\Access\MyTicketsOrderAccess;
use Drupal\Tests\UnitTestCase;

/**
 * Tests My Tickets order access continuity.
 *
 * @group myeventlane_checkout_flow
 */
final class MyTicketsOrderAccessTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $container = new ContainerBuilder();
    $container->set('cache_tags.invalidator', $this->createMock(CacheTagsInvalidatorInterface::class));
    $cacheContextsManager = $this->getMockBuilder(CacheContextsManager::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['assertValidTokens'])
      ->getMock();
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Guest checkout orders remain accessible to matching authenticated emails.
   */
  public function testAllowsGuestOrderAccessForMatchingEmail(): void {
    $order = $this->createMock(OrderInterface::class);
    $order->method('getCustomerId')->willReturn(0);
    $order->method('getEmail')->willReturn('guest@example.test');
    $order->method('getCacheTags')->willReturn([]);
    $order->method('getCacheContexts')->willReturn([]);
    $order->method('getCacheMaxAge')->willReturn(-1);

    $account = $this->createMock(AccountInterface::class);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('getEmail')->willReturn(' Guest@Example.Test ');

    $routeMatch = $this->createMock(RouteMatchInterface::class);
    $routeMatch->method('getParameter')
      ->with('commerce_order')
      ->willReturn($order);

    $accessHandler = $this->createMock(EntityAccessControlHandlerInterface::class);
    $accessHandler->method('access')
      ->with($order, 'view', $account, TRUE)
      ->willReturn(AccessResult::forbidden());

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getAccessControlHandler')
      ->with('commerce_order')
      ->willReturn($accessHandler);

    $access = new MyTicketsOrderAccess($entityTypeManager);

    $result = $access->access($routeMatch, $account);

    $this->assertTrue($result->isAllowed());
    $this->assertContains('user', $result->getCacheContexts());
  }

}
