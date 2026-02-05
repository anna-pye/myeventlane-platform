<?php

declare(strict_types=1);

namespace Drupal\myeventlane_messaging\ValueObject;

/**
 * Immutable value object representing resolved brand settings.
 *
 * Provides a normalized, type-safe representation of messaging brand
 * configuration that can be used throughout the messaging system.
 */
final class Brand {

  /**
   * Brand source: MEL platform defaults.
   */
  public const SOURCE_DEFAULT = 'default';

  /**
   * Brand source: Vendor entity fields.
   */
  public const SOURCE_VENDOR = 'vendor';

  /**
   * Brand source: Event-level override.
   */
  public const SOURCE_EVENT = 'event';

  /**
   * Default MEL accent color (coral).
   */
  public const DEFAULT_ACCENT_COLOR = '#f26d5b';

  /**
   * Default footer text.
   */
  public const DEFAULT_FOOTER = "You're receiving this because you interacted with MyEventLane.";

  /**
   * Constructs a Brand value object.
   *
   * @param string $fromName
   *   The "From" display name.
   * @param string $fromEmail
   *   The "From" email address.
   * @param string $replyTo
   *   The "Reply-To" email address.
   * @param string $footerText
   *   Custom footer text.
   * @param string $accentColor
   *   Hex color code for accent (buttons, links).
   * @param string $logoUrl
   *   Absolute URL to logo image.
   * @param string $source
   *   Source of brand settings (default, vendor, event).
   * @param int|null $vendorId
   *   Vendor ID if applicable.
   * @param int|null $eventId
   *   Event ID if applicable.
   * @param array $marketing
   *   Optional marketing content array.
   */
  public function __construct(
    public readonly string $fromName,
    public readonly string $fromEmail,
    public readonly string $replyTo,
    public readonly string $footerText,
    public readonly string $accentColor,
    public readonly string $logoUrl,
    public readonly string $source = self::SOURCE_DEFAULT,
    public readonly ?int $vendorId = NULL,
    public readonly ?int $eventId = NULL,
    public readonly array $marketing = [],
  ) {}

  /**
   * Creates a Brand from an associative array.
   *
   * @param array $data
   *   Array with keys: from_name, from_email, reply_to, footer_text,
   *   accent_color, logo_url, source, vendor_id, event_id, marketing.
   *
   * @return self
   *   A new Brand instance.
   */
  public static function fromArray(array $data): self {
    return new self(
      fromName: (string) ($data['from_name'] ?? 'MyEventLane'),
      fromEmail: (string) ($data['from_email'] ?? ''),
      replyTo: (string) ($data['reply_to'] ?? ''),
      footerText: (string) ($data['footer_text'] ?? self::DEFAULT_FOOTER),
      accentColor: (string) ($data['accent_color'] ?? self::DEFAULT_ACCENT_COLOR),
      logoUrl: (string) ($data['logo_url'] ?? ''),
      source: (string) ($data['source'] ?? self::SOURCE_DEFAULT),
      vendorId: isset($data['vendor_id']) ? (int) $data['vendor_id'] : NULL,
      eventId: isset($data['event_id']) ? (int) $data['event_id'] : NULL,
      marketing: is_array($data['marketing'] ?? NULL) ? $data['marketing'] : [],
    );
  }

  /**
   * Converts the Brand to an associative array.
   *
   * @return array
   *   Array representation suitable for template context.
   */
  public function toArray(): array {
    return [
      'from_name' => $this->fromName,
      'from_email' => $this->fromEmail,
      'reply_to' => $this->replyTo,
      'footer_text' => $this->footerText,
      'accent_color' => $this->accentColor,
      'logo_url' => $this->logoUrl,
      'source' => $this->source,
      'vendor_id' => $this->vendorId,
      'event_id' => $this->eventId,
      'marketing' => $this->marketing,
    ];
  }

  /**
   * Checks if brand was resolved from a vendor.
   *
   * @return bool
   *   TRUE if from vendor, FALSE otherwise.
   */
  public function isFromVendor(): bool {
    return $this->source === self::SOURCE_VENDOR;
  }

  /**
   * Checks if brand is using platform defaults.
   *
   * @return bool
   *   TRUE if using defaults, FALSE otherwise.
   */
  public function isDefault(): bool {
    return $this->source === self::SOURCE_DEFAULT;
  }

}
