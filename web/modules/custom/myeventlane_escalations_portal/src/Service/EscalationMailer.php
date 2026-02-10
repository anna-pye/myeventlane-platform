<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_portal\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends escalation notification emails.
 *
 * All templates are friendly, respectful, gender-neutral, Australian English.
 */
final class EscalationMailer {

  public function __construct(
    private readonly MailManagerInterface $mailManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EscalationPartyResolver $partyResolver,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Notifies the vendor that a new escalation was submitted.
   */
  public function notifyVendorNewEscalation(EntityInterface $escalation): void {
    $vendor_email = $this->getVendorEmail($escalation);
    if (!$vendor_email) {
      return;
    }

    $this->send('escalation_new_vendor', $vendor_email, [
      'escalation' => $escalation,
      'portal_url' => '/vendor/support/' . $escalation->id(),
    ]);
  }

  /**
   * Notifies the customer that a reply was posted.
   */
  public function notifyCustomerReply(EntityInterface $escalation): void {
    $customer_email = $this->getCustomerEmail($escalation);
    if (!$customer_email) {
      return;
    }

    $this->send('escalation_comment_customer', $customer_email, [
      'escalation' => $escalation,
      'portal_url' => '/my/support/escalations/' . $escalation->id(),
    ]);
  }

  /**
   * Notifies the vendor that a reply was posted.
   */
  public function notifyVendorReply(EntityInterface $escalation): void {
    $vendor_email = $this->getVendorEmail($escalation);
    if (!$vendor_email) {
      return;
    }

    $this->send('escalation_comment_vendor', $vendor_email, [
      'escalation' => $escalation,
      'portal_url' => '/vendor/support/' . $escalation->id(),
    ]);
  }

  /**
   * Notifies the customer that the escalation was resolved.
   */
  public function notifyCustomerResolved(EntityInterface $escalation): void {
    $customer_email = $this->getCustomerEmail($escalation);
    if (!$customer_email) {
      return;
    }

    $this->send('escalation_resolved', $customer_email, [
      'escalation' => $escalation,
      'portal_url' => '/my/support/escalations/' . $escalation->id(),
    ]);
  }

  /**
   * Notifies the vendor that the escalation was reopened.
   */
  public function notifyVendorReopened(EntityInterface $escalation): void {
    $vendor_email = $this->getVendorEmail($escalation);
    if (!$vendor_email) {
      return;
    }

    $this->send('escalation_reopened', $vendor_email, [
      'escalation' => $escalation,
      'portal_url' => '/vendor/support/' . $escalation->id(),
    ]);
  }

  /**
   * Gets the customer's email address from the escalation.
   */
  private function getCustomerEmail(EntityInterface $escalation): ?string {
    if (!$escalation->hasField('user_id') || $escalation->get('user_id')->isEmpty()) {
      return NULL;
    }

    /** @var \Drupal\user\UserInterface|null $user */
    $user = $escalation->get('user_id')->entity;
    return $user ? $user->getEmail() : NULL;
  }

  /**
   * Gets the vendor owner's email address from the escalation.
   */
  private function getVendorEmail(EntityInterface $escalation): ?string {
    if (!$escalation->hasField('vendor_id') || $escalation->get('vendor_id')->isEmpty()) {
      return NULL;
    }

    /** @var \Drupal\myeventlane_vendor\Entity\Vendor|null $vendor */
    $vendor = $escalation->get('vendor_id')->entity;
    if (!$vendor) {
      return NULL;
    }

    /** @var \Drupal\user\UserInterface|null $owner */
    $owner = $vendor->getOwner();
    return $owner ? $owner->getEmail() : NULL;
  }

  /**
   * Sends a mail using the module's hook_mail().
   */
  private function send(string $key, string $to, array $params): void {
    try {
      $this->mailManager->mail(
        'myeventlane_escalations_portal',
        $key,
        $to,
        'en',
        $params,
      );
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to send escalation email key={key} to={to}: {msg}', [
        'key' => $key,
        'to' => $to,
        'msg' => $e->getMessage(),
      ]);
    }
  }

}
