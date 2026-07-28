<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_messaging\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\myeventlane_core\Service\LanguageStyleService;
use Drupal\myeventlane_core\Service\DomainDetector;
use Drupal\myeventlane_messaging\Service\OrderConfirmationQueueBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * ACE Phase 2 unit coverage for order_confirmation copy and structure.
 *
 * @group myeventlane_messaging
 * @coversNothing
 */
final class OrderConfirmationTemplateAceTest extends TestCase {

  /**
   * Booking confirmation subject and body use ACE booking terminology.
   */
  public function testOrderConfirmationUsesBookingTerminology(): void {
    $path = dirname(__DIR__, 3) . '/config/install/myeventlane_messaging.template.order_confirmation.yml';
    $this->assertFileExists($path);
    $data = Yaml::decode((string) file_get_contents($path));
    $this->assertIsArray($data);
    $this->assertArrayHasKey('subject', $data);
    $this->assertArrayHasKey('body_html', $data);

    $twig = new Environment(new ArrayLoader([]));
    $context = $this->sampleContext();

    $subject = trim($twig->createTemplate((string) $data['subject'])->render($context));
    $body = trim($twig->createTemplate((string) $data['body_html'])->render($context));

    $this->assertSame('Your booking is confirmed – Test Event', $subject);
    $this->assertStringContainsString('Booking confirmed', $body);
    $this->assertStringContainsString('Booking #1001', $body);
    $this->assertStringNotContainsString('Order 1001', $body);
    $this->assertStringNotContainsString('Order #', $body);
    $this->assertStringContainsString('Ticket summary', $body);
    $this->assertStringContainsString('Booking total', $body);
    $this->assertStringContainsString('View Digital Pass', $body);
    $this->assertStringContainsString('View Event', $body);
    $this->assertStringContainsString('Manage Booking', $body);
    $this->assertStringContainsString('Download PDF', $body);
    $this->assertStringNotContainsString('Add to Apple Wallet', $body);
    $this->assertStringNotContainsString('Add to Google Wallet', $body);
    $this->assertStringContainsString('What happens next?', $body);
    $this->assertStringContainsString('Need help?', $body);
    $this->assertStringContainsString('Refund policy', $body);
    $this->assertStringContainsString('Help Centre', $body);
    $this->assertStringContainsString('Contact organiser', $body);
    $this->assertStringContainsString('Support', $body);
    $this->assertStringContainsString('We\'ll remind you before your event.', $body);
    $this->assertStringContainsString('/vendors/sample', $body);
    $this->assertStringNotContainsString('/organisers/', $body);

    $lower = mb_strtolower($subject . "\n" . $body);
    foreach (['order confirmed', 'purchase complete', 'receipt', 'tax invoice', 'download your tickets'] as $forbidden) {
      $this->assertStringNotContainsString($forbidden, $lower);
    }

    $fallback = trim($twig->createTemplate((string) $data['subject'])->render([
      'event_name' => NULL,
      'is_paid' => TRUE,
    ]));
    $this->assertSame('Booking confirmed – MyEventLane', $fallback);
  }

  /**
   * Guest confirmation: View Event primary, no authenticated-only booking URL.
   */
  public function testGuestConfirmationUsesViewEventPrimaryCta(): void {
    $data = $this->loadTemplate();
    $twig = new Environment(new ArrayLoader([]));
    $context = $this->sampleContext();
    $context['is_guest'] = TRUE;
    $context['order_url'] = NULL;
    $context['tickets_url'] = NULL;

    $body = trim($twig->createTemplate((string) $data['body_html'])->render($context));

    $this->assertStringContainsString('View Event', $body);
    $this->assertStringContainsString('https://example.test/events/1', $body);
    $this->assertStringNotContainsString('View Digital Pass', $body);
    $this->assertStringNotContainsString('/my-tickets/order/', $body);
    $this->assertStringNotContainsString('Add to Apple Wallet', $body);
    $this->assertStringNotContainsString('Download PDF', $body);
    $this->assertStringContainsString('without signing in', $body);
    $this->assertStringContainsString('Booking #1001', $body);
  }

  /**
   * Authenticated confirmation uses View Digital Pass as primary CTA.
   */
  public function testAuthenticatedConfirmationUsesViewDigitalPassPrimaryCta(): void {
    $data = $this->loadTemplate();
    $twig = new Environment(new ArrayLoader([]));
    $context = $this->sampleContext();
    $context['is_guest'] = FALSE;
    $context['order_url'] = 'https://example.test/my-tickets/order/1001';
    $context['digital_pass_url'] = 'https://example.test/my-tickets/order/1001';

    $body = trim($twig->createTemplate((string) $data['body_html'])->render($context));

    $this->assertStringContainsString('View Digital Pass', $body);
    $this->assertStringContainsString('/my-tickets/order/1001', $body);
    $this->assertStringContainsString('View Event', $body);
    $this->assertStringContainsString('Manage Booking', $body);
    $this->assertStringNotContainsString('without signing in', $body);
  }

  /**
   * Wallet CTAs render only when gated URLs are present.
   */
  public function testWalletCtasAppearOnlyWhenUrlsProvided(): void {
    $data = $this->loadTemplate();
    $twig = new Environment(new ArrayLoader([]));
    $context = $this->sampleContext();
    $context['apple_wallet_url'] = 'https://example.test/wallet/apple/55';
    $context['google_wallet_url'] = 'https://example.test/wallet/google/55';
    $context['apple_wallet_badge_url'] = 'https://example.test/modules/custom/myeventlane_wallet/assets/web/add-to-apple-wallet.svg';
    $context['google_wallet_badge_url'] = 'https://example.test/modules/custom/myeventlane_wallet/assets/web/add-to-google-wallet.png';
    $context['pdf_url'] = 'https://example.test/ticket/ABC123/pdf';

    $body = trim($twig->createTemplate((string) $data['body_html'])->render($context));

    $this->assertStringContainsString('Add to Apple Wallet', $body);
    $this->assertStringContainsString('Add to Google Wallet', $body);
    $this->assertStringContainsString('Download PDF', $body);
    $this->assertStringContainsString('/wallet/apple/55', $body);
    $this->assertStringContainsString('/wallet/google/55', $body);
    $this->assertStringContainsString('add-to-apple-wallet.svg', $body);
    $this->assertStringContainsString('add-to-google-wallet.png', $body);
    $this->assertStringContainsString('width="156" height="48"', $body);
    $this->assertStringContainsString('width="272" height="48"', $body);
  }

  /**
   * Wallet CTAs are omitted when official badge artwork is unavailable.
   */
  public function testWalletCtaDoesNotImitateMissingOfficialBadge(): void {
    $data = $this->loadTemplate();
    $twig = new Environment(new ArrayLoader([]));
    $context = $this->sampleContext();
    $context['apple_wallet_url'] = 'https://example.test/wallet/apple/55';
    $context['apple_wallet_badge_url'] = NULL;

    $body = trim($twig->createTemplate((string) $data['body_html'])->render($context));

    $this->assertStringNotContainsString('/wallet/apple/55', $body);
    $this->assertStringNotContainsString('Add to Apple Wallet', $body);
    $this->assertStringNotContainsString('mel-wallet-badge__fallback', $body);
  }

  /**
   * Email wallet CTAs never use the action builder's host-relative fallback.
   */
  public function testWalletEmailUrlRejectsHostRelativeFallback(): void {
    $reflection = new \ReflectionClass(OrderConfirmationQueueBuilder::class);
    $builder = $reflection->newInstanceWithoutConstructor();
    $logger = $reflection->getProperty('logger');
    $logger->setValue($builder, new NullLogger());
    $method = $reflection->getMethod('walletEmailUrl');
    $method->setAccessible(TRUE);

    $this->assertNull($method->invoke($builder, NULL, '/wallet/apple/55'));
    $this->assertSame(
      'https://example.test/wallet/apple/55',
      $method->invoke($builder, NULL, 'https://example.test/wallet/apple/55'),
    );
  }

  /**
   * Wallet email badges use public-domain raster assets only.
   */
  public function testWalletEmailBadgeUsesPublicDomainAndRejectsSvg(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('public_domain')
      ->willReturn('https://public.example.test');
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->with('myeventlane_core.domain_settings')
      ->willReturn($config);

    $reflection = new \ReflectionClass(OrderConfirmationQueueBuilder::class);
    $builder = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('domainDetector')->setValue(
      $builder,
      new DomainDetector(new RequestStack(), $config_factory),
    );
    $reflection->getProperty('logger')->setValue($builder, new NullLogger());
    $method = $reflection->getMethod('walletEmailBadgeUrl');
    $method->setAccessible(TRUE);

    $this->assertSame(
      'https://public.example.test/modules/custom/myeventlane_wallet/assets/web/add-to-google-wallet.png',
      $method->invoke($builder, 'https://vendor.example.test/modules/custom/myeventlane_wallet/assets/web/add-to-google-wallet.png'),
    );
    $this->assertNull(
      $method->invoke($builder, 'https://vendor.example.test/modules/custom/myeventlane_wallet/assets/web/add-to-apple-wallet.svg'),
    );
  }

  /**
   * Unpaid / pending payment never claims confirmation.
   */
  public function testPendingPaymentUsesReceivedCopy(): void {
    $data = $this->loadTemplate();
    $twig = new Environment(new ArrayLoader([]));
    $context = $this->sampleContext();
    $context['is_paid'] = FALSE;

    $subject = trim($twig->createTemplate((string) $data['subject'])->render($context));
    $body = trim($twig->createTemplate((string) $data['body_html'])->render($context));
    $preheader = trim($twig->createTemplate((string) $data['preheader'])->render($context));

    $this->assertSame('Booking received – payment pending', $subject);
    $this->assertStringContainsString('Booking received', $body);
    $this->assertStringContainsString('waiting for payment confirmation', $body);
    $this->assertStringContainsString('payment pending', $preheader);
    $this->assertStringNotContainsString('Booking confirmed', $body);
    $this->assertStringNotContainsString('your booking is confirmed', mb_strtolower($body));
    $this->assertStringNotContainsString('Your booking is confirmed', $subject);
  }

  /**
   * Paid booking still says confirmed.
   */
  public function testPaidBookingSaysConfirmed(): void {
    $data = $this->loadTemplate();
    $twig = new Environment(new ArrayLoader([]));
    $context = $this->sampleContext();
    $context['is_paid'] = TRUE;

    $subject = trim($twig->createTemplate((string) $data['subject'])->render($context));
    $body = trim($twig->createTemplate((string) $data['body_html'])->render($context));

    $this->assertSame('Your booking is confirmed – Test Event', $subject);
    $this->assertStringContainsString('Booking confirmed', $body);
    $this->assertStringNotContainsString('payment pending', mb_strtolower($subject . "\n" . $body));
  }

  /**
   * Contact organiser link is omitted when organiser URL is unavailable.
   */
  public function testOrganiserContactOmittedWithoutUrl(): void {
    $data = $this->loadTemplate();
    $twig = new Environment(new ArrayLoader([]));

    $context = $this->sampleContext();
    $context['organiser_url'] = NULL;
    $context['events'][0]['organiser_url'] = NULL;

    $body = trim($twig->createTemplate((string) $data['body_html'])->render($context));
    $this->assertStringNotContainsString('Contact organiser', $body);
    $this->assertStringContainsString('Sample Organiser', $body);
  }

  /**
   * Language style must not rewrite /vendors/ paths inside email HTML.
   */
  public function testLanguageStyleDoesNotRewriteVendorPathsInEmailHtml(): void {
    $service = new LanguageStyleService();
    $html = '<a href="https://example.test/vendors/sample">Contact organiser</a>';

    $this->assertTrue($service->looksLikeHtml($html));
    $this->assertFalse($service->looksLikeUrl($html));

    // Simulates theme preprocess: skip HTML blobs so canonical URLs survive.
    $value = $html;
    if (!$service->shouldSkipKey('content') && !$service->looksLikeUrl($value) && !$service->looksLikeHtml($value)) {
      $value = $service->replace($value);
    }
    $this->assertSame($html, $value);
    $this->assertStringContainsString('/vendors/sample', $value);

    // Document the corruption that occurs if replace() is applied to HTML.
    $corrupted = $service->replace($html);
    $this->assertStringContainsString('/organisers/sample', $corrupted);
  }

  /**
   * Postmark plain-text helper produces non-empty multipart fallback text.
   */
  public function testPostmarkHtmlToPlainTextFallback(): void {
    $provider = new \ReflectionClass(\Drupal\myeventlane_messaging\Service\Delivery\PostmarkDeliveryProvider::class);
    $method = $provider->getMethod('htmlToPlainText');
    $method->setAccessible(TRUE);

    $instance = $provider->newInstanceWithoutConstructor();
    $plain = $method->invoke($instance, '<h1>Booking confirmed</h1><p>Booking #1001</p>');
    $this->assertStringContainsString('Booking confirmed', $plain);
    $this->assertStringContainsString('#1001', $plain);
  }

  /**
   * @return array<string, mixed>
   */
  private function loadTemplate(): array {
    $path = dirname(__DIR__, 3) . '/config/install/myeventlane_messaging.template.order_confirmation.yml';
    $data = Yaml::decode((string) file_get_contents($path));
    $this->assertIsArray($data);
    return $data;
  }

  /**
   * @return array<string, mixed>
   */
  private function sampleContext(): array {
    return [
      'first_name' => 'Alex',
      'order_number' => '1001',
      'order_url' => 'https://example.test/my-tickets/order/1001',
      'digital_pass_url' => 'https://example.test/my-tickets/order/1001',
      'order_email' => 'alex@example.test',
      'is_guest' => FALSE,
      'is_paid' => TRUE,
      'event_name' => 'Test Event',
      'event_url' => 'https://example.test/events/1',
      'organiser_name' => 'Sample Organiser',
      'organiser_url' => 'https://example.test/vendors/sample',
      'help_centre_url' => 'https://example.test/help',
      'refund_policy_url' => 'https://example.test/help/policies/refund-policy',
      'support_url' => 'https://example.test/support',
      'booking_total' => '$25.00',
      'total_paid' => '$25.00',
      'show_includes_gst_note' => TRUE,
      'donation_total' => NULL,
      'has_tickets' => TRUE,
      'tickets_need_assignment' => FALSE,
      'apple_wallet_url' => NULL,
      'google_wallet_url' => NULL,
      'apple_wallet_badge_url' => NULL,
      'google_wallet_badge_url' => NULL,
      'pdf_url' => 'https://example.test/ticket/ABC123/pdf',
      'manage_booking_url' => 'https://example.test/my-tickets',
      'events' => [
        [
          'title' => 'Test Event',
          'url' => 'https://example.test/events/1',
          'image_url' => NULL,
          'image_alt' => NULL,
          'start_date' => '14 June 2026',
          'end_date' => NULL,
          'start_time' => '7:00 pm',
          'end_time' => NULL,
          'venue_name' => 'Community Hall',
          'location' => '12 Example St, Melbourne VIC',
          'organiser_name' => 'Sample Organiser',
          'organiser_url' => 'https://example.test/vendors/sample',
        ],
      ],
      'ticket_items' => [
        [
          'title' => 'General Admission',
          'quantity' => 2,
          'price' => '$25.00',
          'attendees' => [
            ['name' => 'Alex Attendee', 'email' => 'alex@example.test'],
          ],
        ],
      ],
    ];
  }

}
