<?php

declare(strict_types=1);

namespace Drupal\myeventlane_refunds\Controller;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\myeventlane_refunds\Form\VendorRefundForm;
use Drupal\myeventlane_refunds\Form\VendorRefundRequestApproveForm;
use Drupal\myeventlane_refunds\Form\VendorRefundRequestRejectForm;
use Drupal\myeventlane_vendor\Service\VendorEventTabsService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Keeps organiser refund decisions inside the selected event workspace.
 */
final class VendorRefundWorkspaceController extends ControllerBase {

  public function __construct(
    private readonly FormBuilderInterface $salesFormBuilder,
    private readonly EntityTypeManagerInterface $storageManager,
    private readonly VendorEventTabsService $eventTabsService,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('form_builder'),
      $container->get('entity_type.manager'),
      $container->get('myeventlane_vendor.service.event_tabs'),
      $container->get('request_stack'),
    );
  }

  /**
   * Displays the approval form in Event Studio.
   */
  public function approve(NodeInterface $node, int|string $refund_request): array {
    return $this->workspace($node, $this->salesFormBuilder->getForm(VendorRefundRequestApproveForm::class), 'refund_requests');
  }

  /**
   * Displays the decline form in Event Studio.
   */
  public function reject(NodeInterface $node, int|string $refund_request): array {
    return $this->workspace($node, $this->salesFormBuilder->getForm(VendorRefundRequestRejectForm::class), 'refund_requests');
  }

  /**
   * Displays the direct order refund form in Event Studio.
   */
  public function refund(OrderInterface $commerce_order): array {
    $eventId = (int) $this->requestStack->getCurrentRequest()?->query->get('event', 0);
    $event = $this->storageManager->getStorage('node')->load($eventId);
    if (!$event instanceof NodeInterface) {
      throw new NotFoundHttpException('Event not found.');
    }

    return $this->workspace(
      $event,
      $this->salesFormBuilder->getForm(VendorRefundForm::class, $commerce_order, $event),
      'orders',
    );
  }

  /**
   * Builds the shared selected-event shell without changing form behaviour.
   */
  private function workspace(NodeInterface $event, array $form, string $activeTab): array {
    $form['#attributes']['class'][] = 'mel-sales-ops-form';
    $form['#attached']['library'][] = 'myeventlane_refunds/mel_refund_ui';

    return [
      '#theme' => 'mel_event_workspace',
      '#event' => $event,
      '#tabs' => $this->eventTabsService->getTabs($event, $activeTab),
      '#actions' => [],
      '#meta' => NULL,
      '#sidebar' => NULL,
      '#content' => $form,
      '#attached' => [
        'library' => [
          'myeventlane_vendor_theme/global-styling',
          'myeventlane_refunds/mel_refund_ui',
        ],
      ],
    ];
  }

}
