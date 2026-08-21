<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_messaging\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\Config;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_messaging\Service\MessageRenderer;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Guardrail tests for messaging invariants (no UI copy assertions).
 *
 * @group myeventlane_messaging
 */
#[RunTestsInSeparateProcesses]
final class MessagingGuardrailTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Keep module list minimal while still enabling the real MessageRenderer and
   * email wrapper theme hook.
   */
  protected static $modules = [
    // Core.
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'file',
    'image',
    'datetime',
    // Contrib.
    'flag',
    // MyEventLane optional-but-referenced dependencies.
    'myeventlane_boost',
    // MyEventLane dependencies for myeventlane_messaging.
    'myeventlane_core',
    'myeventlane_event',
    'myeventlane_event_state',
    'myeventlane_event_attendees',
    // Module under test.
    'myeventlane_messaging',
  ];

  private MessageRenderer $renderer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->renderer = $this->container->get('myeventlane_messaging.message_renderer');
  }

  /**
   * Messaging templates must render without throwing exceptions.
   *
   * This intentionally avoids asserting exact phrasing; it only guards that
   * rendering is stable for representative payload shapes (including nested
   * arrays for receipts).
   */
  public function testMessagingTemplatesRenderWithoutExceptions(): void {
    foreach ($this->getMessagingTemplateYamlPaths() as $path) {
      $data = Yaml::decode((string) file_get_contents($path));
      $this->assertIsArray($data, "Template YAML must decode to array: {$path}");

      $key = $this->templateKeyFromPath($path);
      $context = $this->contextForTemplateKey($key);

      if (isset($data['subject'])) {
        $subject = $this->renderer->renderString((string) $data['subject'], $context);
        $this->assertIsString($subject);
        $this->assertNotSame('', $subject, "Subject should not render to empty for {$key}");
      }

      // Some templates are stored under 'body' (string) and some under
      // 'body_html' (string). Guard both forms.
      if (isset($data['body'])) {
        $body = $this->renderer->renderString((string) $data['body'], $context);
        $this->assertIsString($body);
        $this->assertNotSame('', $body, "Body should not render to empty for {$key}");
      }

      if (isset($data['body_html'])) {
        // Render the inner body HTML as a Twig string. We intentionally avoid
        // the wrapper here, because the wrapper applies `replace(context)`,
        // which is sensitive to non-scalar context values (e.g. order receipts
        // include nested arrays).
        $inner = $this->renderer->renderString((string) $data['body_html'], $context);
        $this->assertIsString($inner);
        $this->assertNotSame('', $inner, "HTML body should not render to empty for {$key}");
      }
    }
  }

  /**
   * Rendering must be resilient when variables are missing (graceful failure).
   *
   * This guards that template rendering does not throw, even if Twig evaluation
   * encounters an error due to missing/invalid context.
   */
  public function testMissingVariablesFailGracefully(): void {
    // Missing variables should not throw.
    $missing = 'Hello {{ missing.nested.attribute }}';
    $rendered = $this->renderer->renderString($missing, []);
    $this->assertIsString($rendered);
    $this->assertStringContainsString('Hello', $rendered);

    // A Twig evaluation error must be caught and handled gracefully.
    $invalid = '{% if %}';
    $fallback = $this->renderer->renderString($invalid, []);
    $this->assertIsString($fallback);
    $this->assertStringContainsString('{% if %}', $fallback);

    // Same guard for HTML body rendering: error in inner Twig must not throw.
    $conf = $this->configFromInlineBodyHtml($invalid);
    $html = $this->renderer->renderHtmlBody($conf, []);
    $this->assertIsString($html);
    $this->assertStringContainsString('{% if %}', $html);
  }

  /**
   * Order confirmation must render with and without donations (no exceptions).
   */
  public function testOrderConfirmationRendersWithAndWithoutDonations(): void {
    $path = $this->moduleTemplatePath('myeventlane_messaging', 'order_confirmation');
    $data = Yaml::decode((string) file_get_contents($path));
    $this->assertIsArray($data);
    $this->assertArrayHasKey('body_html', $data);

    $base = $this->contextForTemplateKey('order_confirmation');
    $noDonation = $base;
    $noDonation['donation_total'] = NULL;
    $noDonationHtml = $this->renderer->renderString((string) $data['body_html'], $noDonation);
    $this->assertIsString($noDonationHtml);
    $this->assertNotSame('', $noDonationHtml);

    $withDonation = $base;
    $withDonation['donation_total'] = '$10.00';
    $withDonationHtml = $this->renderer->renderString((string) $data['body_html'], $withDonation);
    $this->assertIsString($withDonationHtml);
    $this->assertNotSame('', $withDonationHtml);
  }

  /**
   * ACE Phase 2: booking confirmation subject/body use booking terminology.
   */
  public function testOrderConfirmationUsesBookingTerminology(): void {
    $path = $this->moduleTemplatePath('myeventlane_messaging', 'order_confirmation');
    $data = Yaml::decode((string) file_get_contents($path));
    $this->assertIsArray($data);

    $context = $this->contextForTemplateKey('order_confirmation');
    $subject = $this->renderer->renderString((string) $data['subject'], $context);
    $body = $this->renderer->renderString((string) $data['body_html'], $context);

    $this->assertSame('Your booking is confirmed – Test Event', $subject);
    $this->assertStringContainsString('Booking confirmed', $body);
    $this->assertStringContainsString('Booking #1001', $body);
    $this->assertStringContainsString('View Digital Pass', $body);
    $this->assertStringContainsString('View Event', $body);
    $this->assertStringContainsString('What happens next?', $body);
    $this->assertStringContainsString('Need help?', $body);
    $this->assertStringContainsString('Booking total', $body);
    $this->assertStringContainsString('Ticket summary', $body);

    $lower = mb_strtolower($body . "\n" . $subject);
    foreach (['order confirmed', 'purchase complete', 'receipt', 'tax invoice'] as $forbidden) {
      $this->assertStringNotContainsString($forbidden, $lower);
    }

    $fallbackSubject = $this->renderer->renderString((string) $data['subject'], [
      'event_name' => NULL,
      'is_paid' => TRUE,
    ]);
    $this->assertSame('Booking confirmed – MyEventLane', $fallbackSubject);
  }

  /**
   * Guest / pending / paid variants for ACE Phase 2 hardening.
   */
  public function testOrderConfirmationGuestAndPaymentVariants(): void {
    $path = $this->moduleTemplatePath('myeventlane_messaging', 'order_confirmation');
    $data = Yaml::decode((string) file_get_contents($path));
    $this->assertIsArray($data);

    $guest = $this->contextForTemplateKey('order_confirmation');
    $guest['is_guest'] = TRUE;
    $guest['order_url'] = NULL;
    $guestBody = $this->renderer->renderString((string) $data['body_html'], $guest);
    $this->assertStringContainsString('View Event', $guestBody);
    $this->assertStringNotContainsString('View Digital Pass', $guestBody);
    $this->assertStringNotContainsString('/my-tickets/order/', $guestBody);
    $this->assertStringContainsString('without signing in', $guestBody);

    $pending = $this->contextForTemplateKey('order_confirmation');
    $pending['is_paid'] = FALSE;
    $pendingSubject = $this->renderer->renderString((string) $data['subject'], $pending);
    $pendingBody = $this->renderer->renderString((string) $data['body_html'], $pending);
    $this->assertSame('Booking received – payment pending', $pendingSubject);
    $this->assertStringContainsString('Booking received', $pendingBody);
    $this->assertStringNotContainsString('Booking confirmed', $pendingBody);
  }

  /**
   * Forbidden pressure-phrases must not appear in attendee-facing payloads.
   *
   * Note: The canonical list is currently derived from project docs
   * (v0.5.0 alignment notes). This test is intentionally narrow and checks only
   * explicit forbidden phrases referenced in-repo (case-insensitive).
   */
  public function testForbiddenPhrasesNotPresentInAttendeeFacingRenderedOutput(): void {
    $forbidden = [
      'secure checkout',
      'tickets are waiting',
    ];

    foreach ($this->attendeeFacingTemplateKeys() as $key) {
      $path = $this->templateYamlPathForKey($key);
      $data = Yaml::decode((string) file_get_contents($path));
      $this->assertIsArray($data, "Template YAML must decode to array: {$path}");

      $context = $this->contextForTemplateKey($key);
      $renderedPieces = [];

      if (!empty($data['subject'])) {
        $renderedPieces[] = $this->renderer->renderString((string) $data['subject'], $context);
      }
      if (!empty($data['body'])) {
        $renderedPieces[] = $this->renderer->renderString((string) $data['body'], $context);
      }
      if (!empty($data['body_html'])) {
        $renderedPieces[] = $this->renderer->renderString((string) $data['body_html'], $context);
      }

      $rendered = implode("\n\n", $renderedPieces);
      $this->assertNotSame('', $rendered, "Rendered output should not be empty for {$key}");

      $lower = mb_strtolower($rendered);
      foreach ($forbidden as $phrase) {
        $this->assertStringNotContainsString($phrase, $lower, "Forbidden phrase '{$phrase}' must not appear for {$key}");
      }
    }
  }

  /**
   * The email wrapper must render (with scalar-only context).
   *
   * This guards the wrapper theme hook and HTML layout without asserting
   * any production copy.
   */
  public function testEmailWrapperRendersWithScalarContext(): void {
    $conf = $this->configFromInlineBodyHtml('<p>GUARDRAIL_MARKER: {{ name|default("x") }}</p>');
    $html = $this->renderer->renderHtmlBody($conf, ['name' => 'ok']);
    $this->assertIsString($html);
    $this->assertStringContainsString('GUARDRAIL_MARKER', $html);
  }

  /**
   * Attendee-facing messaging template keys (exclude vendor/admin).
   *
   * @return string[]
   *   Template keys.
   */
  private function attendeeFacingTemplateKeys(): array {
    return [
      // Transactional attendee emails.
      'order_confirmation',
      'order_invoice',
      'rsvp_confirmation',
      'cart_abandoned',
      'event_reminder',
      'event_reminder_7d',
      'event_reminder_24h',
      'event_reminder_2h',
      'event_cancelled',
      'waitlist_invite',
      // Note: export_ready_* and sales_open are vendor-facing; boost_reminder is
      // vendor-facing.
    ];
  }

  /**
   * Builds representative contexts per template key.
   *
   * @param string $key
   *   Template key.
   *
   * @return array
   *   Context array (intentionally minimal; no copy assertions).
   */
  private function contextForTemplateKey(string $key): array {
    $base = [
      // Common scalar placeholders.
      'first_name' => 'Test',
      'order_number' => '1001',
      'order_url' => 'https://example.test/orders/1001',
      'order_email' => 'test@example.test',
      'event_title' => 'Test Event',
      'event_url' => 'https://example.test/events/1',
      'my_tickets_url' => 'https://example.test/my-tickets/1001',
      'event_start' => 'Jan 1, 2026 10:00 AM',
      'event_start_date' => 'Jan 1, 2026',
      'event_start_time' => '10:00 AM',
      'event_location' => 'Test Venue',
      'starts_at' => 'Jan 1, 2026 10:00 AM',
      'expires_at' => 'Jan 1, 2026 10:00 AM',
      'extend_url' => 'https://example.test/boost/1',
      'invite_url' => 'https://example.test/waitlist/claim',
      'expires_at_time' => '10:00 AM',
      'venue' => 'Test Venue',
      'cart_url' => 'https://example.test/cart',
      'download_url' => 'https://example.test/download.csv',
      'export_type' => 'CSV',
      'cancel_reason' => 'Scheduling conflict',
      // Footer (wrapper optional).
      'unsubscribe_url' => NULL,
    ];

    if ($key === 'order_confirmation') {
      return $base + [
        'order_id' => 1001,
        'order_url' => 'https://example.test/my-tickets/order/1001',
        'digital_pass_url' => 'https://example.test/my-tickets/order/1001',
        'tickets_url' => 'https://example.test/my-tickets/order/1001#mel-pass-entry',
        'apple_wallet_url' => NULL,
        'google_wallet_url' => NULL,
        'pdf_url' => 'https://example.test/ticket/ABC123/pdf',
        'manage_booking_url' => 'https://example.test/my-tickets',
        'has_tickets' => TRUE,
        'events' => [
          [
            'title' => 'Test Event',
            'url' => 'https://example.test/events/1',
            'image_url' => NULL,
            'image_alt' => NULL,
            'start_date' => '1 Jan 2026',
            'end_date' => NULL,
            'start_time' => '10:00 am',
            'end_time' => NULL,
            'venue_name' => 'Test Venue',
            'location' => '123 Test St',
            'organiser_name' => 'Sample Organiser',
            'organiser_url' => 'https://example.test/vendors/sample',
            'contact_email' => 'organiser@example.test',
            'contact_phone' => NULL,
            'accessibility_contact' => NULL,
          ],
        ],
        'ticket_items' => [
          [
            'title' => 'General Admission',
            'quantity' => 1,
            'price' => '$10.00',
            'attendees' => [
              ['name' => 'Test Attendee', 'email' => 'attendee@example.test'],
            ],
          ],
        ],
        'donation_total' => NULL,
        'total_paid' => '$10.00',
        'booking_total' => '$10.00',
        'order_number' => '1001',
        'is_guest' => FALSE,
        'is_paid' => TRUE,
        'event_name' => 'Test Event',
        'event_url' => 'https://example.test/events/1',
        'organiser_name' => 'Sample Organiser',
        'organiser_url' => 'https://example.test/vendors/sample',
        'help_centre_url' => 'https://example.test/help',
        'refund_policy_url' => 'https://example.test/help/policies/refund-policy',
        'support_url' => 'https://example.test/support',
        'tickets_need_assignment' => FALSE,
        'show_includes_gst_note' => TRUE,
      ];
    }

    if ($key === 'order_invoice') {
      return $base + [
        'invoice_date' => 'Jan 1, 2026',
        'line_items' => [
          [
            'title' => 'General Admission',
            'quantity' => 1,
            'unit_price' => '$10.00',
            'line_total' => '$10.00',
          ],
        ],
        'fee_lines' => [
          ['label' => 'Processing fee', 'amount' => '$1.00'],
        ],
        'tax_lines' => [
          ['label' => 'GST', 'amount' => '$1.00'],
        ],
        'events' => [
          ['title' => 'Test Event'],
        ],
        'total_paid' => '$12.00',
        'event_name' => 'Test Event',
      ];
    }

    if ($key === 'rsvp_confirmation') {
      return $base + [
        'guests' => 2,
        'event_date' => 'Jan 1, 2026 at 10:00 AM',
        'event_location' => '123 Test St',
        'attendee_email' => 'test@example.test',
      ];
    }

    if (in_array($key, ['event_reminder_7d', 'event_reminder_24h'], TRUE)) {
      return $base + [
        'attendee_names' => ['Test Attendee'],
      ];
    }

    if ($key === 'waitlist_invite') {
      return $base + [
        'expires_at' => '10:00 AM',
      ];
    }

    if ($key === 'event_cancelled') {
      return $base + [
        'has_paid_tickets' => FALSE,
        'refund_info' => 'The organiser is responsible for refund decisions. Approved refunds are processed from the organiser\'s connected Stripe account.',
      ];
    }

    // Fallback: base context is usually sufficient for subject/body-only
    // templates.
    return $base;
  }

  /**
   * Returns YAML paths for messaging templates shipped by MEL modules.
   *
   * @return string[]
   *   Absolute file paths.
   */
  private function getMessagingTemplateYamlPaths(): array {
    $paths = [];

    $paths = array_merge($paths, glob($this->modulePath('myeventlane_messaging') . '/config/install/myeventlane_messaging.template.*.yml') ?: []);
    $paths = array_merge($paths, glob($this->modulePath('myeventlane_automation') . '/config/install/myeventlane_messaging.template.*.yml') ?: []);

    sort($paths);
    return $paths;
  }

  /**
   * Resolves a module's filesystem path.
   */
  private function modulePath(string $module): string {
    return $this->container->get('extension.list.module')->getPath($module);
  }

  /**
   * Convenience: resolve a specific template YAML path by key.
   */
  private function templateYamlPathForKey(string $key): string {
    return $this->moduleTemplatePath('myeventlane_messaging', $key)
      ?: $this->moduleTemplatePath('myeventlane_automation', $key);
  }

  /**
   * Resolve template YAML file path for a module + key.
   */
  private function moduleTemplatePath(string $module, string $key): string {
    $path = $this->modulePath($module) . "/config/install/myeventlane_messaging.template.{$key}.yml";
    return is_file($path) ? $path : '';
  }

  /**
   * Extracts template key from a YAML filename.
   */
  private function templateKeyFromPath(string $path): string {
    $base = basename($path);
    // Format: myeventlane_messaging.template.{key}.yml
    $base = preg_replace('/^myeventlane_messaging\\.template\\./', '', $base) ?? $base;
    $base = preg_replace('/\\.yml$/', '', $base) ?? $base;
    return (string) $base;
  }

  /**
   * Creates an in-memory Config object with the provided body_html.
   */
  private function configFromInlineBodyHtml(string $body_html): Config {
    $conf = $this->container->get('config.factory')->getEditable('myeventlane_guardrail.inline');
    $conf->setData([
      'enabled' => TRUE,
      'body_html' => $body_html,
    ]);
    return $conf;
  }

}
