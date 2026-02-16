<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\field\Entity\FieldConfig;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for donation module operations.
 */
final class DonationCommands extends DrushCommands {

  /**
   * Constructs the commands.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Attaches field_target_event to rsvp_donation order items.
   *
   * Use this if the update hook did not run or the field is missing.
   * Required for RSVP donations to appear in vendor dashboard stats.
   */
  #[CLI\Command(name: 'mel:attach-rsvp-donation-field', aliases: ['mel-rsvp-field'])]
  #[CLI\Usage(name: 'drush mel:attach-rsvp-donation-field', description: 'Attach field_target_event to rsvp_donation bundle')]
  public function attachRsvpDonationField(): int {
    $this->io()->title('Attach field_target_event to rsvp_donation');

    $fieldConfigStorage = $this->entityTypeManager->getStorage('field_config');
    $existing = $fieldConfigStorage->load('commerce_order_item.rsvp_donation.field_target_event');

    if ($existing) {
      $this->io()->success('Field field_target_event is already attached to rsvp_donation.');
      return DrushCommands::EXIT_SUCCESS;
    }

    // Ensure field storage exists.
    $fieldStorage = $this->entityTypeManager->getStorage('field_storage_config')
      ->load('commerce_order_item.field_target_event');

    if (!$fieldStorage) {
      $this->io()->error('Field storage commerce_order_item.field_target_event does not exist. Ensure default/boost order item types have it, or run full config import.');
      return DrushCommands::EXIT_FAILURE;
    }

    try {
      FieldConfig::create([
        'field_name' => 'field_target_event',
        'entity_type' => 'commerce_order_item',
        'bundle' => 'rsvp_donation',
        'label' => 'Target Event',
        'description' => 'The event this RSVP donation is associated with.',
        'required' => TRUE,
        'translatable' => FALSE,
        'settings' => [
          'handler' => 'default:node',
          'handler_settings' => [
            'target_bundles' => ['event' => 'event'],
            'sort' => ['field' => 'title', 'direction' => 'asc'],
            'auto_create' => FALSE,
          ],
        ],
      ])->save();

      drupal_flush_all_caches();

      $this->io()->success('Field field_target_event attached to rsvp_donation. Cache cleared.');
      return DrushCommands::EXIT_SUCCESS;
    }
    catch (\Throwable $e) {
      $this->io()->error('Failed to attach field: ' . $e->getMessage());
      return DrushCommands::EXIT_FAILURE;
    }
  }

  /**
   * Diagnose RSVP donation field status.
   */
  #[CLI\Command(name: 'mel:diagnose-rsvp-donations', aliases: ['mel-rsvp-diagnose'])]
  #[CLI\Usage(name: 'drush mel:diagnose-rsvp-donations', description: 'Check field_target_event status for rsvp_donation')]
  public function diagnoseRsvpDonations(): int {
    $this->io()->title('RSVP Donation Field Diagnostic');

    $fieldConfig = $this->entityTypeManager->getStorage('field_config')
      ->load('commerce_order_item.rsvp_donation.field_target_event');
    $this->io()->writeln('Field config attached: ' . ($fieldConfig ? 'YES' : 'NO'));

    $ids = $this->entityTypeManager->getStorage('commerce_order_item')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'rsvp_donation')
      ->range(0, 5)
      ->execute();

    if (empty($ids)) {
      $this->io()->writeln('No rsvp_donation order items in database.');
      return DrushCommands::EXIT_SUCCESS;
    }

    $items = $this->entityTypeManager->getStorage('commerce_order_item')->loadMultiple($ids);
    foreach ($items as $item) {
      $hasField = $item->hasField('field_target_event');
      $isEmpty = $hasField ? $item->get('field_target_event')->isEmpty() : 'N/A';
      $eventId = $hasField && !$isEmpty ? $item->get('field_target_event')->target_id : '-';
      $this->io()->writeln(sprintf('  Item %d: hasField=%s isEmpty=%s event=%s', $item->id(), $hasField ? 'yes' : 'no', $isEmpty === 'N/A' ? 'N/A' : ($isEmpty ? 'yes' : 'no'), $eventId));
    }

    $total = $this->entityTypeManager->getStorage('commerce_order_item')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'rsvp_donation')
      ->count()
      ->execute();
    $this->io()->writeln("\nTotal rsvp_donation items: " . $total);

    return DrushCommands::EXIT_SUCCESS;
  }

}
