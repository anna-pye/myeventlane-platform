<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\myeventlane_event_studio\DTO\EventReadinessResult;
use Drupal\myeventlane_event_studio\Service\EventWorkspaceOverviewBuilder;
use Drupal\Tests\UnitTestCase;
use ReflectionClass;

/**
 * Overview next-action preference over mission-control placeholders.
 *
 * @group myeventlane_event_studio
 */
final class EventWorkspaceOverviewNextActionTest extends UnitTestCase {

  public function testDraftGenericMissionControlYieldsPublishingChecklistCta(): void {
    $action = $this->resolve(
      [
        'severity' => 'warning',
        'title' => 'Finish and publish your event',
        'message' => 'Review your details and publish when you are ready to go live.',
        'action_label' => 'Continue editing',
        'url' => '/vendor/events/1/edit',
      ],
      EventReadinessResult::create(['Add tickets.']),
      FALSE,
    );

    $this->assertSame('Continue setup', $action['title']);
    $this->assertSame('Review publishing', $action['action_label']);
  }

  public function testDraftReadyGenericMissionControlYieldsPublishCta(): void {
    $action = $this->resolve(
      [
        'severity' => 'warning',
        'title' => 'Finish and publish your event',
        'message' => 'Review your details and publish when you are ready to go live.',
        'action_label' => 'Continue editing',
        'url' => '/vendor/events/1/edit',
      ],
      EventReadinessResult::create([], [], ['Event title added.']),
      FALSE,
    );

    $this->assertSame('Ready when you are', $action['title']);
    $this->assertSame('Go to publishing', $action['action_label']);
  }

  public function testLiveIdleLooksReadyYieldsMarketingCta(): void {
    $action = $this->resolve(
      [
        'severity' => 'success',
        'title' => 'Event looks ready',
        'message' => 'Keep an eye on bookings and attendee activity.',
        'action_label' => NULL,
        'url' => NULL,
      ],
      EventReadinessResult::create([], [], ['Event title added.']),
      TRUE,
    );

    $this->assertSame('Share your event', $action['title']);
    $this->assertSame('Share', $action['action_label']);
  }

  public function testLiveBookingActivityKeepsMissionControlAction(): void {
    $action = $this->resolve(
      [
        'severity' => 'info',
        'title' => 'Manage bookings',
        'message' => 'You have attendee or order activity for this event.',
        'action_label' => 'View attendees',
        'url' => '/vendor/events/1/attendees',
      ],
      EventReadinessResult::create([], [], ['Event title added.']),
      TRUE,
    );

    $this->assertSame('Manage bookings', $action['title']);
    $this->assertSame('View attendees', $action['action_label']);
    $this->assertSame('/vendor/events/1/attendees', $action['url']);
  }

  public function testUnknownTypeErrorKeepsMissionControlActionOnDraft(): void {
    $action = $this->resolve(
      [
        'severity' => 'error',
        'title' => 'Add RSVP or tickets',
        'message' => 'Choose how people register so your event can accept bookings.',
        'action_label' => 'Edit event',
        'url' => '/vendor/events/1/edit',
      ],
      EventReadinessResult::create(['Add tickets.']),
      FALSE,
    );

    $this->assertSame('Add RSVP or tickets', $action['title']);
    $this->assertSame('Edit event', $action['action_label']);
  }

  public function testPublishBlockersBeatStripeConnectRecommendation(): void {
    $action = $this->resolve(
      [
        'severity' => 'warning',
        'title' => 'Finish and publish your event',
        'message' => 'Review your details and publish when you are ready to go live.',
        'action_label' => 'Continue editing',
        'url' => '/vendor/events/1/edit',
      ],
      EventReadinessResult::create(['Add tickets.']),
      FALSE,
      [
        'tone' => 'attention',
        'detail' => 'Connect Stripe to accept payments.',
        'url' => '/vendor/payouts',
      ],
    );

    $this->assertSame('Continue setup', $action['title']);
    $this->assertSame('Review publishing', $action['action_label']);
    $this->assertNotSame('Connect Stripe', $action['title']);
  }

  public function testStripeConnectWinsWhenReadinessReady(): void {
    $action = $this->resolve(
      [
        'severity' => 'success',
        'title' => 'Event looks ready',
        'message' => 'Keep an eye on bookings.',
        'action_label' => NULL,
        'url' => NULL,
      ],
      EventReadinessResult::create([], [], ['Event title added.']),
      FALSE,
      [
        'tone' => 'attention',
        'detail' => 'Connect Stripe to accept payments.',
        'url' => '/vendor/payouts',
      ],
    );

    $this->assertSame('Connect Stripe', $action['title']);
    $this->assertSame('/vendor/payouts', $action['url']);
  }

  public function testAjaxGuideSnapshotReusesStripeAwareBuilders(): void {
    $overview = file_get_contents(dirname(__DIR__, 3) . '/src/Service/EventWorkspaceOverviewBuilder.php');
    $this->assertIsString($overview);
    $this->assertStringContainsString('function buildHomeAjaxGuideSnapshot', $overview);
    $this->assertStringContainsString('buildGuideCardState', $overview);
    $this->assertStringContainsString('buildEventReady(', $overview);
    $this->assertStringContainsString('resolveNextRecommendedAction(', $overview);
    $this->assertStringContainsString('buildStripeHealth(', $overview);
  }

  /**
   * @param array<string, mixed> $next
   *   Mission-control next_action payload.
   * @param array<string, mixed> $stripe
   *   Optional Stripe health payload.
   *
   * @return array{title: string, message: string, action_label: ?string, url: ?string}
   */
  private function resolve(array $next, EventReadinessResult $readiness, bool $published, array $stripe = []): array {
    $translator = $this->createMock(TranslationInterface::class);
    $translator->method('translateString')->willReturnCallback(
      static fn (\Drupal\Core\StringTranslation\TranslatableMarkup $markup): string => $markup->getUntranslatedString(),
    );

    $builder = (new ReflectionClass(EventWorkspaceOverviewBuilder::class))
      ->newInstanceWithoutConstructor();
    $property = (new ReflectionClass(EventWorkspaceOverviewBuilder::class))
      ->getProperty('stringTranslation');
    $property->setValue($builder, $translator);

    $method = (new ReflectionClass(EventWorkspaceOverviewBuilder::class))
      ->getMethod('resolveNextRecommendedAction');

    /** @var array{title: string, message: string, action_label: ?string, url: ?string} $action */
    $action = $method->invoke($builder, $next, $readiness, $published, 1, $stripe);
    return $action;
  }

}
