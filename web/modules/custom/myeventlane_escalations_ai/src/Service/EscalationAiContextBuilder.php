<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_ai\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds a sanitised context array for AI prompts.
 *
 * IMPORTANT: Do not include escalation->notes (internal).
 * Mask emails/phones to reduce PII leakage.
 */
final class EscalationAiContextBuilder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Builds a sanitised context array for the given escalation.
   *
   * @param int $escalation_id
   *   The escalation entity ID.
   *
   * @return array
   *   Context array safe to JSON encode and send to the AI provider.
   */
  public function build(int $escalation_id): array {
    $storage = $this->entityTypeManager->getStorage('escalation');
    /** @var \Drupal\myeventlane_escalations\Entity\Escalation|null $escalation */
    $escalation = $storage->load($escalation_id);

    if (!$escalation) {
      $this->logger->warning('AI context build: escalation not found id={id}', ['id' => $escalation_id]);
      return [
        'error' => 'escalation_not_found',
        'escalation_id' => $escalation_id,
      ];
    }

    $subject = (string) $escalation->get('subject')->value;
    $description = (string) $escalation->get('description')->value;

    $context = [
      'escalation_id' => (int) $escalation->id(),
      'subject' => $this->sanitizeText($subject),
      'description' => $this->sanitizeText($description),
      'type' => (string) $escalation->get('type')->value,
      'status' => (string) $escalation->get('status')->value,
      'priority' => (string) $escalation->get('priority')->value,
      'created' => (int) $escalation->get('created')->value,
      'changed' => (int) $escalation->get('changed')->value,
      'linked' => [
        'vendor_id' => $escalation->get('vendor_id')->isEmpty() ? NULL : (int) $escalation->get('vendor_id')->target_id,
        'event_id' => $escalation->get('event_id')->isEmpty() ? NULL : (int) $escalation->get('event_id')->target_id,
        'order_id' => $escalation->get('order_id')->isEmpty() ? NULL : (int) $escalation->get('order_id')->target_id,
      ],
      // No internal notes. No user email. No addresses.
      'viewer_uid' => (int) $this->currentUser->id(),
    ];

    return $context;
  }

  /**
   * Builds a sanitised context with the public comment thread included.
   *
   * Use this for tasks that need conversation history (e.g. breach_soon).
   * Only public messages from field_escalation_thread are included.
   * Internal notes are NEVER included.
   *
   * @param int $escalation_id
   *   The escalation entity ID.
   *
   * @return array
   *   Context array with a 'thread' key containing sanitised messages.
   */
  public function buildWithThread(int $escalation_id): array {
    $context = $this->build($escalation_id);

    if (isset($context['error'])) {
      return $context;
    }

    $context['thread'] = $this->loadSanitizedThread($escalation_id);

    return $context;
  }

  /**
   * Loads and sanitises the public comment thread for an escalation.
   *
   * @param int $escalation_id
   *   The escalation entity ID.
   *
   * @return array
   *   Array of sanitised message objects.
   */
  private function loadSanitizedThread(int $escalation_id): array {
    try {
      $comment_storage = $this->entityTypeManager->getStorage('comment');
    }
    catch (\Throwable) {
      // Comment module not installed or entity type missing.
      return [];
    }

    $cids = $comment_storage->getQuery()
      ->condition('entity_type', 'escalation')
      ->condition('entity_id', $escalation_id)
      ->condition('field_name', 'field_escalation_thread')
      ->sort('created', 'ASC')
      ->range(0, 20)
      ->accessCheck(FALSE)
      ->execute();

    if (empty($cids)) {
      return [];
    }

    $comments = $comment_storage->loadMultiple($cids);
    $messages = [];

    foreach ($comments as $comment) {
      $author = $comment->getOwner();
      $author_name = $author ? $author->getDisplayName() : 'Unknown';
      $body = (string) ($comment->get('comment_body')->value ?? '');

      $messages[] = [
        'author' => $this->sanitizeText((string) $author_name),
        'body' => $this->sanitizeText($body),
        'created' => (int) $comment->getCreatedTime(),
      ];
    }

    return $messages;
  }

  /**
   * Sanitises text by masking emails and phone numbers.
   *
   * @param string $text
   *   Raw text from the escalation.
   *
   * @return string
   *   Text with PII redacted.
   */
  private function sanitizeText(string $text): string {
    $text = trim($text);

    // Mask emails: keep first char of local part + domain.
    $text = preg_replace_callback('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', static function (array $m): string {
      $email = $m[0];
      $parts = explode('@', $email, 2);
      if (count($parts) !== 2) {
        return '[redacted-email]';
      }
      $local = $parts[0];
      $domain = $parts[1];
      $local_mask = mb_substr($local, 0, 1) . '***';
      return $local_mask . '@' . $domain;
    }, $text) ?? $text;

    // Mask phone-ish sequences (basic pattern: 8+ digit sequences).
    $text = preg_replace('/(\+?\d[\d\s().\-]{7,}\d)/', '[redacted-phone]', $text) ?? $text;

    return $text;
  }

}
