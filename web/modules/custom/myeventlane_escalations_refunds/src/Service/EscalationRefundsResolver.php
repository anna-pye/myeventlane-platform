<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_refunds\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_escalations\Entity\Escalation;
use Drupal\myeventlane_escalations_refunds\Repository\RefundsCorrelationRepository;

/**
 * Resolves refund data for a single escalation.
 *
 * READ-ONLY. Prefers order_id; falls back to event_id.
 * Optionally loads the Commerce order for totals (staff context only).
 */
final class EscalationRefundsResolver {

  public function __construct(
    private readonly RefundsCorrelationRepository $repository,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Resolves all refund data linked to an escalation.
   *
   * @param \Drupal\myeventlane_escalations\Entity\Escalation $escalation
   *   The escalation entity.
   *
   * @return array{logs: array, requests: array, order: object|null, event_id: int|null}
   *   Resolved refund context.
   */
  public function resolve(Escalation $escalation): array {
    $result = [
      'logs' => [],
      'requests' => [],
      'order' => NULL,
      'event_id' => NULL,
    ];

    $has_order = !$escalation->get('order_id')->isEmpty();
    $has_event = !$escalation->get('event_id')->isEmpty();

    if ($has_order) {
      $order_id = (int) $escalation->get('order_id')->target_id;
      $result['logs'] = $this->repository->findLogsByOrderId($order_id);
      $result['requests'] = $this->repository->findRequestsByOrderId($order_id);

      // Load the Commerce order for totals display.
      try {
        $order = $this->entityTypeManager->getStorage('commerce_order')->load($order_id);
        if ($order) {
          $result['order'] = $order;
        }
      }
      catch (\Throwable) {
        // Commerce order load failed; proceed without it.
      }
    }
    elseif ($has_event) {
      $event_id = (int) $escalation->get('event_id')->target_id;
      $result['event_id'] = $event_id;
      $result['logs'] = $this->repository->findLogsByEventId($event_id);
      $result['requests'] = $this->repository->findRequestsByEventId($event_id);
    }

    return $result;
  }

}
