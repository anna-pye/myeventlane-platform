<?php

declare(strict_types=1);

namespace Drupal\myeventlane_donations\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\myeventlane_donations\Service\VendorAutoBillingService;
use Drupal\myeventlane_donations\Service\VendorContributionInvoiceService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for donation module operations.
 */
final class DonationCommands extends DrushCommands {

  /**
   * Constructs the commands.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly VendorContributionInvoiceService $vendorContributionInvoiceService,
    private readonly VendorAutoBillingService $vendorAutoBillingService,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('myeventlane_donations.vendor_contribution_invoice'),
      $container->get('myeventlane_donations.vendor_auto_billing'),
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

  /**
   * Generate MEL vendor contribution invoices from unbilled accrual rows.
   */
  #[CLI\Command(name: 'mel:vendor-mel-invoices-generate', aliases: ['mel-mel-inv-gen'])]
  #[CLI\Option(name: 'vendor', description: 'Optional vendor entity ID filter')]
  #[CLI\Option(name: 'since', description: 'Unix timestamp start (default: 30 days ago)')]
  #[CLI\Option(name: 'until', description: 'Unix timestamp end (default: now)')]
  #[CLI\Usage(name: 'drush mel:vendor-mel-invoices-generate', description: 'Group unbilled rows into invoices')]
  public function generateMelVendorInvoices(array $options = ['vendor' => NULL, 'since' => NULL, 'until' => NULL]): int {
    $this->io()->title('Generate MEL vendor contribution invoices');

    $since = isset($options['since']) && is_numeric($options['since']) ? (int) $options['since'] : strtotime('-30 days midnight');
    $until = isset($options['until']) && is_numeric($options['until']) ? (int) $options['until'] : time();
    $vendor = NULL;
    if (isset($options['vendor']) && $options['vendor'] !== NULL && $options['vendor'] !== '' && is_numeric($options['vendor'])) {
      $v = (int) $options['vendor'];
      $vendor = $v > 0 ? $v : NULL;
    }

    if ($until < $since) {
      $this->io()->error('until must be >= since.');
      return DrushCommands::EXIT_FAILURE;
    }

    try {
      $created = $this->vendorContributionInvoiceService->generateInvoicesForWindow($vendor, $since, $until);
    }
    catch (\Throwable $e) {
      $this->io()->error($e->getMessage());
      return DrushCommands::EXIT_FAILURE;
    }

    if ($created === []) {
      $this->io()->warning('No invoices created.');
      return DrushCommands::EXIT_SUCCESS;
    }

    foreach ($created as $row) {
      $this->io()->writeln(sprintf(
        'Invoice #%d vendor=%d total=%s %s rows=%d',
        (int) ($row['invoice_id'] ?? 0),
        (int) ($row['vendor_id'] ?? 0),
        number_format(((int) ($row['total_amount_cents'] ?? 0)) / 100, 2),
        strtoupper((string) ($row['currency_code'] ?? '')),
        count($row['contribution_row_ids'] ?? [])
      ));
    }

    $autoResult = $this->vendorAutoBillingService->processAutoBillingForInvoices($created);
    if ($autoResult['attempted'] > 0) {
      $this->io()->writeln(sprintf('Auto-billing: attempted=%d succeeded=%d failed=%d', $autoResult['attempted'], $autoResult['succeeded'], $autoResult['failed']));
    }

    $this->io()->success(sprintf('Created %d invoice(s).', count($created)));
    return DrushCommands::EXIT_SUCCESS;
  }

}
