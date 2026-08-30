<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_vendor\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the portfolio dashboard boundary and event-workspace handoff.
 *
 * @group myeventlane_vendor
 */
final class VendorDashboardPortfolioContractTest extends TestCase {

  private string $template;

  private string $builder;

  private string $services;

  private string $styles;

  protected function setUp(): void {
    parent::setUp();
    $webRoot = dirname(__DIR__, 6);
    $path = $webRoot . '/themes/custom/myeventlane_vendor_theme/templates/dashboard/dashboard.html.twig';
    $this->template = (string) file_get_contents($path);
    $this->builder = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_vendor/src/Service/VendorDashboardViewModelBuilder.php',
    );
    $this->services = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_vendor/myeventlane_vendor.services.yml',
    );
    $this->styles = (string) file_get_contents(
      $webRoot . '/themes/custom/myeventlane_vendor_theme/src/scss/pages/_dashboard-live-ops.scss',
    );
  }

  public function testDashboardUsesCanonicalPriorityAndWorkspaceLinks(): void {
    $this->assertStringContainsString('model.priority_action|default(null)', $this->template);
    $this->assertStringContainsString('href="{{ links.manage }}"', $this->template);
    $this->assertStringContainsString("{{ 'Open workspace'|t }}", $this->template);
    $this->assertStringContainsString(
      "'manage' => \$this->safeUrlFromRoute('myeventlane_vendor.console.event_workspace', ['event' => \$nid]",
      $this->builder,
    );
  }

  public function testPortfolioIsAFirstClassDashboardZone(): void {
    $portfolioPosition = strpos($this->template, 'mel-vendor-portfolio__work');
    $outcomePosition = strpos($this->template, 'mel-vendor-portfolio__outcome');

    $this->assertNotFalse($portfolioPosition);
    $this->assertNotFalse($outcomePosition);
    $this->assertLessThan($outcomePosition, $portfolioPosition);
    $this->assertStringNotContainsString("{{ 'Tools & settings'|t }}", $this->template);
  }

  public function testTemplateDoesNotCalculateEventBusinessState(): void {
    $this->assertStringNotContainsString('EventReadinessFacade', $this->template);
    $this->assertStringNotContainsString('PublishEligibilityEvaluator', $this->template);
    $this->assertStringNotContainsString('tickets_sold +', $this->template);
    $this->assertStringNotContainsString('revenue +', $this->template);
  }

  public function testEventStudioWorkspaceRouteRemainsCanonical(): void {
    $webRoot = dirname(__DIR__, 6);
    $routing = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_event_studio/myeventlane_event_studio.routing.yml',
    );

    $this->assertStringContainsString('myeventlane_event_studio.workspace:', $routing);
    $this->assertStringContainsString("path: '/vendor/events/{node}/studio'", $routing);
    $this->assertStringContainsString('EventStudioAccess::access', $routing);
  }

  public function testPortfolioUsesCanonicalLifecycleResolver(): void {
    $this->assertStringContainsString(
      'LifecycleStateResolverInterface $lifecycleStateResolver',
      $this->builder,
    );
    $this->assertStringContainsString(
      '$this->lifecycleStateResolver->resolveState($node)',
      $this->builder,
    );
    $this->assertStringContainsString(
      "'@myeventlane_event_state.resolver'",
      $this->services,
    );
  }

  public function testPortfolioKeepsEveryLifecycleRepresentedAndBounded(): void {
    foreach (['live', 'sold_out', 'scheduled', 'draft', 'ended', 'cancelled', 'archived'] as $state) {
      $this->assertStringContainsString("'$state'", $this->builder);
    }
    $this->assertStringContainsString('MAX_EVENTS_PER_LIFECYCLE', $this->builder);
    $this->assertStringContainsString("'event_portfolio' => [", $this->builder);
    $this->assertStringContainsString('lifecycle_counts', $this->template);
    $this->assertStringContainsString("'Archived'|t", $this->template);
  }

  public function testReadyIsNotInferredFromScheduledState(): void {
    $this->assertStringNotContainsString(
      "'scheduled' => (string) \$this->t('Ready')",
      $this->builder,
    );
  }

  public function testNewOrganiserReceivesOneGuidedCreateAction(): void {
    $this->assertStringContainsString(
      '{% if create_url and not events_empty %}',
      $this->template,
    );
    $this->assertStringContainsString(
      'mel-vendor-portfolio__first-event',
      $this->template,
    );
    $this->assertStringContainsString(
      'MyEventLane will open a dedicated workspace',
      $this->template,
    );
  }

  public function testApprovedDashboardHierarchyIsPresentationOnly(): void {
    $this->assertStringContainsString("grid-template-areas:", $this->styles);
    $this->assertStringContainsString("'identity identity'", $this->styles);
    $this->assertStringContainsString("'guidance outcome'", $this->styles);
    $this->assertStringContainsString("'work outcome'", $this->styles);
    $this->assertStringContainsString('grid-area: work;', $this->styles);
    $this->assertStringContainsString('grid-area: outcome;', $this->styles);

    $this->assertStringContainsString('model.priority_action|default(null)', $this->template);
    $this->assertStringContainsString('model.events|default([])', $this->template);
    $this->assertStringContainsString('model.kpis|default([])', $this->template);
  }

}
