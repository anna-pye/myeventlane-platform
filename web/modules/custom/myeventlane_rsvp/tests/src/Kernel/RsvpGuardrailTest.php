<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_rsvp\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_rsvp\Controller\RsvpCancelController;
use Drupal\myeventlane_rsvp\Form\RsvpPublicForm;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Guardrail tests for RSVP invariants (no UI copy assertions).
 *
 * @group myeventlane_rsvp
 */
#[RunTestsInSeparateProcesses]
final class RsvpGuardrailTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Enable the RSVP module; dependencies are installed automatically.
   */
  protected static $modules = [
    // Core.
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    // Dependencies used by MyEventLane schema + RSVP.
    'views',
    'datetime',
    'options',
    'link',
    'path',
    'file',
    'image',
    'taxonomy',
    // Contrib dependencies (pulled in by myeventlane_schema).
    'address',
    'paragraphs',
    'entity_reference_revisions',
    'profile',
    'commerce',
    'commerce_price',
    'commerce_product',
    'commerce_store',
    'commerce_order',
    'commerce_cart',
    // Module under test.
    'myeventlane_rsvp',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Core schemas needed by our tests.
    // Note: DatabaseQueue creates its table on demand; no schema install needed.
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('rsvp_submission');

    // Ensure an 'event' node type exists for RSVP submissions.
    if (!NodeType::load('event')) {
      NodeType::create([
        'type' => 'event',
        'name' => 'Event',
      ])->save();
    }
  }

  /**
   * RSVP submit flow must create a submission, notify, then set a redirect.
   */
  public function testRsvpSubmitNotifyRedirectSequenceIntegrity(): void {
    $event = Node::create([
      'type' => 'event',
      'title' => 'Test Event',
      'status' => 1,
    ]);
    $event->save();

    // Spy mailer: ensure "notify" is attempted without asserting content.
    $spy = new class() {
      public int $calls = 0;
      public mixed $lastSubmission = NULL;
      public mixed $lastEvent = NULL;

      public function sendConfirmation($submission, $event = NULL): void {
        $this->calls++;
        $this->lastSubmission = $submission;
        $this->lastEvent = $event;
      }
    };

    $this->container->set('myeventlane_rsvp.mailer', $spy);

    $form = RsvpPublicForm::create($this->container);

    $form_state = new FormState();
    $form_state->setValues([
      'event_id' => $event->id(),
      'name' => 'Test Attendee',
      'email' => 'attendee@example.test',
      'phone' => '',
      'guests' => 1,
      // Donation controls (explicitly disabled).
      'donation_toggle' => 0,
      'donation_preset' => '',
      'donation_custom' => '',
      'donation' => 0,
    ]);
    $form_state->set('donation_amount', 0);

    // Build the form (ensures the form can be constructed without exceptions).
    $form_array = $form->buildForm([], $form_state, $event);
    $this->assertIsArray($form_array);

    // Validate + submit (sequence under test).
    $form->validateForm($form_array, $form_state);
    $form->submitForm($form_array, $form_state);

    // Submission created.
    $storage = $this->container->get('entity_type.manager')->getStorage('rsvp_submission');
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    $this->assertCount(1, $ids);

    // Notify attempted.
    $this->assertSame(1, $spy->calls);

    // Redirect set (route name, not UI copy).
    $redirect = $form_state->getRedirect();
    $this->assertInstanceOf(Url::class, $redirect);
    $this->assertSame('myeventlane_rsvp.thankyou', $redirect->getRouteName());
    $this->assertSame(['event' => $event->id()], $redirect->getRouteParameters());
  }

  /**
   * RSVP cancel flow must queue a notification and redirect.
   */
  public function testRsvpCancelNotifyRedirectSequenceIntegrity(): void {
    $queue = $this->container->get('queue')->get('mel_rsvp_vendor_digest');
    $this->assertSame(0, $queue->numberOfItems());

    $controller = RsvpCancelController::create($this->container);
    $response = $controller->cancel(123);

    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertSame(1, $queue->numberOfItems());

    $item = $queue->claimItem();
    $this->assertNotNull($item);
    $this->assertIsObject($item);
    $this->assertIsArray($item->data);
    $this->assertSame('rsvp_cancelled', $item->data['action'] ?? NULL);
    $this->assertSame(123, $item->data['id'] ?? NULL);
    $queue->deleteItem($item);

    // Redirect target is front (no copy assertion).
    $this->assertSame(Url::fromRoute('<front>')->toString(), $response->getTargetUrl());
  }

  /**
   * RSVP attendee-facing templates must render without exceptions.
   *
   * This guards template wiring (Twig syntax + expected variable access) without
   * pinning any exact phrasing.
   */
  public function testRsvpTemplatesRenderWithoutExceptions(): void {
    $twig = $this->container->get('twig');
    $modulePath = $this->container->get('extension.list.module')->getPath('myeventlane_rsvp');

    $templates = [
      // Attendee-facing.
      $modulePath . '/templates/email-rsvp-confirmation.html.twig',
      $modulePath . '/templates/email-rsvp-cancel.html.twig',
      $modulePath . '/templates/sms/rsvp-confirm-sms.txt.twig',
      $modulePath . '/templates/email-rsvp-waitlist.html.twig',
      $modulePath . '/templates/email-rsvp-promotion.html.twig',
    ];

    $context = [
      'event_title' => 'Test Event',
      'event_date' => 'Jan 1, 2026',
      'venue' => 'Test Venue',
      'url' => 'https://example.test/events/1',
      'event' => [
        'label' => 'Test Event',
        'field_event_start' => ['value' => '2026-01-01T10:00:00'],
      ],
    ];

    foreach ($templates as $path) {
      $tpl = (string) file_get_contents($path);
      $rendered = (string) $twig->createTemplate($tpl)->render($context);
      $this->assertIsString($rendered);
      $this->assertNotSame('', trim($rendered), "Rendered output should not be empty for {$path}");
    }
  }

  /**
   * Forbidden pressure-phrases must not appear in RSVP attendee-facing output.
   */
  public function testForbiddenPhrasesNotPresentInRsvpRenderedOutput(): void {
    $forbidden = [
      'secure checkout',
      'tickets are waiting',
    ];

    $twig = $this->container->get('twig');
    $modulePath = $this->container->get('extension.list.module')->getPath('myeventlane_rsvp');

    $templates = [
      $modulePath . '/templates/email-rsvp-confirmation.html.twig',
      $modulePath . '/templates/email-rsvp-cancel.html.twig',
      $modulePath . '/templates/sms/rsvp-confirm-sms.txt.twig',
    ];

    $context = [
      'event_title' => 'Test Event',
      'event_date' => 'Jan 1, 2026',
      'venue' => 'Test Venue',
      'url' => 'https://example.test/events/1',
      'event' => [
        'label' => 'Test Event',
        'field_event_start' => ['value' => '2026-01-01T10:00:00'],
      ],
    ];

    foreach ($templates as $path) {
      $tpl = (string) file_get_contents($path);
      $rendered = (string) $twig->createTemplate($tpl)->render($context);
      $lower = mb_strtolower($rendered);
      foreach ($forbidden as $phrase) {
        $this->assertStringNotContainsString($phrase, $lower, "Forbidden phrase '{$phrase}' must not appear for {$path}");
      }
    }
  }

}

