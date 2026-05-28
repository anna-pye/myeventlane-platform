<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_tickets\Service\OperationalAssignmentAuditProjector;
use Drupal\myeventlane_tickets\Service\OperationalAssignmentGovernanceManager;
use Drupal\myeventlane_tickets\Service\OperationalOwnershipProjectionBuilder;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\myeventlane_tickets\Service\OperationalAssignmentGovernanceManager
 *
 * @group myeventlane_tickets
 */
#[RunTestsInSeparateProcesses]
final class OperationalAssignmentOwnershipGovernanceKernelTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
    'options',
    'datetime',
    'datetime_range',
    'commerce',
    'commerce_price',
    'commerce_order',
    'commerce_product',
    'entity_reference_revisions',
    'paragraphs',
    'mel_ticket',
    'myeventlane_vendor',
    'myeventlane_tickets',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);

    foreach (array_keys($container->getDefinitions()) as $service_id) {
      if (str_starts_with($service_id, 'myeventlane_vendor.') && $service_id !== 'myeventlane_vendor.event_access_checker') {
        $container->removeDefinition($service_id);
      }
    }

    $container->register('myeventlane_vendor.event_access_checker', EventVendorAccessChecker::class);
    $container->register('myeventlane_onboarding.manager', \stdClass::class);
    $container->register('myeventlane_core.vendor_follow', \stdClass::class);
    $container->register('myeventlane_event_studio.save', \stdClass::class);
    $container->register('myeventlane_legal.gatekeeper', \stdClass::class);
    $container->register('myeventlane_core.domain_detector', \stdClass::class);
    $container->register('myeventlane_analytics.order_item_classifier', \stdClass::class);
    $container->register('myeventlane_core.entity_id_normalizer', \stdClass::class);
    $container->register('myeventlane_boost.manager', \stdClass::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['user']);
  }

  public function testAssignmentRoleNormalization(): void {
    $m = $this->assignmentManager();
    $this->assertSame('unassigned_lane', $m->normalizeAssignmentRoleLabel(''));
    $this->assertSame('lead', $m->normalizeAssignmentRoleLabel('LEAD'));
  }

  public function testOwnershipStateNormalization(): void {
    $m = $this->assignmentManager();
    $this->assertSame('unassigned', $m->normalizeOwnershipState(''));
    $this->assertSame('monitoring', $m->normalizeOwnershipState('monitoring'));
    $this->assertSame('unassigned', $m->normalizeOwnershipState('not-a-state'));
  }

  public function testInvalidHandoffNormalizesToOperational(): void {
    $m = $this->assignmentManager();
    $this->assertSame('operational', $m->normalizeHandoffType(''));
    $this->assertSame('venue', $m->normalizeHandoffType('venue'));
    $this->assertSame('trust_safety', $m->normalizeHandoffType('trust_safety'));
    $this->assertSame('operational', $m->normalizeHandoffType('unknown_lane_xyz'));
  }

  public function testOwnershipProjectionStructure(): void {
    $merged = [
      'issuance' => [
        'worst_quantity_alignment_status' => 'valid',
        'aggregated_issued_ticket_count' => 2,
        'aggregated_expected_quantity' => 2,
      ],
      'recovery' => ['recovery_mismatch_observed' => FALSE],
      'artifacts' => [
        'canonical_pdf_readiness' => 'valid',
        'qr_payload_operational' => 'valid',
        'attachment_continuity' => 'valid',
        'venue_operation_policy' => ['ga' => ['gate_semantics' => ['gate_family' => 'x']]],
      ],
      'guest_continuity' => ['continuity_statuses_observed' => ['valid']],
    ];
    $sections = $this->ownershipBuilder()->buildWorkspaceOwnershipSections($merged, 1_800_000_000);
    $ids = array_column($sections, 'id');
    $this->assertContains('assignment_ownership', $ids);
    $this->assertContains('acknowledgement_ownership', $ids);
    $this->assertContains('escalation_owner_visibility', $ids);
    $this->assertContains('handoff_continuity', $ids);
    $this->assertContains('operational_responsibility', $ids);
    $this->assertContains('ownership_audit_timeline', $ids);
    $blob = json_encode($sections, JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('replay_token', $blob);
    $this->assertStringNotContainsString('device_fingerprint', $blob);
    $this->assertStringNotContainsString('qr_payload', $blob);
  }

  public function testAssignmentAuditContinuityAndEscalationOwnership(): void {
    $audit = $this->assignmentAuditProjector();
    $merged = [];
    $hist = $audit->projectAssignmentHistory($merged, 100);
    $this->assertNotEmpty($hist[0]['summary'] ?? '');
    $m = $this->assignmentManager();
    $map = $m->mapEscalationOwnership('urgent');
    $this->assertStringContainsString('urgent', strtolower($map['escalation_owner_descriptor'] ?? ''));
    $cont = $audit->projectResponsibilityContinuitySummary($merged, 200);
    $this->assertNotEmpty($cont[0]['summary'] ?? '');
  }

  public function testOwnershipTimelineOrdering(): void {
    $audit = $this->assignmentAuditProjector();
    $rows = $audit->projectOperationalOwnershipTimeline([
      ['ownership_state' => 'assigned', 'recorded_at' => 50, 'note' => 'b'],
      ['ownership_state' => 'unassigned', 'recorded_at' => 10, 'note' => 'a'],
      ['ownership_state' => 'acknowledged', 'recorded_at' => 50, 'note' => 'c'],
    ]);
    $this->assertSame(10, $rows[0]['recorded_at_unix']);
    // Same timestamp: secondary sort is lexicographic on ownership_state.
    $this->assertSame('acknowledged', $rows[1]['ownership_state']);
    $this->assertSame('assigned', $rows[2]['ownership_state']);
  }

  public function testMaxOwnershipState(): void {
    $m = $this->assignmentManager();
    $this->assertSame('escalated', $m->maxOwnershipState(['assigned', 'escalated', 'acknowledged']));
  }

  private function assignmentManager(): OperationalAssignmentGovernanceManager {
    return $this->container->get('myeventlane_tickets.operational_assignment_governance_manager');
  }

  private function assignmentAuditProjector(): OperationalAssignmentAuditProjector {
    return $this->container->get('myeventlane_tickets.operational_assignment_audit_projector');
  }

  private function ownershipBuilder(): OperationalOwnershipProjectionBuilder {
    return $this->container->get('myeventlane_tickets.operational_ownership_projection_builder');
  }

}
