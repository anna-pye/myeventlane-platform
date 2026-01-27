<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Resolves vendor branding (from name, reply-to, logo, accent, footer) for messages.
 *
 * Brand context is injected into the Twig wrapper and subject/body rendering.
 * No per-vendor themes; all styling via variables.
 */
final class VendorBrandResolver {

  /**
   * Constructs VendorBrandResolver.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Resolves brand data for the given context.
   *
   * @param array $context
   *   Message context; may contain event_id, order_id, etc. for vendor lookup.
   *
   * @return array
   *   Keys: from_name, reply_to, logo_url, accent_color, footer_text.
   */
  public function resolve(array $context): array {
    $settings = $this->configFactory->get('myeventlane_messaging.settings');
    $fromName = $settings->get('from_name') ?? 'MyEventLane';
    $fromEmail = $settings->get('from_email') ?? '';
    $replyTo = $settings->get('reply_to') ?? $fromEmail;

    return [
      'from_name' => $fromName,
      'from_email' => $fromEmail,
      'reply_to' => $replyTo ?: $fromEmail,
      'logo_url' => '',
      'accent_color' => '#6e7ef2',
      'footer_text' => 'You’re receiving this because you interacted with MyEventLane.',
    ];
  }

}
