<?php

declare(strict_types=1);

namespace Drupal\myeventlane_boost\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\myeventlane_boost\Service\BoostPurchaseSuccessResolver;
use Drupal\myeventlane_event\Service\BoostedEventQualityGate;
use Drupal\myeventlane_event\Service\FeaturedEventReadinessRenderBuilder;
use Drupal\myeventlane_vendor\Service\BoostStatusService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Boost purchase success page after Commerce checkout.
 */
final class BoostPurchaseSuccessController extends ControllerBase {

  private const REDIRECT_DELAY_MS = 8000;

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    private readonly BoostPurchaseSuccessResolver $purchaseSuccessResolver,
    private readonly BoostStatusService $boostStatusService,
    private readonly BoostedEventQualityGate $qualityGate,
    private readonly FeaturedEventReadinessRenderBuilder $homepageReadinessRender,
  ) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('myeventlane_boost.purchase_success_resolver'),
      $container->get('myeventlane_vendor.service.boost_status'),
      $container->get('myeventlane_event.boosted_quality_gate'),
      $container->get('myeventlane_event.featured_readiness_render'),
    );
  }

  /**
   * Renders the Boost purchase success experience.
   */
  public function success(NodeInterface $event, Request $request): array {
    $order = $this->loadValidatedOrder($event, $request);
    $purchase = $this->purchaseSuccessResolver->resolvePrimaryBoostPurchase($order);
    if ($purchase === NULL) {
      throw new NotFoundHttpException();
    }

    if (!$this->qualityGate->hasHeroImage($event)) {
      $this->messenger()->addWarning(
        $this->t('Hero image missing. Add a cover photo in Event Studio branding so your boosted event can appear in Community Spotlight.')
      );
    }
    elseif (!$this->qualityGate->hasFocalPoint($event)) {
      $this->messenger()->addWarning(
        $this->t('Focal point missing. Set the hero crop in Event Studio branding so your boosted event can appear in Community Spotlight.')
      );
    }

    $account = $this->currentUser();
    $event_id = (int) $event->id();
    $boost = $this->boostStatusService->getVisibilityPayload($event);
    $workspace_url = $this->safeRouteUrl('myeventlane_event_studio.workspace', ['node' => $event_id], $account);
    $event_url = $this->safeRouteUrl('entity.node.canonical', ['node' => $event_id], $account);
    $analytics_url = $this->safeRouteUrl('myeventlane_vendor.console.event_analytics', ['event' => $event_id], $account);
    if ($analytics_url === '') {
      $analytics_url = $this->safeRouteUrl('myeventlane_event_studio.workspace_analytics', ['node' => $event_id], $account);
    }

    return [
      '#theme' => 'boost_purchase_success',
      '#event' => $event,
      '#order' => $order,
      '#boost_days' => $purchase['boost_days'],
      '#plan_label' => $purchase['plan_label'],
      '#expiry_date' => $boost['expires'],
      '#workspace_url' => $workspace_url,
      '#event_url' => $event_url,
      '#analytics_url' => $analytics_url,
      '#homepage_readiness' => $this->homepageReadinessRender->buildChecklistCard(
        $event,
        TRUE,
        TRUE,
      ),
      '#attached' => [
        'library' => ['myeventlane_boost/boost_purchase_success'],
        'drupalSettings' => [
          'myeventlaneBoostPurchaseSuccess' => [
            'redirectUrl' => $workspace_url,
            'redirectDelayMs' => self::REDIRECT_DELAY_MS,
          ],
        ],
      ],
      '#cache' => [
        'contexts' => ['user', 'url.query_args:order'],
        'tags' => array_merge($event->getCacheTags(), $order->getCacheTags()),
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Loads and validates the order referenced by the success page query.
   */
  private function loadValidatedOrder(NodeInterface $event, Request $request): OrderInterface {
    $order_id = (int) $request->query->get('order', 0);
    if ($order_id <= 0) {
      throw new NotFoundHttpException();
    }

    $order = $this->entityTypeManager->getStorage('commerce_order')->load($order_id);
    if (!$order instanceof OrderInterface) {
      throw new NotFoundHttpException();
    }

    $account = $this->currentUser();
    $customer_id = (int) $order->getCustomerId();
    if ($customer_id !== (int) $account->id()
      && !$account->hasPermission('administer commerce_order')) {
      throw new AccessDeniedHttpException();
    }

    if ($order->getState()->getId() === 'draft') {
      throw new NotFoundHttpException();
    }

    if (!$this->purchaseSuccessResolver->isBoostOnlyOrder($order)) {
      throw new NotFoundHttpException();
    }

    if (!$this->purchaseSuccessResolver->orderMatchesEvent($order, (int) $event->id())) {
      throw new NotFoundHttpException();
    }

    return $order;
  }

  /**
   * Builds a route URL string when the route exists and is accessible.
   *
   * @param array<string, mixed> $parameters
   */
  private function safeRouteUrl(string $route, array $parameters, AccountInterface $account): string {
    try {
      $url = Url::fromRoute($route, $parameters);
      if (!$url->access($account)) {
        return '';
      }
      return $url->toString();
    }
    catch (\Throwable) {
      return '';
    }
  }

}
