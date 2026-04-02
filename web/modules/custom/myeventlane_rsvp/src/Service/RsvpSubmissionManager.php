<?php

declare(strict_types=1);

namespace Drupal\myeventlane_rsvp\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_rsvp\Entity\RsvpSubmission;
use Drupal\myeventlane_rsvp\Entity\RsvpSubmissionInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Centralized RSVP submission logic.
 */
final class RsvpSubmissionManager {

  private const FLOOD_LIMIT = 10;
  private const FLOOD_WINDOW = 3600;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RsvpCapacityService $capacityService,
    private readonly FloodInterface $flood,
    private readonly LockBackendInterface $lock,
    private readonly AccountProxyInterface $currentUser,
    private readonly RequestStack $requestStack,
  ) {}

  public function submitOrUpdate(NodeInterface $event, array $data, ?int $capacity = NULL, ?array $legalConsent = NULL): RsvpSubmissionInterface {
    $event_id = (int) $event->id();
    $uid = (int) $this->currentUser->id();
    $email = trim((string) ($data['email'] ?? ''));
    $name = trim((string) ($data['name'] ?? ''));
    $donation = (float) ($data['donation'] ?? 0);
    $guestCount = (int) ($data['quantity'] ?? $data['guest_count'] ?? $data['guests'] ?? 1);
    $guestCount = max(1, $guestCount);
    $mode = (string) ($data['mode'] ?? 'create');

    if ($email === '') {
      throw new \InvalidArgumentException('Email is required.');
    }

    $identifier = NULL;
    if ($uid === 0) {
      $identifier = 'myeventlane_rsvp.submit:' . ($this->requestStack->getCurrentRequest()?->getClientIp() ?? '0');
      if (!$this->flood->isAllowed('myeventlane_rsvp.submit', self::FLOOD_LIMIT, self::FLOOD_WINDOW, $identifier)) {
        throw new \RuntimeException('Too many RSVP attempts. Please try again later.');
      }
    }

    $lock_name = 'rsvp:event:' . $event_id;
    if (!$this->lock->acquire($lock_name, 5)) {
      throw new \RuntimeException('Could not acquire lock. Please try again.');
    }

    try {
      $storage = $this->entityTypeManager->getStorage('rsvp_submission');

      $existing = $mode === 'edit' ? $this->findExisting($event_id, $uid, $email) : NULL;
      if ($existing) {
        $this->updateSubmission($existing, $data);

        // RSVP is confirmed regardless of donation; donation is optional.
        if ($existing->hasField('status')) {
          $existing->set('status', 'confirmed');
        }

        if ($legalConsent !== NULL) {
          $existing->_myeventlaneLegalConsent = $legalConsent;
        }

        $existing->save();
        if ($identifier !== NULL) {
          $this->flood->register('myeventlane_rsvp.submit', self::FLOOD_WINDOW, $identifier);
        }
        return $existing;
      }

      if ($capacity !== NULL && $this->capacityService->isAtCapacity($event_id, $capacity)) {
        if (class_exists(\Drupal\myeventlane_capacity\Exception\CapacityExceededException::class)) {
          throw new \Drupal\myeventlane_capacity\Exception\CapacityExceededException();
        }
        throw new \RuntimeException('This event is at capacity.');
      }

      // RSVP is confirmed regardless of donation amount; donation is optional.
      $status = 'confirmed';

      $values = [
        'event_id' => ['target_id' => $event_id],
        'attendee_name' => $name,
        'name' => $name,
        'email' => $email,
        'phone' => trim((string) ($data['phone'] ?? '')),
        'guests' => $guestCount,
        'donation' => $donation,
        'status' => $status,
        'user_id' => ['target_id' => $uid > 0 ? $uid : 0],
      ];

      /** @var \Drupal\myeventlane_rsvp\Entity\RsvpSubmissionInterface $submission */
      $submission = $storage->create($values);
      if ($submission instanceof RsvpSubmission && $submission->hasField('quantity')) {
        $submission->set('quantity', $guestCount);
      }
      if ($legalConsent !== NULL) {
        $submission->_myeventlaneLegalConsent = $legalConsent;
      }
      $submission->save();

      if ($identifier !== NULL) {
        $this->flood->register('myeventlane_rsvp.submit', self::FLOOD_WINDOW, $identifier);
      }

      return $submission;
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Finds an existing RSVP for the current dedupe key (event + user/email).
   */
  public function findExistingForEventAndEmail(NodeInterface $event, string $email): ?RsvpSubmissionInterface {
    $event_id = (int) $event->id();
    $uid = (int) $this->currentUser->id();
    $email = trim($email);
    if ($email === '') {
      return NULL;
    }
    return $this->findExisting($event_id, $uid, $email);
  }

  private function findExisting(int $event_id, int $uid, string $email): ?RsvpSubmissionInterface {
    $storage = $this->entityTypeManager->getStorage('rsvp_submission');

    if ($uid > 0) {
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('event_id', $event_id)
        ->condition('user_id', $uid)
        ->range(0, 1)
        ->execute();
      if ($ids) {
        $entity = $storage->load(reset($ids));
        return $entity instanceof RsvpSubmissionInterface ? $entity : NULL;
      }
      return NULL;
    }

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event_id', $event_id)
      ->condition('email', $email)
      ->range(0, 1)
      ->execute();

    if ($ids) {
      $entity = $storage->load(reset($ids));
      return $entity instanceof RsvpSubmissionInterface ? $entity : NULL;
    }

    return NULL;
  }

  private function updateSubmission(RsvpSubmissionInterface $submission, array $data): void {
    if (!$submission instanceof RsvpSubmission) {
      return;
    }
    $submission->set('attendee_name', trim((string) ($data['name'] ?? $submission->get('attendee_name')->value)));
    $submission->set('name', trim((string) ($data['name'] ?? $submission->get('name')->value)));
    $submission->set('phone', trim((string) ($data['phone'] ?? $submission->get('phone')->value)));
    $guestCount = (int) ($data['quantity'] ?? $data['guest_count'] ?? $data['guests'] ?? $submission->get('guests')->value);
    $guestCount = max(1, $guestCount);
    $submission->set('guests', $guestCount);
    if ($submission->hasField('quantity')) {
      $submission->set('quantity', $guestCount);
    }
    $submission->set('donation', (float) ($data['donation'] ?? $submission->get('donation')->value));
  }

}