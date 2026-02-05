<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Access;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\myeventlane_refunds\Service\BuyerRefundEligibilityService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Access check for buyer self-service refund form.
 */
final class BuyerRefundAccessCheck {

  /**
   * Constructs BuyerRefundAccessCheck.
   *
   * @param \Drupal\myeventlane_refunds\Service\BuyerRefundEligibilityService $eligibility
   *   The buyer refund eligibility service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly BuyerRefundEligibilityService $eligibility,
    private readonly RequestStack $requestStack,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('myeventlane_refunds.buyer_eligibility'),
      $container->get('request_stack'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Checks access for the buyer refund route.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(RouteMatchInterface $route_match, AccountInterface $account): AccessResultInterface {
    $order = $route_match->getParameter('commerce_order');
    if (!$order instanceof OrderInterface) {
      return AccessResult::forbidden('Order not found.');
    }

    if ($account->isAnonymous()) {
      return AccessResult::forbidden('You must be logged in to request a refund.')
        ->addCacheContexts(['user.roles:anonymous']);
    }

    $eventId = (int) $this->requestStack->getCurrentRequest()?->query->get('event', 0);
    if (!$eventId) {
      return AccessResult::forbidden('Event parameter required.');
    }

    $event = $this->entityTypeManager->getStorage('node')->load($eventId);
    if (!$event instanceof NodeInterface || $event->bundle() !== 'event') {
      return AccessResult::forbidden('Event not found.');
    }

    $allowed = $this->eligibility->isEligible($order, $event, $account);

    return AccessResult::allowedIf($allowed)
      ->addCacheableDependency($order)
      ->addCacheableDependency($event)
      ->addCacheContexts(['user']);
  }

}
