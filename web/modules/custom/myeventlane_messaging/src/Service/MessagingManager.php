<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Service;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\myeventlane_messaging\Service\Delivery\DeliveryProviderManager;
use Psr\Log\LoggerInterface;

/**
 * Queues and sends transactional messages. Single entry point; idempotent.
 */
final class MessagingManager {

  /**
   * The queue name used for message dispatch.
   */
  private const QUEUE_NAME = 'myeventlane_messaging';

  /**
   * Templates that are always sent (transactional, not subject to opt-out).
   *
   * @var string[]
   */
  private const TRANSACTIONAL_TEMPLATES = [
    'assign_tickets_buyer',
    'order_receipt',
    'vendor_event_cancellation',
    'vendor_event_important_change',
    'vendor_event_update',
    'refund_requested_buyer',
    'refund_requested_vendor',
    'refund_approved_buyer',
    'refund_approved_vendor',
    'refund_rejected_buyer',
    'refund_rejected_vendor',
    'refund_completed_buyer',
    'refund_completed_vendor',
  ];

  /**
   * Templates that respect operational_reminder_opt_out.
   *
   * @var string[]
   */
  private const OPERATIONAL_TEMPLATES = [
    'event_reminder',
    'event_reminder_24h',
    'event_reminder_7d',
    'cart_abandoned',
    'boost_reminder',
  ];

  /**
   * Constructs a MessagingManager.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Language\LanguageManagerInterface $lang
   *   The language manager.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\myeventlane_messaging\Service\MessageRenderer $messageRenderer
   *   The message renderer.
   * @param \Drupal\myeventlane_messaging\Service\MessageStorage $messageStorage
   *   The message storage.
   * @param \Drupal\myeventlane_messaging\Service\MessagePreferenceStorage $preferenceStorage
   *   The preference storage.
   * @param \Drupal\myeventlane_messaging\Service\Delivery\DeliveryProviderManager $deliveryProviderManager
   *   The delivery provider manager.
   * @param \Drupal\myeventlane_messaging\Service\UtmLinker|null $utmLinker
   *   Optional UTM linker (may be NULL if not yet in container).
   * @param \Drupal\myeventlane_messaging\Service\VendorBrandResolver|null $vendorBrandResolver
   *   Optional vendor brand resolver (may be NULL if not yet in container).
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LanguageManagerInterface $lang,
    private readonly QueueFactory $queueFactory,
    private readonly LoggerInterface $logger,
    private readonly MessageRenderer $messageRenderer,
    private readonly MessageStorage $messageStorage,
    private readonly MessagePreferenceStorage $preferenceStorage,
    private readonly DeliveryProviderManager $deliveryProviderManager,
    private readonly ?UtmLinker $utmLinker = NULL,
    private readonly ?VendorBrandResolver $vendorBrandResolver = NULL,
  ) {}

  /**
   * Adds a message to the canonical queue (idempotent).
   *
   * Builds context_hash, skips if an existing message with same hash+recipient+template
   * is queued or sent, creates a message record, enqueues only message_id.
   *
   * @param string $type
   *   The template key.
   * @param string $to
   *   The recipient email.
   * @param array $context
   *   The template context (must be serializable; no objects for hashing).
   * @param array $opts
   *   Optional: langcode, attachments, scheduled_for.
   *
   * @return string|null
   *   The message ID (UUID) if queued, NULL if skipped or failed. Caller may pass
   *   this to sendMessage() for immediate send (e.g. refund notifications).
   */
  public function queue(string $type, string $to, array $context = [], array $opts = []): ?string {
    $eventId = isset($context['event_id']) && is_numeric($context['event_id']) ? (int) $context['event_id'] : NULL;
    $orderId = isset($context['order_id']) && is_numeric($context['order_id']) ? (int) $context['order_id'] : NULL;
    $submissionId = isset($context['submission_id']) && is_numeric($context['submission_id']) ? (int) $context['submission_id'] : NULL;

    $to = trim($to);
    if ($to === '') {
      $this->logger->warning('MessagingManager::queue: empty recipient skipped.', [
        'queue_name' => self::QUEUE_NAME,
        'event_id' => $eventId,
        'order_id' => $orderId,
        'message_type' => $type,
      ]);
      return NULL;
    }

    $contextHash = $this->contextHash($type, $to, $context);
    $existing = $this->messageStorage->findByContextHash($contextHash, $to, $type, ['queued', 'sent']);
    if ($existing) {
      $this->logger->info('MessagingManager::queue: duplicate skipped (idempotent).', [
        'queue_name' => self::QUEUE_NAME,
        'event_id' => $eventId,
        'order_id' => $orderId,
        'message_type' => $type,
        'existing_id' => $existing->id,
      ]);
      return NULL;
    }

    $now = (int) time();
    $langcode = $opts['langcode'] ?? $this->lang->getDefaultLanguage()->getId();
    $scheduledFor = (int) ($opts['scheduled_for'] ?? $now);

    // Normalize context for storage: ensure scalar/array only for serialization.
    $storableContext = $this->normalizeContextForStorage($context);
    $storableContext['_attachments'] = $opts['attachments'] ?? [];
    $id = $this->uuid();

    try {
      $this->messageStorage->create([
        'id' => $id,
        'template' => $type,
        'channel' => 'email',
        'recipient' => $to,
        'langcode' => $langcode,
        'context' => $storableContext,
        'context_hash' => $contextHash,
        'scheduled_for' => $scheduledFor,
        'status' => 'queued',
        'attempts' => 0,
        'created' => $now,
        'sent' => 0,
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to create message record. @message', [
        '@message' => $e->getMessage(),
        'queue_name' => self::QUEUE_NAME,
        'event_id' => $eventId,
        'order_id' => $orderId,
        'message_type' => $type,
      ]);
      return NULL;
    }

    try {
      $this->queueFactory->get(self::QUEUE_NAME)->createItem(['message_id' => $id]);
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to queue message id. @message', [
        '@message' => $e->getMessage(),
        'queue_name' => self::QUEUE_NAME,
        'event_id' => $eventId,
        'order_id' => $orderId,
        'message_type' => $type,
        'message_id' => $id,
      ]);
      return NULL;
    }

    return $id;
  }

  /**
   * Sends a message by ID (called by queue worker). Idempotent and preference-aware.
   *
   * @param string $messageId
   *   UUID from queue payload.
   */
  public function sendMessage(string $messageId): void {
    $message = $this->messageStorage->load($messageId);
    if (!$message) {
      $this->logger->error('Message not found; skipping. message_id=@id', [
        '@id' => $messageId,
        'queue_name' => self::QUEUE_NAME,
      ]);
      return;
    }

    if ($message->status === 'sent') {
      $this->logger->info('Message already sent (idempotent skip). message_id=@id', [
        '@id' => $messageId,
        'queue_name' => self::QUEUE_NAME,
      ]);
      return;
    }

    if ($message->status === 'suppressed') {
      $this->logger->info('Message suppressed; skipping. message_id=@id', [
        '@id' => $messageId,
        'queue_name' => self::QUEUE_NAME,
      ]);
      return;
    }

    $type = $message->template;
    $to = $message->recipient;
    $ctx = $message->context;
    $opts = [
      'langcode' => $message->langcode,
      'attachments' => $ctx['_attachments'] ?? [],
    ];
    unset($ctx['_attachments']);

    $eventId = isset($ctx['event_id']) && is_numeric($ctx['event_id']) ? (int) $ctx['event_id'] : NULL;
    $orderId = isset($ctx['order_id']) && is_numeric($ctx['order_id']) ? (int) $ctx['order_id'] : NULL;

    // Template enabled.
    $conf = $this->configFactory->get("myeventlane_messaging.template.{$type}");
    if (!$conf || !$conf->get('enabled')) {
      $this->logger->notice('Template @type disabled or missing; suppressing.', [
        '@type' => $type,
        'queue_name' => self::QUEUE_NAME,
        'message_id' => $messageId,
      ]);
      $this->messageStorage->update($messageId, ['status' => 'suppressed']);
      return;
    }

    // Preference rules: transactional forced-on; operational/marketing respect opt-out.
    if (!$this->allowByPreference($type, $to, $ctx)) {
      $this->logger->info('Message suppressed by preference. message_id=@id template=@type', [
        '@id' => $messageId,
        '@type' => $type,
        'queue_name' => self::QUEUE_NAME,
      ]);
      $this->messageStorage->update($messageId, ['status' => 'suppressed']);
      return;
    }

    // Render subject/body (token safety).
    $subjectTpl = (string) ($conf->get('subject') ?? '');
    $subjectRaw = $this->messageRenderer->renderString($subjectTpl, $ctx);
    if (self::containsTwigSyntax($subjectRaw)) {
      $this->logger->error('Message subject contains unresolved tokens; skipping.', [
        'queue_name' => self::QUEUE_NAME,
        'message_id' => $messageId,
        'message_type' => $type,
      ]);
      $this->messageStorage->update($messageId, ['status' => 'failed']);
      return;
    }
    $subject = Html::decodeEntities(strip_tags($subjectRaw));
    $subject = Unicode::truncate($subject, 150, TRUE, TRUE);

    // Inject brand into context before body render.
    $brand = $this->vendorBrandResolver?->resolve($ctx) ?? [];
    $ctx += $brand;

    $body = $this->messageRenderer->renderHtmlBody($conf, $ctx);
    if (self::containsTwigSyntax($body)) {
      $this->logger->error('Message body contains unresolved tokens; skipping.', [
        'queue_name' => self::QUEUE_NAME,
        'message_id' => $messageId,
        'message_type' => $type,
      ]);
      $this->messageStorage->update($messageId, ['status' => 'failed']);
      return;
    }

    $utmParams = [];
    if ($conf->get('utm.enable') && is_array($conf->get('utm.params'))) {
      $utmParams = $conf->get('utm.params');
    }
    if ($utmParams && $this->utmLinker) {
      $body = $this->utmLinker->apply($body, $utmParams);
    }

    $provider = $this->deliveryProviderManager->getProvider(NULL, $ctx);
    $params = [
      'to' => $to,
      'subject' => $subject,
      'body' => $body,
      'html' => $body,
      'langcode' => $message->langcode,
      'attachments' => $opts['attachments'] ?? [],
    ];
    if (!empty($brand['from_name'])) {
      $params['from_name'] = $brand['from_name'];
    }
    if (!empty($brand['from_email'])) {
      $params['from_email'] = $brand['from_email'];
    }
    if (!empty($brand['reply_to'])) {
      $params['reply_to'] = $brand['reply_to'];
    }

    $sent = $provider->send($params);

    $this->messageStorage->incrementAttempts($messageId);
    if ($sent) {
      $this->messageStorage->update($messageId, [
        'status' => 'sent',
        'sent' => (int) time(),
      ]);
      $this->logger->info('Sent message @type to @to. message_id=@id', [
        '@type' => $type,
        '@to' => $to,
        '@id' => $messageId,
        'queue_name' => self::QUEUE_NAME,
        'event_id' => $eventId,
        'order_id' => $orderId,
      ]);
    }
    else {
      $this->messageStorage->update($messageId, ['status' => 'failed']);
      $this->logger->error('Failed sending message @type to @to. message_id=@id', [
        '@type' => $type,
        '@to' => $to,
        '@id' => $messageId,
        'queue_name' => self::QUEUE_NAME,
        'event_id' => $eventId,
        'order_id' => $orderId,
      ]);
    }
  }

  /**
   * Sends a prepared message payload immediately (legacy: queue worker sends by ID).
   *
   * If the payload contains 'message_id', delegates to sendMessage. Otherwise
   * treats as legacy payload and creates a message record then sends by ID once.
   * New code must only enqueue message_id.
   *
   * @param array $payload
   *   Either ['message_id' => uuid] or legacy ['type','to','context','opts'].
   */
  public function sendNow(array $payload): void {
    if (isset($payload['message_id']) && is_string($payload['message_id'])) {
      $this->sendMessage($payload['message_id']);
      return;
    }
    // Legacy: no message_id. Create message and send once (no re-queue).
    $type = $payload['type'] ?? 'generic';
    $to = $payload['to'] ?? '';
    $context = $payload['context'] ?? [];
    $opts = $payload['opts'] ?? [];
    if ($to === '') {
      $this->logger->warning('sendNow: empty recipient.', [
        'queue_name' => self::QUEUE_NAME,
        'message_type' => $type,
      ]);
      return;
    }
    $contextHash = $this->contextHash($type, $to, $context);
    $existing = $this->messageStorage->findByContextHash($contextHash, $to, $type, ['queued', 'sent']);
    if ($existing) {
      $this->sendMessage($existing->id);
      return;
    }
    $now = (int) time();
    $langcode = $opts['langcode'] ?? $this->lang->getDefaultLanguage()->getId();
    $storableContext = $this->normalizeContextForStorage($context);
    $id = $this->uuid();
    try {
      $this->messageStorage->create([
        'id' => $id,
        'template' => $type,
        'channel' => 'email',
        'recipient' => $to,
        'langcode' => $langcode,
        'context' => $storableContext,
        'context_hash' => $contextHash,
        'scheduled_for' => $now,
        'status' => 'queued',
        'attempts' => 0,
        'created' => $now,
        'sent' => 0,
      ]);
      $this->sendMessage($id);
    }
    catch (\Throwable $e) {
      $this->logger->error('sendNow legacy: failed to create message. @message', [
        '@message' => $e->getMessage(),
        'queue_name' => self::QUEUE_NAME,
        'message_type' => $type,
      ]);
    }
  }

  /**
   * Deterministic context hash for idempotency.
   */
  private function contextHash(string $type, string $to, array $context): string {
    $storable = $this->normalizeContextForStorage($context);
    $key = $type . '|' . $to . '|' . json_encode($storable, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return hash('sha256', $key);
  }

  /**
   * Normalizes context for storage/hashing (scalars and arrays only).
   */
  private function normalizeContextForStorage(array $context): array {
    $out = [];
    foreach ($context as $k => $v) {
      if ($k === '_attachments') {
        $out[$k] = $v;
        continue;
      }
      if (is_scalar($v) || $v === NULL) {
        $out[$k] = $v;
        continue;
      }
      if (is_array($v)) {
        $out[$k] = $this->normalizeContextForStorage($v);
        continue;
      }
      // Skip objects for storage/hash.
    }
    return $out;
  }

  /**
   * Whether sending is allowed by recipient preferences.
   */
  private function allowByPreference(string $template, string $recipient, array $context): bool {
    if (in_array($template, self::TRANSACTIONAL_TEMPLATES, TRUE)) {
      return TRUE;
    }
    $prefs = $this->preferenceStorage->get($recipient, 'email');
    if (in_array($template, self::OPERATIONAL_TEMPLATES, TRUE)) {
      return !$prefs['operational_reminder_opt_out'];
    }
    return !$prefs['marketing_opt_out'];
  }

  private static function containsTwigSyntax(string $rendered): bool {
    return str_contains($rendered, '{{') || str_contains($rendered, '{%') || str_contains($rendered, '{#');
  }

  private function uuid(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
  }

}
