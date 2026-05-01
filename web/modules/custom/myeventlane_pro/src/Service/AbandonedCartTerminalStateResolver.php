<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\state_machine\WorkflowManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves terminal workflow states for abandoned-cart order checks.
 */
final class AbandonedCartTerminalStateResolver {

  /**
   * Cached terminal states keyed by workflow id.
   *
   * @var array<string, array<string, bool>>
   */
  private array $terminalStatesByWorkflow = [];

  /**
   * Constructs the terminal state resolver.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly WorkflowManagerInterface $workflowManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Determines whether the order is currently in a terminal workflow state.
   */
  public function isTerminalState(OrderInterface $order): bool {
    $orderType = $this->entityTypeManager
      ->getStorage('commerce_order_type')
      ->load($order->bundle());
    if (!$orderType instanceof OrderTypeInterface) {
      return FALSE;
    }

    $workflowId = $orderType->getWorkflowId();
    if ($workflowId === '') {
      return FALSE;
    }

    if (!isset($this->terminalStatesByWorkflow[$workflowId])) {
      $this->terminalStatesByWorkflow[$workflowId] = $this->discoverTerminalStates($workflowId);
    }

    return isset($this->terminalStatesByWorkflow[$workflowId][$order->getState()->getId()]);
  }

  /**
   * Discovers terminal states for a workflow id.
   *
   * @return array<string, bool>
   *   Terminal state id lookup.
   */
  private function discoverTerminalStates(string $workflowId): array {
    $terminalStates = [];

    try {
      $workflow = $this->workflowManager->createInstance($workflowId);
      $states = $workflow->getStates();
      $fromStates = [];

      foreach ($workflow->getTransitions() as $transition) {
        foreach ($transition->getFromStateIds() as $fromStateId) {
          $fromStates[$fromStateId] = TRUE;
        }
      }

      foreach (array_keys($states) as $stateId) {
        if (!isset($fromStates[$stateId])) {
          $terminalStates[$stateId] = TRUE;
        }
      }
    }
    catch (\Throwable $exception) {
      $this->logger->error('Failed terminal-state discovery for workflow "@workflow": @message', [
        '@workflow' => $workflowId,
        '@message' => $exception->getMessage(),
      ]);
    }

    return $terminalStates;
  }

}
