<?php

declare(strict_types=1);

namespace Drupal\mel_ticket\Plugin\EntityReferenceSelection;

use Drupal\Core\Entity\Attribute\EntityReferenceSelection;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Limits autocomplete to reusable ticket setups owned by the active user.
 */
#[EntityReferenceSelection(
  id: 'mel_ticket:reusable',
  label: new TranslatableMarkup('Reusable ticket setups (current organiser)'),
  entity_types: ['mel_ticket_type'],
  group: 'mel_ticket',
  weight: 0,
)]
final class ReusableTicketSelection extends DefaultSelection {

  /**
   * {@inheritdoc}
   */
  protected function buildEntityQuery($match = NULL, $match_operator = 'CONTAINS'): QueryInterface {
    $query = parent::buildEntityQuery($match, $match_operator);
    $query->condition('is_reusable', 1);
    $query->condition('vendor_id', $this->currentUser->id());
    return $query;
  }

}
