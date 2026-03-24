<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event\Controller;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_checkout_paragraph\Service\CheckInTokenService;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Door Mode check-in: QR validation and name search with JSON endpoints.
 */
final class CheckinController extends ControllerBase {

  private const CSRF_ID = 'mel_door_checkin';

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    AccountProxyInterface $currentUser,
    private readonly Connection $database,
    private readonly LoggerInterface $logger,
    private readonly CsrfTokenGenerator $csrfToken,
    private readonly ?CheckInTokenService $checkInToken,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('database'),
      $container->get('logger.factory')->get('myeventlane_event'),
      $container->get('csrf_token'),
      $container->has('myeventlane_checkout_paragraph.checkin_token')
        ? $container->get('myeventlane_checkout_paragraph.checkin_token')
        : NULL,
    );
  }

  /**
   * Access callback for door check-in routes.
   */
  public function checkAccess(?NodeInterface $event = NULL): AccessResult {
    $account = $this->currentUser();

    if ($account->hasPermission('administer commerce_order') || $account->hasPermission('bypass node access')) {
      return AccessResult::allowed()->addCacheContexts(['user.permissions']);
    }

    if ($event instanceof NodeInterface) {
      $store = $this->getStoreForUser($account);
      if ($store && $this->vendorOwnsEvent($store, $event)) {
        return AccessResult::allowed()->addCacheContexts(['user']);
      }
      return AccessResult::forbidden()->addCacheContexts(['user']);
    }

    $store = $this->getStoreForUser($account);
    if ($store) {
      return AccessResult::allowed()->addCacheContexts(['user']);
    }

    return AccessResult::forbidden()->addCacheContexts(['user']);
  }

  /**
   * Door Mode page (mobile-friendly, no full attendee list).
   */
  public function doorMode(NodeInterface $event): array {
    $eventId = (int) $event->id();
    $validateUrl = $this->buildValidateUrl($eventId);
    $searchUrl = $this->buildSearchUrl($eventId);

    return [
      '#theme' => 'mel_checkin_page',
      '#event' => $event,
      '#event_id' => $eventId,
      '#validate_url' => $validateUrl,
      '#search_url' => $searchUrl,
      '#csrf_token' => $this->csrfToken->get(self::CSRF_ID),
      '#attached' => [
        'library' => ['myeventlane_event/door_checkin'],
        'drupalSettings' => [
          'melDoorCheckin' => [
            'validateUrl' => $validateUrl,
            'searchUrl' => $searchUrl,
            'csrfToken' => $this->csrfToken->get(self::CSRF_ID),
            'eventId' => $eventId,
          ],
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node:' . $eventId],
      ],
    ];
  }

  /**
   * Validates QR token or performs name search / check-in (JSON).
   */
  public function validate(NodeInterface $event, Request $request): JsonResponse {
    $eventId = (int) $event->id();

    if ($request->isMethod(Request::METHOD_GET)) {
      $q = trim((string) $request->query->get('q', ''));
      if ($q === '') {
        return new JsonResponse(['status' => 'error', 'message' => 'Missing search query.'], 400);
      }
      try {
        $candidates = $this->searchAttendeeParagraphs($eventId, $q, 25);
      }
      catch (\Throwable $e) {
        $this->logger->error('Check-in search failed for event @eid: @msg', [
          '@eid' => (string) $eventId,
          '@msg' => $e->getMessage(),
        ]);
        return new JsonResponse(['status' => 'error', 'message' => 'Search failed.'], 500);
      }
      return new JsonResponse([
        'status' => 'ok',
        'candidates' => $candidates,
      ]);
    }

    $token = $request->headers->get('X-CSRF-Token', '');
    if (!$this->csrfToken->validate($token, self::CSRF_ID)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Invalid CSRF token.'], 403);
    }

    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Invalid JSON body.'], 400);
    }

    $paragraphId = isset($payload['paragraph_id']) ? (int) $payload['paragraph_id'] : 0;
    $code = isset($payload['code']) ? trim((string) $payload['code']) : '';

    try {
      if ($paragraphId > 0) {
        return $this->jsonCheckInParagraph($eventId, $paragraphId);
      }

      if ($code === '') {
        return new JsonResponse(['status' => 'error', 'message' => 'Missing code or paragraph_id.'], 400);
      }

      // QR / signed token path.
      if ($this->checkInToken !== NULL && $this->looksLikeQrToken($code)) {
        $decoded = $this->checkInToken->validateToken($code);
        if (!$decoded || empty($decoded['valid'])) {
          return new JsonResponse(['status' => 'error', 'message' => 'Invalid or expired ticket code.'], 400);
        }
        return $this->jsonCheckInParagraph($eventId, (int) $decoded['paragraph_id']);
      }

      // Name / email search: single match checks in; multiple returns pick list.
      $matches = $this->searchAttendeeParagraphs($eventId, $code, 10);
      if ($matches === []) {
        return new JsonResponse(['status' => 'error', 'message' => 'No matching attendee for this event.'], 404);
      }
      if (count($matches) > 1) {
        return new JsonResponse([
          'status' => 'multiple',
          'message' => 'Multiple matches — pick an attendee.',
          'candidates' => $matches,
        ]);
      }
      return $this->jsonCheckInParagraph($eventId, (int) $matches[0]['paragraph_id']);
    }
    catch (\Throwable $e) {
      $this->logger->error('Check-in validate failed for event @eid: @msg', [
        '@eid' => (string) $eventId,
        '@msg' => $e->getMessage(),
      ]);
      return new JsonResponse(['status' => 'error', 'message' => 'Check-in failed.'], 500);
    }
  }

  /**
   * Checks in an attendee paragraph after ownership checks.
   */
  private function jsonCheckInParagraph(int $eventId, int $paragraphId): JsonResponse {
    if (!$this->paragraphBelongsToCompletedEventOrder($paragraphId, $eventId)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Attendee not found for this event.'], 404);
    }

    $storage = $this->entityTypeManager()->getStorage('paragraph');
    $paragraph = $storage->load($paragraphId);
    if (!$paragraph instanceof ParagraphInterface || $paragraph->bundle() !== 'attendee_answer') {
      return new JsonResponse(['status' => 'error', 'message' => 'Invalid attendee record.'], 400);
    }

    $accessHandler = $this->entityTypeManager()->getAccessControlHandler('paragraph');
    if (!$accessHandler->access($paragraph, 'update', $this->currentUser())) {
      return new JsonResponse(['status' => 'error', 'message' => 'Access denied.'], 403);
    }

    $already = $paragraph->hasField('field_checked_in') && !$paragraph->get('field_checked_in')->isEmpty()
      ? (bool) $paragraph->get('field_checked_in')->value
      : FALSE;

    if ($already) {
      $this->logger->notice('Duplicate check-in attempt for paragraph @pid at event @eid by user @uid.', [
        '@pid' => (string) $paragraphId,
        '@eid' => (string) $eventId,
        '@uid' => (string) $this->currentUser()->id(),
      ]);
      return new JsonResponse([
        'status' => 'duplicate',
        'message' => 'Already checked in.',
        'checked_in' => TRUE,
      ]);
    }

    if ($paragraph->hasField('field_checked_in')) {
      $paragraph->set('field_checked_in', 1);
    }
    if ($paragraph->hasField('field_checked_in_timestamp')) {
      $paragraph->set('field_checked_in_timestamp', time());
    }
    if ($paragraph->hasField('field_checked_in_by')) {
      $paragraph->set('field_checked_in_by', $this->currentUser()->id());
    }
    $paragraph->save();

    $this->logger->notice('Check-in success paragraph @pid event @eid by user @uid.', [
      '@pid' => (string) $paragraphId,
      '@eid' => (string) $eventId,
      '@uid' => (string) $this->currentUser()->id(),
    ]);

    return new JsonResponse([
      'status' => 'success',
      'message' => 'Checked in',
      'checked_in' => TRUE,
      'paragraph_id' => $paragraphId,
    ]);
  }

  /**
   * Fast attendee search for an event (no full entity graph).
   *
   * @return array<int, array{paragraph_id: int, name: string, email: string}>
   */
  private function searchAttendeeParagraphs(int $eventId, string $needle, int $limit): array {
    $schema = $this->database->schema();
    if (
      !$schema->tableExists('commerce_order_item__field_ticket_holder') ||
      !$schema->tableExists('commerce_order_item__field_target_event') ||
      !$schema->tableExists('paragraph_field_data')
    ) {
      return [];
    }

    $like = '%' . $this->database->escapeLike($needle) . '%';

    $query = $this->database->select('commerce_order_item__field_ticket_holder', 'th');
    $query->join('commerce_order_item', 'oi', 'oi.order_item_id = th.entity_id');
    $query->join('commerce_order', 'o', 'o.order_id = oi.order_id');
    $query->join('commerce_order_item__field_target_event', 'te', 'te.entity_id = oi.order_item_id');
    $query->join('paragraph_field_data', 'p', 'p.id = th.field_ticket_holder_target_id');
    $query->leftJoin('paragraph__field_first_name', 'fn', 'fn.entity_id = p.id AND fn.bundle = :bundle', [':bundle' => 'attendee_answer']);
    $query->leftJoin('paragraph__field_last_name', 'ln', 'ln.entity_id = p.id AND ln.bundle = :bundle2', [':bundle2' => 'attendee_answer']);
    $query->leftJoin('paragraph__field_email', 'em', 'em.entity_id = p.id AND em.bundle = :bundle3', [':bundle3' => 'attendee_answer']);
    $query->addField('th', 'field_ticket_holder_target_id', 'paragraph_id');
    $query->addExpression("TRIM(CONCAT(COALESCE(fn.field_first_name_value, ''), ' ', COALESCE(ln.field_last_name_value, '')))", 'full_name');
    $query->addField('em', 'field_email_value', 'email');
    $query->condition('o.state', 'completed');
    $query->condition('te.field_target_event_target_id', $eventId);
    $query->condition('p.type', 'attendee_answer');
    $query->condition('p.status', 1);
    $query->where("(fn.field_first_name_value LIKE :like OR ln.field_last_name_value LIKE :like OR em.field_email_value LIKE :like OR CONCAT(COALESCE(fn.field_first_name_value, ''), ' ', COALESCE(ln.field_last_name_value, '')) LIKE :like)", [
      ':like' => $like,
    ]);

    $query->range(0, $limit);

    $out = [];
    foreach ($query->execute() as $row) {
      $pid = (int) ($row->paragraph_id ?? 0);
      if ($pid <= 0) {
        continue;
      }
      $name = trim((string) ($row->full_name ?? ''));
      $email = (string) ($row->email ?? '');
      $out[] = [
        'paragraph_id' => $pid,
        'name' => $name,
        'email' => $email,
      ];
    }

    return $out;
  }

  /**
   * Verifies paragraph is a ticket holder on a completed order for the event.
   */
  private function paragraphBelongsToCompletedEventOrder(int $paragraphId, int $eventId): bool {
    $schema = $this->database->schema();
    if (
      !$schema->tableExists('commerce_order_item__field_ticket_holder') ||
      !$schema->tableExists('commerce_order_item__field_target_event')
    ) {
      return FALSE;
    }

    $query = $this->database->select('commerce_order_item__field_ticket_holder', 'th');
    $query->join('commerce_order_item', 'oi', 'oi.order_item_id = th.entity_id');
    $query->join('commerce_order', 'o', 'o.order_id = oi.order_id');
    $query->join('commerce_order_item__field_target_event', 'te', 'te.entity_id = oi.order_item_id');
    $query->addExpression('1');
    $query->condition('th.field_ticket_holder_target_id', $paragraphId);
    $query->condition('o.state', 'completed');
    $query->condition('te.field_target_event_target_id', $eventId);
    $query->range(0, 1);

    return (bool) $query->execute()->fetchField();
  }

  private function looksLikeQrToken(string $code): bool {
    return strlen($code) >= 32 && base64_decode($code, TRUE) !== FALSE;
  }

  private function buildValidateUrl(int $eventId): string {
    return Url::fromRoute(
      'myeventlane_event.checkin_validate',
      ['event' => $eventId],
      ['absolute' => TRUE],
    )->toString();
  }

  private function buildSearchUrl(int $eventId): string {
    return Url::fromRoute(
      'myeventlane_event.checkin_validate',
      ['event' => $eventId],
      ['absolute' => TRUE],
    )->toString();
  }

  /**
   * Resolves store for current vendor user (same logic as VendorOwnershipResolver).
   */
  private function getStoreForUser(AccountInterface $account): ?StoreInterface {
    if ($this->moduleHandler()->moduleExists('myeventlane_vendor')) {
      $vendor_storage = $this->entityTypeManager()->getStorage('myeventlane_vendor');
      $vendors = $vendor_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_owner', $account->id())
        ->range(0, 1)
        ->execute();

      if (!empty($vendors)) {
        $vendor = $vendor_storage->load(reset($vendors));
        if ($vendor && $vendor->hasField('field_vendor_store') && !$vendor->get('field_vendor_store')->isEmpty()) {
          $store = $vendor->get('field_vendor_store')->entity;
          if ($store instanceof StoreInterface) {
            return $store;
          }
        }
      }
    }

    $store_storage = $this->entityTypeManager()->getStorage('commerce_store');
    $store_ids = $store_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $account->id())
      ->range(0, 1)
      ->execute();

    if (!empty($store_ids)) {
      $store = $store_storage->load(reset($store_ids));
      if ($store instanceof StoreInterface) {
        return $store;
      }
    }

    return NULL;
  }

  /**
   * Whether the vendor store owns the event node.
   */
  private function vendorOwnsEvent(StoreInterface $store, NodeInterface $event): bool {
    if (!$event->hasField('field_event_vendor') || $event->get('field_event_vendor')->isEmpty()) {
      return (int) $event->getOwnerId() === (int) $store->getOwnerId();
    }

    $event_vendor = $event->get('field_event_vendor')->entity;
    if (!$event_vendor) {
      return FALSE;
    }

    if ($event_vendor->hasField('field_vendor_store') && !$event_vendor->get('field_vendor_store')->isEmpty()) {
      $vendor_store = $event_vendor->get('field_vendor_store')->entity;
      if ($vendor_store && (int) $vendor_store->id() === (int) $store->id()) {
        return TRUE;
      }
    }

    return (int) $event_vendor->getOwnerId() === (int) $store->getOwnerId();
  }

}
