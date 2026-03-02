<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\commerce_recurring\Entity\SubscriptionInterface;
use Drupal\state_machine\Plugin\Workflow\WorkflowInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves semantic state categories for Pro subscriptions.
 */
final class ProSubscriptionStateResolver {

  /**
   * Determines whether the subscription is in an active state.
   */
  public function isActive(SubscriptionInterface $subscription): bool {
    return $this->matchesKeywords($subscription, ['active']);
  }

  /**
   * Determines whether the subscription is in a failed-payment state.
   */
  public function isPaymentFailure(SubscriptionInterface $subscription): bool {
    return $this->matchesKeywords($subscription, [
      'past_due',
      'past-due',
      'pastdue',
      'unpaid',
      'failed',
      'payment_failed',
      'payment-failed',
    ]);
  }

  /**
   * Determines whether the subscription is in a cancelled state.
   */
  public function isCancelled(SubscriptionInterface $subscription): bool {
    return $this->matchesKeywords($subscription, [
      'cancel',
      'canceled',
      'cancelled',
    ]);
  }

  /**
   * Determines whether the state is terminal in its workflow.
   */
  public function isTerminal(SubscriptionInterface $subscription): bool {
    $workflow = $this->resolveWorkflow($subscription);
    if (!$workflow instanceof WorkflowInterface) {
      return FALSE;
    }

    $currentStateId = $subscription->getState()->getId();
    if ($currentStateId === '') {
      return FALSE;
    }

    foreach ($workflow->getTransitions() as $transition) {
      if ($transition->getFromStateId() === $currentStateId) {
        return FALSE;
      }
    }

    return TRUE;
  }

  public function __construct(
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Matches state id/label against a semantic keyword set.
   *
   * @param string[] $keywords
   *   Keyword fragments.
   */
  private function matchesKeywords(SubscriptionInterface $subscription, array $keywords): bool {
    $state = $subscription->getState();
    $haystacks = [
      $this->normalize((string) $state->getId()),
      $this->normalize((string) $state->getLabel()),
    ];

    foreach ($keywords as $keyword) {
      $needle = $this->normalize($keyword);
      foreach ($haystacks as $haystack) {
        if ($needle !== '' && str_contains($haystack, $needle)) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * Resolves workflow instance from the state item.
   */
  private function resolveWorkflow(SubscriptionInterface $subscription): ?WorkflowInterface {
    try {
      $workflow = $subscription->getState()->getWorkflow();
      return $workflow instanceof WorkflowInterface ? $workflow : NULL;
    }
    catch (\Throwable $exception) {
      $this->logger->error(
        'Unable to resolve workflow for subscription @id: @message',
        [
          '@id' => (string) $subscription->id(),
          '@message' => $exception->getMessage(),
        ],
      );
      return NULL;
    }
  }

  /**
   * Normalizes a token for comparison.
   */
  private function normalize(string $value): string {
    return str_replace([' ', '-'], '_', strtolower(trim($value)));
  }

}
