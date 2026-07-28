<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Service;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\RequeueException;
use Drupal\myeventlane_messaging\Service\Delivery\DeliveryProviderManager;
use Drupal\myeventlane_pro\Service\VendorCommsResolver;
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
   * Maximum provider delivery attempts before a terminal failure.
   */
  private const MAX_DELIVERY_ATTEMPTS = 5;

  /**
   * Seconds before an abandoned worker claim is considered stale.
   */
  private const DELIVERY_CLAIM_TTL = 900;

  /**
   * Templates that are always sent (transactional, not subject to opt-out).
   *
   * @var string[]
   */
  private const TRANSACTIONAL_TEMPLATES = [
    // @deprecated assign_tickets_buyer: no queue() callers; buyer flow uses order_confirmation + ticket assignment.
    'assign_tickets_buyer',
    'boost_confirmation',
    'order_confirmation',
    'order_invoice',
    'rsvp_confirmation',
    'rsvp_vendor_copy',
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
    'refund_completed_admin',
    'refund_failed_buyer',
    'refund_failed_vendor',
    'refund_failed_admin',
    'ticket_tier_waitlist_offer',
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
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\myeventlane_messaging\Service\UtmLinker|null $utmLinker
   *   Optional UTM linker (may be NULL if not yet in container).
   * @param \Drupal\myeventlane_messaging\Service\BrandResolverInterface|null $vendorBrandResolver
   *   Optional vendor brand resolver (may be NULL if not yet in container).
   * @param \Drupal\myeventlane_pro\Service\VendorCommsResolver|null $vendorCommsResolver
   *   Optional vendor comms resolver.
   * @param \Drupal\myeventlane_messaging\Service\OrderConfirmationAttachmentResolver|null $orderConfirmationAttachmentResolver
   *   Optional resolver for order confirmation PDF attachments at send time.
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
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ?UtmLinker $utmLinker = NULL,
    private readonly ?BrandResolverInterface $vendorBrandResolver = NULL,
    private readonly ?VendorCommsResolver $vendorCommsResolver = NULL,
    private readonly ?OrderConfirmationAttachmentResolver $orderConfirmationAttachmentResolver = NULL,
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
   *   Optional: langcode, attachments, scheduled_for, channel, and
   *   idempotency_key.
   *
   * @return string|null
   *   The message ID (UUID) if queued, NULL if skipped or failed. Caller may pass
   *   this to sendMessage() for immediate send (e.g. refund notifications).
   */
  public function queue(string $type, string $to, array $context = [], array $opts = []): ?string {
    $eventId = isset($context['event_id']) && is_numeric($context['event_id']) ? (int) $context['event_id'] : NULL;
    $orderId = isset($context['order_id']) && is_numeric($context['order_id']) ? (int) $context['order_id'] : NULL;
    $to = strtolower(trim($to));
    if ($to === '') {
      $this->logger->warning('MessagingManager::queue: empty recipient skipped.', [
        'queue_name' => self::QUEUE_NAME,
        'event_id' => $eventId,
        'order_id' => $orderId,
        'message_type' => $type,
      ]);
      return NULL;
    }

    // Choose A/B variant once at queue time (deterministic).
    $conf = $this->configFactory->get("myeventlane_messaging.template.{$type}");
    if ($conf && $conf->get('ab_test.enable')) {
      $context['_ab_variant'] = $this->pickAbVariant($conf, $type, $to);
    }

    $contextHash = $this->contextHash($type, $to, $context);
    $idempotencyKey = trim((string) ($opts['idempotency_key'] ?? ''));
    if ($idempotencyKey === '') {
      $idempotencyKey = sprintf('message:%s:%s', $type, $contextHash);
    }
    if (strlen($idempotencyKey) > 255) {
      $idempotencyKey = 'sha256:' . hash('sha256', $idempotencyKey);
    }

    $existing = $this->messageStorage->findByIdempotencyKey($idempotencyKey);
    if ($existing) {
      if ($existing->status === 'failed'
        && (int) $existing->attempts < self::MAX_DELIVERY_ATTEMPTS) {
        $this->queueFactory->get(self::QUEUE_NAME)->createItem([
          'message_id' => $existing->id,
        ]);
        $this->logger->notice('MessagingManager::queue: retryable failed message requeued.', [
          'queue_name' => self::QUEUE_NAME,
          'message_type' => $type,
          'existing_id' => $existing->id,
        ]);
        return $existing->id;
      }
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
    $channel = (string) ($opts['channel'] ?? 'email');
    if ($channel !== 'email' && $channel !== 'sms') {
      $channel = 'email';
    }

    // Normalize context for storage: ensure scalar/array only for serialization.
    $storableContext = $this->normalizeContextForStorage($context);
    if (class_exists(\Drupal\myeventlane_notifications\NotificationTaxonomy::class)) {
      $classification = \Drupal\myeventlane_notifications\NotificationTaxonomy::emailTemplateClassification($type);
      $storableContext['_mel_notification_context'] = $classification['context'];
      $storableContext['_mel_notification_domain'] = $classification['domain'];
      $storableContext['_mel_notification_priority'] = $classification['priority'];
    }
    $storableContext['_attachments'] = $opts['attachments'] ?? [];
    $id = $this->uuid();

    try {
      $this->messageStorage->create([
        'id' => $id,
        'template' => $type,
        'channel' => $channel,
        'recipient' => $to,
        'langcode' => $langcode,
        'context' => $storableContext,
        'context_hash' => $contextHash,
        'idempotency_key' => $idempotencyKey,
        'scheduled_for' => $scheduledFor,
        'status' => 'queued',
        'attempts' => 0,
        'created' => $now,
        'sent' => 0,
      ]);
    }
    catch (\Throwable $e) {
      // A concurrent producer may have won the unique-key insert race.
      $existing = $this->messageStorage->findByIdempotencyKey($idempotencyKey);
      if ($existing !== NULL) {
        $this->logger->info('MessagingManager::queue: concurrent duplicate skipped (idempotent).', [
          'queue_name' => self::QUEUE_NAME,
          'message_type' => $type,
          'existing_id' => $existing->id,
          'idempotency_key' => $idempotencyKey,
        ]);
        return NULL;
      }
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
    $now = time();
    $staleBefore = $now - self::DELIVERY_CLAIM_TTL;
    if ($message->status === 'processing') {
      if ((int) $message->claimed_at <= 0
        || (int) $message->claimed_at > $staleBefore
        || !$this->messageStorage->reclaimStaleProcessing(
          $messageId,
          $staleBefore,
          $now,
        )) {
        $this->logger->info('Message is already claimed by another worker. message_id=@id', [
          '@id' => $messageId,
          'queue_name' => self::QUEUE_NAME,
        ]);
        return;
      }
      $this->logger->warning('Reclaimed stale pre-dispatch message after worker interruption. message_id=@id', [
        '@id' => $messageId,
        'queue_name' => self::QUEUE_NAME,
      ]);
    }
    elseif ($message->status === 'dispatching') {
      if ((int) $message->claimed_at > 0
        && (int) $message->claimed_at <= $staleBefore
        && $this->messageStorage->markStaleDispatchUnknown(
          $messageId,
          $staleBefore,
        )) {
        $this->logger->critical('Stale provider dispatch quarantined without retry to prevent a duplicate. message_id=@id', [
          '@id' => $messageId,
          'queue_name' => self::QUEUE_NAME,
        ]);
      }
      else {
        $this->logger->info('Message provider dispatch is already in progress. message_id=@id', [
          '@id' => $messageId,
          'queue_name' => self::QUEUE_NAME,
        ]);
      }
      return;
    }
    elseif ($message->status === 'delivery_unknown') {
      $this->logger->warning('Message delivery remains unknown and requires provider reconciliation. message_id=@id', [
        '@id' => $messageId,
        'queue_name' => self::QUEUE_NAME,
      ]);
      return;
    }
    if ($message->status === 'failed_permanent'
      || (int) $message->attempts >= self::MAX_DELIVERY_ATTEMPTS) {
      $this->messageStorage->update($messageId, [
        'status' => 'failed_permanent',
        'claimed_at' => 0,
      ]);
      $this->logger->error('Message reached the maximum delivery attempts. message_id=@id attempts=@attempts', [
        '@id' => $messageId,
        '@attempts' => (int) $message->attempts,
        'queue_name' => self::QUEUE_NAME,
      ]);
      return;
    }
    if ($message->status !== 'processing'
      && !$this->messageStorage->claimForDelivery($messageId, $now)) {
      $this->logger->info('Message delivery claim was not acquired. message_id=@id', [
        '@id' => $messageId,
        'queue_name' => self::QUEUE_NAME,
      ]);
      return;
    }

    $type = $message->template;
    $to = $message->recipient;
    $ctx = is_array($message->context ?? NULL) ? $message->context : [];

    $queuedAttachments = $ctx['_attachments'] ?? [];

    if ($this->orderConfirmationAttachmentResolver) {
      $attachmentsForSend = $this->orderConfirmationAttachmentResolver
        ->mergeOrderConfirmationAttachments($type, $ctx, $queuedAttachments);
    }
    else {
      $attachmentsForSend = $queuedAttachments;
    }

    $opts = [
      'langcode' => $message->langcode,
      'attachments' => $attachmentsForSend,
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
      $this->messageStorage->update($messageId, [
        'status' => 'suppressed',
        'claimed_at' => 0,
      ]);
      return;
    }

    // Preference rules: transactional forced-on; operational/marketing respect opt-out.
    if (!$this->allowByPreference($type, $to, $ctx)) {
      $this->logger->info('Message suppressed by preference. message_id=@id template=@type', [
        '@id' => $messageId,
        '@type' => $type,
        'queue_name' => self::QUEUE_NAME,
      ]);
      $this->messageStorage->update($messageId, [
        'status' => 'suppressed',
        'claimed_at' => 0,
      ]);
      return;
    }

    // SMS channel: render SMS text but do not send; mark suppressed.
    if ($message->channel === 'sms') {
      $smsText = $this->messageRenderer->renderSmsText($conf, $ctx);
      $smsLength = strlen($smsText);
      // Safety guard: truncate to 320 chars max with warning.
      if ($smsLength > 320) {
        $smsText = Unicode::truncate($smsText, 320, TRUE, TRUE);
        $this->logger->warning('SMS content truncated to 320 chars for message @id. Original length @original.', [
          '@id' => $messageId,
          '@original' => $smsLength,
          'queue_name' => self::QUEUE_NAME,
        ]);
      }
      $this->logger->warning('SMS channel not yet delivered; message suppressed. message_id=@id template=@type sms_length=@len', [
        '@id' => $messageId,
        '@type' => $type,
        '@len' => strlen($smsText),
        'queue_name' => self::QUEUE_NAME,
      ]);
      $this->messageStorage->update($messageId, [
        'status' => 'suppressed',
        'claimed_at' => 0,
      ]);
      return;
    }

    // A/B overrides at send time (subject, preheader, body_html, sms_text, marketing only).
    $variant = isset($ctx['_ab_variant']) && ($ctx['_ab_variant'] === 'A' || $ctx['_ab_variant'] === 'B')
      ? $ctx['_ab_variant']
      : NULL;
    $variantData = [];
    if ($variant && $conf->get('ab_test.enable')) {
      $variantData = is_array($conf->get("ab_test.variants.{$variant}")) ? $conf->get("ab_test.variants.{$variant}") : [];
    }
    $subjectTpl = (string) (($variantData['subject'] ?? '') !== '' ? $variantData['subject'] : ($conf->get('subject') ?? ''));
    $preheaderTpl = (string) (($variantData['preheader'] ?? '') !== '' ? $variantData['preheader'] : ($conf->get('preheader') ?? ''));
    $bodyHtmlOverride = (($variantData['body_html'] ?? '') !== '') ? $variantData['body_html'] : NULL;

    // Inject brand into context before preheader/body render.
    $brandObject = $this->vendorBrandResolver?->resolve($ctx);
    $brand = $brandObject ? $brandObject->toArray() : [
      'from_name' => 'MyEventLane',
      'from_email' => '',
      'reply_to' => '',
      'footer_text' => "You're receiving this because you interacted with MyEventLane.",
      'accent_color' => '#f26d5b',
      'logo_url' => '',
      'marketing' => [],
    ];
    if (!empty($variantData['marketing']) && is_array($variantData['marketing'])) {
      $brand['marketing'] = array_merge($brand['marketing'] ?? [], $variantData['marketing']);
    }
    $ctx += $brand;

    // Preheader: render and inject into context for wrapper.
    $preheaderRaw = $preheaderTpl !== ''
      ? $this->messageRenderer->renderString($preheaderTpl, $ctx)
      : '';
    $ctx['preheader'] = Unicode::truncate(
      trim(strip_tags(Html::decodeEntities($preheaderRaw))),
      200,
      TRUE,
      TRUE
    );

    // Render subject (token safety).
    $subjectRaw = $this->messageRenderer->renderString($subjectTpl, $ctx);
    if (self::containsTwigSyntax($subjectRaw)) {
      $this->logger->error('Message subject contains unresolved tokens; skipping.', [
        'queue_name' => self::QUEUE_NAME,
        'message_id' => $messageId,
        'message_type' => $type,
      ]);
      $this->messageStorage->update($messageId, [
        'status' => 'failed',
        'claimed_at' => 0,
      ]);
      return;
    }
    $subject = Html::decodeEntities(strip_tags($subjectRaw));
    $subject = Unicode::truncate($subject, 150, TRUE, TRUE);

    // Guardrail: empty subject after A/B + overrides → abort.
    if (trim($subject) === '') {
      $this->logger->error('Message subject is empty after rendering; aborting send. message_id=@id template=@type', [
        '@id' => $messageId,
        '@type' => $type,
        'queue_name' => self::QUEUE_NAME,
      ]);
      $this->messageStorage->update($messageId, [
        'status' => 'failed',
        'claimed_at' => 0,
      ]);
      return;
    }

    // Guardrail: from_email is required for delivery.
    if (empty($brand['from_email'])) {
      $this->logger->error('Brand from_email is empty; aborting send. message_id=@id template=@type', [
        '@id' => $messageId,
        '@type' => $type,
        'queue_name' => self::QUEUE_NAME,
      ]);
      $this->messageStorage->update($messageId, [
        'status' => 'failed',
        'claimed_at' => 0,
      ]);
      return;
    }

    $effectiveBodyOverride = $bodyHtmlOverride;
    if ($this->vendorCommsResolver instanceof VendorCommsResolver) {
      $store = $this->resolveStoreFromContext($ctx);
      if ($store instanceof \Drupal\commerce_store\Entity\StoreInterface) {
        $vendorOverride = $this->vendorCommsResolver->resolveBody($store, $type, $ctx);
        if (is_string($vendorOverride) && $vendorOverride !== '') {
          $effectiveBodyOverride = $vendorOverride;
        }
      }
    }

    $body = $this->messageRenderer->renderHtmlBody($conf, $ctx, $effectiveBodyOverride);
    if (self::containsTwigSyntax($body)) {
      $this->logger->error('Message body contains unresolved tokens; skipping.', [
        'queue_name' => self::QUEUE_NAME,
        'message_id' => $messageId,
        'message_type' => $type,
      ]);
      $this->messageStorage->update($messageId, [
        'status' => 'failed',
        'claimed_at' => 0,
      ]);
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
      'mel_template_key' => $type,
      'mel_message_id' => $messageId,
    ];
    if ($orderId !== NULL) {
      $params['mel_order_id'] = $orderId;
    }
    if (!empty($brand['from_name'])) {
      $params['from_name'] = $brand['from_name'];
    }
    if (!empty($brand['from_email'])) {
      $params['from_email'] = $brand['from_email'];
    }
    if (!empty($brand['reply_to'])) {
      $params['reply_to'] = $brand['reply_to'];
    }

    if (!$this->messageStorage->markDispatching($messageId, time())) {
      $this->logger->error('Message lost its delivery claim before provider dispatch. message_id=@id', [
        '@id' => $messageId,
        'queue_name' => self::QUEUE_NAME,
      ]);
      return;
    }

    try {
      $sent = $provider->send($params);
    }
    catch (\Throwable $e) {
      $this->messageStorage->incrementAttempts($messageId);
      $this->messageStorage->update($messageId, [
        'status' => 'delivery_unknown',
        'claimed_at' => 0,
        'provider' => $provider->id(),
      ]);
      $this->logger->critical('Provider dispatch outcome is unknown; automatic retry suppressed. message_id=@id provider=@provider @message', [
        '@id' => $messageId,
        '@provider' => $provider->id(),
        '@message' => $e->getMessage(),
        'queue_name' => self::QUEUE_NAME,
      ]);
      return;
    }

    $this->messageStorage->incrementAttempts($messageId);
    if ($sent) {
      $updates = [
        'status' => 'sent',
        'sent' => (int) time(),
        'claimed_at' => 0,
        'provider' => $provider->id(),
      ];
      $providerMessageId = $provider->getLastProviderMessageId();
      if ($providerMessageId !== NULL && $providerMessageId !== '') {
        $updates['provider_message_id'] = $providerMessageId;
      }
      $this->messageStorage->update($messageId, $updates);
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
      $this->messageStorage->update($messageId, [
        'status' => 'failed',
        'claimed_at' => 0,
      ]);
      $this->logger->error('Failed sending message @type to @to. message_id=@id', [
        '@type' => $type,
        '@to' => $to,
        '@id' => $messageId,
        'queue_name' => self::QUEUE_NAME,
        'event_id' => $eventId,
        'order_id' => $orderId,
      ]);
      throw new RequeueException(sprintf('Delivery failed for message %s.', $messageId));
    }
  }

  /**
   * Releases a worker claim when delivery throws unexpectedly.
   */
  public function releaseFailedClaim(string $messageId): void {
    if ($this->messageStorage->releaseFailedClaim($messageId)) {
      $this->logger->warning('Released failed message delivery claim for retry. message_id=@id', [
        '@id' => $messageId,
        'queue_name' => self::QUEUE_NAME,
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
    $to = strtolower(trim((string) ($payload['to'] ?? '')));
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
    $idempotencyKey = trim((string) ($opts['idempotency_key'] ?? ''));
    if ($idempotencyKey === '') {
      $idempotencyKey = sprintf('message:%s:%s', $type, $contextHash);
    }
    if (strlen($idempotencyKey) > 255) {
      $idempotencyKey = 'sha256:' . hash('sha256', $idempotencyKey);
    }
    $existing = $this->messageStorage->findByIdempotencyKey($idempotencyKey);
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
        'idempotency_key' => $idempotencyKey,
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

  /**
   * Picks A/B variant deterministically at queue time (same recipient+type = same variant).
   */
  private function pickAbVariant(Config $conf, string $type, string $to): string {
    $weight = (int) ($conf->get('ab_test.variants.A.weight') ?? 50);
    // Use abs() because crc32() returns a signed integer that can be negative.
    $bucket = abs(crc32(strtolower($to . '|' . $type))) % 100;
    return ($bucket < $weight) ? 'A' : 'B';
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

  /**
   * Resolves store from known context keys.
   */
  private function resolveStoreFromContext(array $context): ?\Drupal\commerce_store\Entity\StoreInterface {
    if (!empty($context['store_id']) && is_numeric($context['store_id'])) {
      $store = $this->entityTypeManager->getStorage('commerce_store')->load((int) $context['store_id']);
      if ($store instanceof \Drupal\commerce_store\Entity\StoreInterface) {
        return $store;
      }
    }

    if (!empty($context['order_id']) && is_numeric($context['order_id'])) {
      $order = $this->entityTypeManager->getStorage('commerce_order')->load((int) $context['order_id']);
      if ($order instanceof \Drupal\commerce_order\Entity\OrderInterface) {
        $store = $order->getStore();
        if ($store instanceof \Drupal\commerce_store\Entity\StoreInterface) {
          return $store;
        }
      }
    }

    if (!empty($context['event_id']) && is_numeric($context['event_id'])) {
      $event = $this->entityTypeManager->getStorage('node')->load((int) $context['event_id']);
      if ($event instanceof \Drupal\node\NodeInterface && $event->hasField('field_event_store') && !$event->get('field_event_store')->isEmpty()) {
        $store = $event->get('field_event_store')->entity;
        if ($store instanceof \Drupal\commerce_store\Entity\StoreInterface) {
          return $store;
        }
      }
    }

    return NULL;
  }

}
