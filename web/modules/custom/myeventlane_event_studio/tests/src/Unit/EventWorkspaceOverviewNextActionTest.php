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
    $this->assertSame('Continue setup', $action['action_label']);
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
    $this->assertSame('Publish', $action['action_label']);
    $this->assertSame('publish', $action['mode']);
    $this->assertTrue($action['mirrors_hero']);
    $this->assertNull($action['url']);
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

  public function testLiveBookingActivityReflectsHeroShareCta(): void {
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

    // Hero owns Share; booking ops stay in secondary cards — Focus mirrors Hero.
    $this->assertSame('Share your event', $action['title']);
    $this->assertSame('Share', $action['action_label']);
    $this->assertNotSame('View attendees', $action['action_label']);
  }

  public function testUnknownTypeErrorAlignsCtaWithHeroContinueSetup(): void {
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
    $this->assertSame('Continue setup', $action['action_label']);
    $this->assertNotSame('Edit event', $action['action_label']);
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
    $this->assertSame('Continue setup', $action['action_label']);
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
    $this->assertFalse($action['mirrors_hero']);
    $this->assertSame('connect_stripe', $action['key']);
  }

  public function testAuthoritativePrimaryCtaMatchesMissionControlWhenNotStripe(): void {
    $translator = $this->createMock(TranslationInterface::class);
    $translator->method('translateString')->willReturnCallback(
      static fn (\Drupal\Core\StringTranslation\TranslatableMarkup $markup): string => $markup->getUntranslatedString(),
    );
    $builder = (new ReflectionClass(EventWorkspaceOverviewBuilder::class))
      ->newInstanceWithoutConstructor();
    (new ReflectionClass(EventWorkspaceOverviewBuilder::class))
      ->getProperty('stringTranslation')
      ->setValue($builder, $translator);

    $readiness = EventReadinessResult::create(['Add tickets.']);
    $hero = (new ReflectionClass(EventWorkspaceOverviewBuilder::class))
      ->getMethod('resolveAuthoritativePrimaryCta')
      ->invoke($builder, $readiness, FALSE, 1, '');
    $mc = (new ReflectionClass(EventWorkspaceOverviewBuilder::class))
      ->getMethod('resolveNextRecommendedAction')
      ->invoke($builder, [], $readiness, FALSE, 1, []);

    $this->assertSame($hero['label'], $mc['action_label']);
    $this->assertSame($hero['key'], $mc['key']);
    $this->assertSame($hero['mode'], $mc['mode']);
    $this->assertTrue($mc['mirrors_hero']);
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
   * @return array<string, mixed>
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

    /** @var array<string, mixed> $action */
    $action = $method->invoke($builder, $next, $readiness, $published, 1, $stripe);
    return $action;
  }

}
