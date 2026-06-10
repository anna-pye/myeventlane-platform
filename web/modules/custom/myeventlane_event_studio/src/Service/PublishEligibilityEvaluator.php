<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_vendor\Service\PaidPublishStripeGate;
use Drupal\myeventlane_vendor\Service\VendorPublishRequirementsGate;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Single publish enforcement gate for vendor, Stripe, and event readiness checks.
 */
final class PublishEligibilityEvaluator {

  use StringTranslationTrait;

  public function __construct(
    private readonly VendorPublishRequirementsGate $publishRequirementsGate,
    private readonly PaidPublishStripeGate $paidPublishStripeGate,
    private readonly EventReadinessService $eventReadiness,
    private readonly LoggerInterface $logger,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Evaluates whether an event may be published for the given account.
   *
   * @return array{
   *   allowed: bool,
   *   reason: string|null,
   *   messages: list<string>,
   * }
   */
  public function evaluate(NodeInterface $event, AccountInterface $account): array {
    try {
      return $this->evaluateInternal($event, $account);
    }
    catch (\Throwable $e) {
      $this->logger->error('Event Studio publish eligibility evaluation failed for event @nid uid=@uid: @message', [
        '@nid' => (string) ($event->id() ?? 'new'),
        '@uid' => (string) $account->id(),
        '@message' => $e->getMessage(),
      ]);
      return [
        'allowed' => FALSE,
        'reason' => 'evaluation_failed',
        'messages' => [(string) $this->t('Publish eligibility could not be verified. Try again shortly.')],
      ];
    }
  }

  /**
   * @return array{
   *   allowed: bool,
   *   reason: string|null,
   *   messages: list<string>,
   * }
   */
  private function evaluateInternal(NodeInterface $event, AccountInterface $account): array {
    if ($event->bundle() !== 'event') {
      return [
        'allowed' => FALSE,
        'reason' => 'invalid_event',
        'messages' => [(string) $this->t('Expected event node.')],
      ];
    }

    $denials = $this->publishRequirementsGate->getLivePublishDenialReasons($account);
    if ($denials !== []) {
      return [
        'allowed' => FALSE,
        'reason' => 'vendor_denied',
        'messages' => array_values($denials),
      ];
    }

    $eventType = $this->eventType($event);
    if (in_array($eventType, ['paid', 'both'], TRUE)) {
      $eventNodeId = $event->id() ? (int) $event->id() : NULL;
      $stripeMsg = $this->paidPublishStripeGate->validatePaidPublishAllowed($account, $eventNodeId);
      if ($stripeMsg !== NULL) {
        return [
          'allowed' => FALSE,
          'reason' => 'stripe',
          'messages' => [$stripeMsg],
        ];
      }
    }

    $readiness = $this->eventReadiness->evaluate($event, $account);
    if (!$readiness->ready) {
      return [
        'allowed' => FALSE,
        'reason' => 'readiness',
        'messages' => $readiness->errors !== [] ? $readiness->errors : [(string) $this->t('Cannot publish yet.')],
      ];
    }

    return [
      'allowed' => TRUE,
      'reason' => NULL,
      'messages' => [],
    ];
  }

  private function eventType(NodeInterface $event): string {
    if ($event->hasField('field_event_type') && !$event->get('field_event_type')->isEmpty()) {
      return (string) $event->get('field_event_type')->value;
    }
    return 'rsvp';
  }

}
