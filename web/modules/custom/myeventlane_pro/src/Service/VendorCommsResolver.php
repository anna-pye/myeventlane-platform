<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Utility\Token;
use Drupal\Component\Utility\Xss;
use Psr\Log\LoggerInterface;

/**
 * Resolves Pro vendor communication body overrides.
 */
final class VendorCommsResolver {

  /**
   * Allowed HTML tags in vendor body content.
   *
   * @var string[]
   */
  private const ALLOWED_TAGS = ['p', 'strong', 'em', 'br', 'ul', 'li', 'a'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Token $token,
    private readonly LoggerInterface $logger,
    private readonly ProActiveResolver $proActiveResolver,
  ) {}

  /**
   * Returns custom body override for template/store, or NULL if not applicable.
   */
  public function resolveBody(StoreInterface $store, string $template_id, array $context): ?string {
    if (!$this->proActiveResolver->isStoreProActive($store)) {
      return NULL;
    }

    $field = $this->mapTemplateToField($template_id);
    if ($field === NULL) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('myeventlane_pro_vendor_comms');
    $items = $storage->loadByProperties(['store_id' => (int) $store->id()]);
    $entity = $items === [] ? NULL : reset($items);
    if (!$entity instanceof \Drupal\myeventlane_pro\Entity\ProVendorComms) {
      return NULL;
    }

    if (!(bool) $entity->get('enabled')) {
      return NULL;
    }

    $raw = trim((string) $entity->get($field));
    if ($raw === '') {
      return NULL;
    }

    try {
      $tokenData = [
        'event' => [
          'title' => (string) ($context['event_title'] ?? ''),
          'date' => (string) ($context['event_date'] ?? $context['event_start'] ?? ''),
          'location' => (string) ($context['event_location'] ?? $context['venue'] ?? ''),
        ],
        'customer' => [
          'first_name' => (string) ($context['first_name'] ?? $context['name'] ?? 'there'),
        ],
        'order' => [
          'total' => (string) ($context['order_total'] ?? $context['total_paid'] ?? ''),
        ],
        'ticket' => [
          'type' => (string) ($context['ticket_type'] ?? ''),
        ],
      ];

      $rendered = $this->token->replace($raw, $tokenData, ['clear' => TRUE]);
      $safe = Xss::filter($rendered, self::ALLOWED_TAGS);
      return trim($safe) . $this->buildMandatoryFooter($store);
    }
    catch (\Throwable $exception) {
      $this->logger->error('Failed to resolve vendor comms override for store @store and template @template: @message', [
        '@store' => (int) $store->id(),
        '@template' => $template_id,
        '@message' => $exception->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Maps message template id to ProVendorComms field.
   */
  private function mapTemplateToField(string $templateId): ?string {
    return match ($templateId) {
      'rsvp_confirmation' => 'rsvp_body',
      'order_confirmation' => 'ticket_body',
      'order_invoice' => 'ticket_body',
      'order_receipt' => 'ticket_body',
      'event_reminder_24h', 'event_reminder_2h' => 'reminder_body',
      'pro_cart_abandoned_w1', 'pro_cart_abandoned_w2' => 'abandoned_cart_body',
      default => NULL,
    };
  }

  /**
   * Builds mandatory platform legal footer.
   */
  private function buildMandatoryFooter(StoreInterface $store): string {
    $signature = '';
    $storage = $this->entityTypeManager->getStorage('myeventlane_pro_vendor_comms');
    $items = $storage->loadByProperties(['store_id' => (int) $store->id()]);
    $entity = $items === [] ? NULL : reset($items);
    if ($entity instanceof \Drupal\myeventlane_pro\Entity\ProVendorComms) {
      $signature = trim((string) $entity->get('brand_signature'));
    }

    $footer = '<p>You are receiving this message because of your activity on MyEventLane.</p>';
    if ($signature !== '') {
      $footer = '<p>' . Xss::filter($signature, self::ALLOWED_TAGS) . '</p>' . $footer;
    }
    return '<hr />' . $footer;
  }

}
