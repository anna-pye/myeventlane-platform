<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Component\Utility\Tags;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves ticket product + ticket type entity autocomplete strings for Event Studio.
 *
 * Matches products/types the vendor already owns — does not create entities.
 */
final class EventStudioTicketLinkSuggestionService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * @param list<string> $tierTitles
   *   Plain-text tier titles from the inline builder.
   *
   * @return array{product: ?string, ticket_types: ?string}
   *   Values suitable for entity_autocomplete text fields, or NULL when none.
   */
  public function suggest(AccountInterface $account, string $eventTitle, array $tierTitles, ?int $nid): array {
    $out = ['product' => NULL, 'ticket_types' => NULL];
    $uid = (int) $account->id();
    if ($uid === 0) {
      return $out;
    }

    $eventTitle = trim($eventTitle);
    $seen = [];
    $normalizedTiers = [];
    foreach ($tierTitles as $t) {
      $t = trim((string) $t);
      if ($t === '' || isset($seen[$t])) {
        continue;
      }
      $seen[$t] = TRUE;
      $normalizedTiers[] = $t;
    }

    try {
      $product = $this->findTicketProduct($account, $eventTitle, $nid);
      if ($product instanceof ProductInterface) {
        $out['product'] = $this->formatEntityAutocomplete($product);
      }

      $ticketEntities = $this->findTicketTypesForTiers($account, $normalizedTiers);
      if ($ticketEntities !== []) {
        $tags = [];
        foreach ($ticketEntities as $entity) {
          $tags[] = $this->formatEntityAutocomplete($entity);
        }
        $out['ticket_types'] = Tags::implode($tags);
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Event Studio ticket link suggestions failed: @msg', [
        '@msg' => $e->getMessage(),
      ]);
    }

    return $out;
  }

  private function formatEntityAutocomplete(object $entity): string {
    return $entity->label() . ' (' . $entity->id() . ')';
  }

  private function findTicketProduct(AccountInterface $account, string $eventTitle, ?int $nid): ?ProductInterface {
    $storage = $this->entityTypeManager->getStorage('commerce_product');

    if ($nid !== NULL && $nid > 0) {
      $ids = $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'ticket')
        ->condition('field_event', $nid)
        ->range(0, 1)
        ->execute();
      if ($ids) {
        $id = (int) reset($ids);
        $p = $storage->load($id);
        return $p instanceof ProductInterface ? $p : NULL;
      }
    }

    if ($eventTitle === '') {
      return NULL;
    }

    $owner = (int) $account->id();

    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'ticket')
      ->condition('uid', $owner)
      ->condition('title', $eventTitle)
      ->range(0, 1)
      ->execute();
    if ($ids) {
      $id = (int) reset($ids);
      $p = $storage->load($id);
      return $p instanceof ProductInterface ? $p : NULL;
    }

    $like = '%' . $this->database->escapeLike($eventTitle) . '%';
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'ticket')
      ->condition('uid', $owner)
      ->condition('title', $like, 'LIKE')
      ->range(0, 1)
      ->execute();
    if ($ids) {
      $id = (int) reset($ids);
      $p = $storage->load($id);
      return $p instanceof ProductInterface ? $p : NULL;
    }

    return NULL;
  }

  /**
   * @param list<string> $tierTitles
   *
   * @return list<TicketTypeInterface>
   */
  private function findTicketTypesForTiers(AccountInterface $account, array $tierTitles): array {
    if ($tierTitles === []) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('mel_ticket_type');
    $vendor = (int) $account->id();
    $out = [];
    $seenIds = [];

    foreach ($tierTitles as $title) {
      $ids = $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('vendor_id', $vendor)
        ->condition('ticket_kind', 'paid')
        ->condition('title', $title)
        ->range(0, 1)
        ->execute();

      if (!$ids) {
        $ids = $this->loadMelTicketTypeIdsCaseInsensitive($vendor, $title);
      }

      if ($ids) {
        $id = (int) reset($ids);
        if (isset($seenIds[$id])) {
          continue;
        }
        $e = $storage->load($id);
        if ($e instanceof TicketTypeInterface) {
          $seenIds[$id] = TRUE;
          $out[] = $e;
        }
      }
    }

    return $out;
  }

  /**
   * Fallback when stored title casing differs from the inline builder.
   *
   * @return list<int|string>
   */
  private function loadMelTicketTypeIdsCaseInsensitive(int $vendorId, string $title): array {
    $storage = $this->entityTypeManager->getStorage('mel_ticket_type');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('vendor_id', $vendorId)
      ->condition('ticket_kind', 'paid')
      ->range(0, 500)
      ->execute();

    if ($ids === []) {
      return [];
    }

    $lower = mb_strtolower($title);
    foreach (array_values($ids) as $id) {
      $e = $storage->load($id);
      if (!$e instanceof TicketTypeInterface) {
        continue;
      }
      if (mb_strtolower($e->getTitle()) === $lower) {
        return [$id];
      }
    }

    return [];
  }

}
