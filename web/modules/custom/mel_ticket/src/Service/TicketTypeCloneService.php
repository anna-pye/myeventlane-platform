<?php

declare(strict_types=1);

namespace Drupal\mel_ticket\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\mel_ticket\Entity\TicketTypeInterface;
use Drupal\node\NodeInterface;
use InvalidArgumentException;

/**
 * Clones reusable ticket templates into event-scoped ticket rows.
 *
 * Reuse in the wizard attaches a <em>new</em> ticket entity so templates stay
 * immutable and paid Commerce variations remain per-event (paid templates are
 * disallowed — see TicketType::assertBusinessRules).
 */
final class TicketTypeCloneService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Creates a non-reusable copy of a template for the given event.
   *
   * @param \Drupal\mel_ticket\Entity\TicketTypeInterface $template
   *   A reusable ticket owned by the same account as $account.
   * @param \Drupal\node\NodeInterface $event
   *   The event node (bundle event).
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The acting user; must own the template.
   *
   * @return \Drupal\mel_ticket\Entity\TicketTypeInterface
   *   Saved clone with template_source set.
   *
   * @throws \InvalidArgumentException
   *   If the template cannot be cloned (wrong kind, not reusable, etc.).
   */
  public function createFromTemplate(
    TicketTypeInterface $template,
    NodeInterface $event,
    AccountInterface $account,
  ): TicketTypeInterface {
    if ($event->bundle() !== 'event') {
      throw new InvalidArgumentException('Clone target must be an event node.');
    }
    if (!$template->isReusable()) {
      throw new InvalidArgumentException('Only reusable tickets can be used as templates.');
    }
    if ((int) $template->get('vendor_id')->target_id !== (int) $account->id()) {
      throw new InvalidArgumentException('Templates must be owned by the current user.');
    }
    $kind = $template->getTicketKind();
    if ($kind === 'paid') {
      throw new InvalidArgumentException('Paid ticket templates are not supported; create paid tiers per event.');
    }
    if (!in_array($kind, ['rsvp', 'external'], TRUE)) {
      throw new InvalidArgumentException('Unsupported ticket kind for template clone.');
    }

    $storage = $this->entityTypeManager->getStorage('mel_ticket_type');
    $values = [
      'title' => $template->getTitle(),
      'ticket_kind' => $kind,
      'vendor_id' => $template->get('vendor_id')->getValue(),
      'is_reusable' => FALSE,
      'event' => ['target_id' => $event->id()],
      'status' => 1,
    ];

    if (!$template->get('capacity')->isEmpty()) {
      $values['capacity'] = (int) $template->get('capacity')->value;
    }
    if (!$template->get('rsvp_limit')->isEmpty()) {
      $values['rsvp_limit'] = (int) $template->get('rsvp_limit')->value;
    }
    if (!$template->get('sale_start')->isEmpty()) {
      $values['sale_start'] = $template->get('sale_start')->getValue();
    }
    if (!$template->get('sale_end')->isEmpty()) {
      $values['sale_end'] = $template->get('sale_end')->getValue();
    }
    if (!$template->get('external_url')->isEmpty()) {
      $values['external_url'] = $template->get('external_url')->getValue();
    }

    // Instance row: never carry Commerce links from the template.
    $values['price'] = NULL;
    $values['commerce_variation'] = NULL;
    $values['template_source'] = ['target_id' => (int) $template->id()];

    /** @var \Drupal\mel_ticket\Entity\TicketTypeInterface $clone */
    $clone = $storage->create($values);
    $clone->save();

    return $clone;
  }
}