<?php

declare(strict_types=1);

namespace Drupal\myeventlane_tickets\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Utility\Token;
use Drupal\myeventlane_tickets\Entity\Ticket;
use Drupal\myeventlane_tickets\Ticket\QrCodeGenerator;
use Drupal\myeventlane_tickets\Ticket\TicketQrPayload;

/**
 * Adds computed variables to the ticket PDF template.
 *
 * When an issued ticket entity is present the builder populates template
 * variables from a canonical view model built by
 * UniversalTicketViewModelBuilder. Legacy (order-item, attendee, RSVP) paths
 * still fall through to the original QR-only preprocessing.
 */
final class TicketPdfTemplateBuilder {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly QrCodeGenerator $qrCodeGenerator,
    private readonly TicketQrPayload $ticketQrPayload,
    private readonly ?UniversalTicketViewModelBuilder $viewModelBuilder = NULL,
    private readonly ?Token $token = NULL,
  ) {}

  /**
   * Preprocesses ticket PDF twig variables.
   */
  public function preprocess(array &$variables): void {
    $config = $this->configFactory->get('myeventlane_tickets.settings');

    $brand_logo_url = trim((string) ($config->get('brand_logo_url') ?? ''));
    $brand_accent_color = trim((string) ($config->get('brand_accent_color') ?? ''));
    $brand_footer_text = (string) ($config->get('brand_footer_text') ?? '');

    $variables['brand_logo_url'] = $brand_logo_url !== '' ? $brand_logo_url : NULL;
    $variables['brand_accent_color'] = $brand_accent_color !== '' ? $brand_accent_color : '#6b46ff';
    $variables['brand_footer_text'] = $this->replaceFooterTokens($brand_footer_text, $variables);
    $variables['show_ticket_code'] = (bool) ($config->get('show_ticket_code') ?? TRUE);
    $variables['include_qr_code'] = (bool) ($config->get('include_qr_code') ?? TRUE);
    $variables['qr_data_uri'] = NULL;
    $variables['brand_vendor_name'] = $this->extractVendorName($variables);

    $variables['is_pro_branded'] = $variables['brand_logo_url'] !== NULL
      || $variables['brand_footer_text'] !== ''
      || $variables['brand_vendor_name'] !== NULL;

    $ticket = $variables['ticket'] ?? NULL;

    if ($ticket instanceof Ticket && $this->viewModelBuilder !== NULL) {
      $this->applyCanonicalModel($variables, $ticket);
      return;
    }

    $this->applyLegacyQr($variables);
  }

  /**
   * Populates PDF template variables from the canonical view model.
   *
   * All operational entitlement data (QR, status, entitlement type, fulfilment,
   * expiry, badges) comes from the normalised model — no duplicated shaping.
   */
  private function applyCanonicalModel(array &$variables, Ticket $ticket): void {
    $model = $this->viewModelBuilder->build($ticket);

    $variables['qr_data_uri'] = $model['qr']['data_uri'] ?? NULL;
    $variables['view_model'] = $model;

    $variables['entitlement_type'] = $model['ticket']['entitlement_type'];
    $variables['entitlement_label'] = $model['ticket']['entitlement_label'];
    $variables['ticket_status'] = $model['ticket']['status'];
    $variables['ticket_status_label'] = $model['ticket']['status_label'];
    $variables['redemption'] = $model['redemption'];
    $variables['expiry'] = $model['expiry'];
    $variables['fulfilment'] = $model['fulfilment'];
    $variables['badges'] = $model['badges'];
    $variables['capabilities'] = $model['capabilities'] ?? [];
    $variables['vendor_display'] = $model['vendor'];
    $variables['is_canonical'] = TRUE;
  }

  /**
   * Legacy QR preprocessing for non-ticket paths (order-item, attendee, RSVP).
   */
  private function applyLegacyQr(array &$variables): void {
    $variables['is_canonical'] = FALSE;

    if (!$variables['include_qr_code']) {
      return;
    }

    $ticket = $variables['ticket'] ?? NULL;
    if ($ticket instanceof Ticket) {
      $payload = $this->ticketQrPayload->buildForTicket($ticket);
      $variables['qr_data_uri'] = $this->qrCodeGenerator->buildDataUri($payload);
      return;
    }

    $legacy_code = trim((string) ($variables['code'] ?? ''));
    if ($legacy_code !== '') {
      $variables['qr_data_uri'] = $this->qrCodeGenerator->buildDataUri($legacy_code);
    }
  }

  /**
   * Applies Token replacements if token service exists.
   */
  private function replaceFooterTokens(string $text, array $variables): string {
    if ($text === '') {
      return '';
    }
    if (!$this->token) {
      return $text;
    }

    $data = [];
    $ticket = $variables['ticket'] ?? NULL;
    if ($ticket instanceof Ticket) {
      $data['myeventlane_ticket'] = $ticket;
      $data['ticket'] = $ticket;
      $event = $ticket->get('event_id')->entity;
      if ($event instanceof EntityInterface) {
        $data['node'] = $event;
      }
      $order = $ticket->get('order_id')->entity;
      if ($order instanceof EntityInterface) {
        $data['commerce_order'] = $order;
        $owner = $order->getCustomer();
        if ($owner instanceof EntityInterface) {
          $data['user'] = $owner;
        }
      }
    }

    return $this->token->replace($text, $data, ['clear' => TRUE]);
  }

  /**
   * Gets vendor name from ticket event when available.
   */
  private function extractVendorName(array $variables): ?string {
    $ticket = $variables['ticket'] ?? NULL;
    if (!$ticket instanceof Ticket) {
      return NULL;
    }
    $event = $ticket->get('event_id')->entity;
    if (!$event || !$event->hasField('field_event_vendor') || $event->get('field_event_vendor')->isEmpty()) {
      return NULL;
    }
    $vendor = $event->get('field_event_vendor')->entity;
    return $vendor ? $vendor->label() : NULL;
  }

}
