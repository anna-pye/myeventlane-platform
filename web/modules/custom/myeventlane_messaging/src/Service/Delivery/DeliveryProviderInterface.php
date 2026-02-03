<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Service\Delivery;

/**
 * Interface for message delivery providers (email, etc.).
 */
interface DeliveryProviderInterface {

  /**
   * Sends a message via this provider.
   *
   * @param array $params
   *   Must contain: to, subject, body, langcode; optional: html, attachments,
   *   from_name, from_email, reply_to.
   *
   * @return bool
   *   TRUE if accepted/sent, FALSE otherwise.
   */
  public function send(array $params): bool;

  /**
   * Provider id (e.g. 'drupal_mail', 'postmark').
   */
  public function id(): string;

}
