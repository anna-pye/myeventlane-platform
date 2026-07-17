<?php

declare(strict_types=1);

namespace Drupal\myeventlane_wallet\Service;

use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileSystemInterface;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Service\UniversalTicketViewModelBuilder;
use JsonException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ZipArchive;

/**
 * Builds signed Apple Wallet .pkpass archives.
 *
 * Issued tickets drive pass content through UniversalTicketViewModelBuilder
 * (QR payloads originate from TicketQrPayload inside that builder). Signing is
 * owned exclusively by WalletSigner — do not change signing from this class.
 */
final class PkPassBuilder {

  /**
   * Canonical MEL organisation display name when config is empty.
   */
  private const DEFAULT_ORGANISATION_NAME = 'MyEventLane';

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly UniversalTicketViewModelBuilder $ticketViewModelBuilder,
    private readonly WalletSigner $walletSigner,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly LoggerInterface $logger,
    private readonly WalletEventPresentation $walletEventPresentation,
  ) {}

  /**
   * Generates a .pkpass file path for the given order item route.
   *
   * @param \Drupal\myeventlane_tickets\Entity\Ticket|null $ticket
   *   Issued entitlement row when present; NULL preserves legacy empty placeholder.
   *
   * @return string
   *   Path to the generated .pkpass file.
   *
   * @throws \RuntimeException
   *   When an issued ticket is present but pass generation or signing fails.
   */
  public function generate(OrderItemInterface $orderItem, ?Ticket $ticket = NULL): string {
    $tempDir = $this->fileSystem->getTempDirectory();
    $passPath = $tempDir . '/ticket_' . $orderItem->id() . '_' . uniqid('', TRUE) . '.pkpass';

    if (!$ticket instanceof Ticket) {
      // Legacy compatibility: empty artifact until issuance exists for the line.
      file_put_contents($passPath, '');
      return $passPath;
    }

    $model = $this->ticketViewModelBuilder->build($ticket);
    $passJson = $this->buildPassJson($orderItem, $ticket, $model);

    $workDir = $tempDir . '/pkpass_' . $orderItem->id() . '_' . uniqid('', TRUE);
    if (!$this->fileSystem->mkdir($workDir)) {
      throw new RuntimeException('Unable to create Apple Wallet work directory.');
    }

    try {
      $this->writePassBundle($workDir, $passJson);
      $this->writeManifest($workDir);
      $signature = $this->walletSigner->sign($workDir . '/manifest.json');
      if (file_put_contents($workDir . '/signature', $signature) === FALSE) {
        throw new RuntimeException('Unable to write Apple Wallet signature.');
      }
      $this->zipBundle($workDir, $passPath);
    }
    catch (\Throwable $e) {
      $this->logger->error('Apple Wallet pass generation failed for order item @id: @message', [
        '@id' => (string) $orderItem->id(),
        '@message' => $e->getMessage(),
      ]);
      throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
    }
    finally {
      $this->fileSystem->deleteRecursive($workDir);
    }

    return $passPath;
  }

  /**
   * Builds pass.json content from the universal ticket view model.
   *
   * @param array<string, mixed> $model
   *   Universal ticket view model.
   *
   * @return string
   *   JSON document.
   */
  private function buildPassJson(OrderItemInterface $orderItem, Ticket $ticket, array $model): string {
    $config = $this->configFactory->get('myeventlane_wallet.settings');
    $team_id = trim((string) $config->get('apple_team_id'));
    $pass_type_id = trim((string) $config->get('apple_pass_type_id'));
    $org = $this->organisationName();
    if ($team_id === '' || $pass_type_id === '') {
      throw new RuntimeException('Apple Wallet team ID and pass type ID are required.');
    }

    $ticket_code = (string) ($model['ticket']['code'] ?? '');
    $qr_payload = (string) ($model['qr']['payload'] ?? '');
    if ($ticket_code === '' || $qr_payload === '') {
      throw new RuntimeException('Apple Wallet pass requires ticket code and QR payload.');
    }

    $presentation = $this->walletEventPresentation->build($ticket, $model, $org);
    $event_label = $presentation['event_label'];
    $serial = $this->resolveSerialNumber($orderItem, $ticket, $ticket_code);

    // Cohesive MEL branding: organisationName + logoText always match platform
    // config (never per-organiser). Description stays event-scoped for clarity.
    $pass = [
      'formatVersion' => 1,
      'passTypeIdentifier' => $pass_type_id,
      'serialNumber' => $serial,
      'teamIdentifier' => $team_id,
      'organizationName' => $org,
      'description' => $event_label,
      'logoText' => $org,
      'foregroundColor' => 'rgb(41, 50, 65)',
      'backgroundColor' => 'rgb(255, 247, 238)',
      'labelColor' => 'rgb(107, 70, 255)',
      'barcode' => [
        'format' => 'PKBarcodeFormatQR',
        'message' => $qr_payload,
        'messageEncoding' => 'iso-8859-1',
      ],
      'barcodes' => [
        [
          'format' => 'PKBarcodeFormatQR',
          'message' => $qr_payload,
          'messageEncoding' => 'iso-8859-1',
        ],
      ],
      'eventTicket' => $presentation['event_ticket'],
    ];

    $relevant_date = $presentation['relevant_date'];
    if ($relevant_date !== NULL) {
      $pass['relevantDate'] = $relevant_date;
    }

    if ($presentation['locations'] !== []) {
      $pass['locations'] = $presentation['locations'];
    }
    if ($presentation['semantics'] !== []) {
      $pass['semantics'] = $presentation['semantics'];
    }

    try {
      return json_encode($pass, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    catch (JsonException $e) {
      throw new RuntimeException('Unable to encode Apple Wallet pass.json.', 0, $e);
    }
  }

  /**
   * Platform organisation name used for organizationName and logoText.
   */
  private function organisationName(): string {
    $configured = trim((string) ($this->configFactory->get('myeventlane_wallet.settings')->get('apple_organisation_name') ?: ''));
    if ($configured === '') {
      return self::DEFAULT_ORGANISATION_NAME;
    }
    // Normalise legacy spacing / casing drift toward cohesive MEL branding.
    $compact = strtolower(preg_replace('/\s+/', '', $configured) ?? '');
    if ($compact === 'myeventlane') {
      return self::DEFAULT_ORGANISATION_NAME;
    }
    return $configured;
  }

  private function resolveSerialNumber(OrderItemInterface $orderItem, Ticket $ticket, string $ticket_code): string {
    if ($orderItem->hasField('field_wallet_serial') && !$orderItem->get('field_wallet_serial')->isEmpty()) {
      $existing = trim((string) $orderItem->get('field_wallet_serial')->value);
      if ($existing !== '') {
        return $existing;
      }
    }
    $uuid = (string) $ticket->uuid();
    return $uuid !== '' ? $uuid : ('mel-' . $orderItem->id() . '-' . $ticket_code);
  }

  private function writePassBundle(string $workDir, string $passJson): void {
    if (file_put_contents($workDir . '/pass.json', $passJson) === FALSE) {
      throw new RuntimeException('Unable to write pass.json.');
    }

    $assets_dir = $this->moduleExtensionList->getPath('myeventlane_wallet') . '/assets/pass';
    foreach ([
      'icon.png',
      'icon@2x.png',
      'icon@3x.png',
      'logo.png',
      'logo@2x.png',
    ] as $asset) {
      $source = $assets_dir . '/' . $asset;
      if (!is_file($source)) {
        throw new RuntimeException('Required Apple Wallet asset missing: ' . $asset);
      }
      if (!@copy($source, $workDir . '/' . $asset)) {
        throw new RuntimeException('Unable to copy Apple Wallet asset: ' . $asset);
      }
    }

    // Apple’s current Event Ticket image guidance does not support strip images.
    // Do not package strip.png or substitute platform artwork.
  }

  private function writeManifest(string $workDir): void {
    $manifest = [];
    foreach (scandir($workDir) ?: [] as $file) {
      if ($file === '.' || $file === '..') {
        continue;
      }
      $path = $workDir . '/' . $file;
      if (!is_file($path)) {
        continue;
      }
      $manifest[$file] = sha1_file($path);
    }
    try {
      $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT | JSON_UNESCAPED_SLASHES);
    }
    catch (JsonException $e) {
      throw new RuntimeException('Unable to encode Apple Wallet manifest.json.', 0, $e);
    }
    if (file_put_contents($workDir . '/manifest.json', $json) === FALSE) {
      throw new RuntimeException('Unable to write manifest.json.');
    }
  }

  private function zipBundle(string $workDir, string $passPath): void {
    $zip = new ZipArchive();
    if ($zip->open($passPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
      throw new RuntimeException('Unable to open Apple Wallet zip archive.');
    }
    foreach (scandir($workDir) ?: [] as $file) {
      if ($file === '.' || $file === '..') {
        continue;
      }
      $path = $workDir . '/' . $file;
      if (is_file($path)) {
        $zip->addFile($path, $file);
      }
    }
    if (!$zip->close()) {
      throw new RuntimeException('Unable to finalise Apple Wallet zip archive.');
    }
    if (!is_file($passPath) || filesize($passPath) === 0) {
      throw new RuntimeException('Apple Wallet .pkpass archive is empty.');
    }
  }

}
