<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\myeventlane_tickets\Kernel\Traits\RegistersTicketBackedClassifierStubTrait;
use Drupal\myeventlane_tickets\Service\OperationalCoordinationAuditProjector;
use Drupal\myeventlane_tickets\Service\OperationalCoordinationProjectionBuilder;
use Drupal\myeventlane_tickets\Service\OperationalCoordinationStateManager;
use Drupal\myeventlane_tickets\Service\OperationalWorkspaceBuilder;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\myeventlane_tickets\Service\OperationalCoordinationStateManager
 *
 * @group myeventlane_tickets
 */
#[RunTestsInSeparateProcesses]
final class OperationalCoordinationStateConvergenceKernelTest extends KernelTestBase {

  use RegistersTicketBackedClassifierStubTrait;

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
    $this->registerTicketBackedClassifierStub($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['user']);
  }

  public function testCoordinationStateNormalization(): void {
    $m = $this->coordinationStateManager();
    $this->assertSame('nominal', $m->normalizeCoordinationState(''));
    $this->assertSame('recovery', $m->normalizeCoordinationState('recovery'));
    $this->assertSame('nominal', $m->normalizeCoordinationState('not-a-state'));
  }

  public function testOperationalPostureNormalization(): void {
    $m = $this->coordinationStateManager();
    $this->assertSame('standard_operations', $m->normalizeOperationalPosture(''));
    $this->assertSame('recovery_operations', $m->normalizeOperationalPosture('recovery_operations'));
    $this->assertSame('standard_operations', $m->normalizeOperationalPosture('invalid-posture'));
  }

  public function testConfidenceStateNormalization(): void {
    $m = $this->coordinationStateManager();
    $this->assertSame('stable', $m->normalizeConfidenceState(''));
    $this->assertSame('caution', $m->normalizeConfidenceState('caution'));
    $this->assertSame('stable', $m->normalizeConfidenceState('unknown-confidence'));
  }

  public function testSaturationNormalization(): void {
    $m = $this->coordinationStateManager();
    $this->assertSame('clear', $m->normalizeIncidentSaturation(''));
    $this->assertSame('saturated', $m->normalizeIncidentSaturation('saturated'));
    $this->assertSame('clear', $m->normalizeIncidentSaturation('invalid-saturation'));
  }

  public function testRecoveryCoordinationProjections(): void {
    $merged = [
      'issuance' => ['worst_quantity_alignment_status' => 'valid'],
      'recovery' => [
        'recovery_mismatch_observed' => TRUE,
        'completion_states_observed' => ['complete'],
      ],
      'artifacts' => [
        'canonical_pdf_readiness' => 'valid',
        'qr_payload_operational' => 'valid',
        'attachment_continuity' => 'valid',
      ],
      'guest_continuity' => ['continuity_statuses_observed' => ['valid']],
    ];
    $model = $this->coordinationStateManager()->composeOperationalCoordinationReadModel($merged, 1_700_000_000);
    $this->assertSame('recovery', $model['coordination_state']);
    $this->assertStringContainsString('mismatch_observed=yes', (string) $model['recovery_coordination_descriptor']);
  }

  public function testReconciliationReadinessProjections(): void {
    $merged = [
      'issuance' => ['worst_quantity_alignment_status' => 'valid'],
      'recovery' => [
        'recovery_mismatch_observed' => FALSE,
        'completion_states_observed' => ['complete'],
      ],
      'artifacts' => [
        'canonical_pdf_readiness' => 'valid',
        'qr_payload_operational' => 'missing',
        'attachment_continuity' => 'valid',
      ],
      'guest_continuity' => ['continuity_statuses_observed' => ['valid']],
    ];
    $model = $this->coordinationStateManager()->composeOperationalCoordinationReadModel($merged, 1_700_000_000);
    $this->assertSame('reconciliation', $model['coordination_state']);
    $this->assertStringContainsString('qr_operational_readiness=missing', (string) $model['reconciliation_readiness_descriptor']);
  }

  public function testWorkspaceCoordinationRenderingShape(): void {
    $sections = $this->coordinationProjectionBuilder()->buildWorkspaceCoordinationSections([], 1_700_000_000);
    $this->assertNotEmpty($sections);
    $this->assertSame('operational_coordination', $sections[0]['id']);
    $this->assertNotEmpty($sections[0]['cards']);
  }

  public function testCoordinationAuditOrdering(): void {
    $audit = $this->coordinationAuditProjector();
    $sections = $audit->buildWorkspaceCoordinationAuditSections([], 1_700_000_010);
    $timeline = $sections[0]['timeline'] ?? [];
    $this->assertNotEmpty($timeline);
    $prev = -1;
    foreach ($timeline as $row) {
      $at = (int) ($row['recorded_at_unix'] ?? 0);
      $this->assertGreaterThanOrEqual($prev, $at);
      $prev = $at;
    }
  }

  public function testNoReplayLeakageInCoordinationProjections(): void {
    $merged = [
      'replay_token' => 'secret',
      'issuance' => ['worst_quantity_alignment_status' => 'valid'],
      'recovery' => ['recovery_mismatch_observed' => FALSE, 'completion_states_observed' => ['complete']],
      'artifacts' => [
        'canonical_pdf_readiness' => 'valid',
        'qr_payload_operational' => 'valid',
        'attachment_continuity' => 'valid',
      ],
      'guest_continuity' => ['continuity_statuses_observed' => ['valid']],
    ];
    $sections = $this->coordinationProjectionBuilder()->buildWorkspaceCoordinationSections($merged, 1_700_000_000);
    $encoded = json_encode($sections, JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('replay_token', $encoded);
    $this->assertStringNotContainsString('secret', $encoded);
  }

  public function testNoQrPayloadLeakageInProjections(): void {
    $merged = [
      'issuance' => ['worst_quantity_alignment_status' => 'valid'],
      'recovery' => ['recovery_mismatch_observed' => FALSE, 'completion_states_observed' => ['complete']],
      'artifacts' => [
        'canonical_pdf_readiness' => 'valid',
        'qr_payload_operational' => 'valid',
        'attachment_continuity' => 'valid',
      ],
      'guest_continuity' => ['continuity_statuses_observed' => ['valid']],
    ];
    $sections = $this->coordinationProjectionBuilder()->buildWorkspaceCoordinationSections($merged, 1_700_000_000);
    $encoded = json_encode($sections, JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('qr_payload', $encoded);
    $this->assertStringNotContainsString('BEGIN:QR', $encoded);
  }

  public function testNoDeviceFingerprintLeakage(): void {
    $merged = [
      'device_fingerprint' => 'fp-secret',
      'issuance' => ['worst_quantity_alignment_status' => 'valid'],
      'recovery' => ['recovery_mismatch_observed' => FALSE, 'completion_states_observed' => ['complete']],
      'artifacts' => [
        'canonical_pdf_readiness' => 'valid',
        'qr_payload_operational' => 'valid',
        'attachment_continuity' => 'valid',
      ],
      'guest_continuity' => ['continuity_statuses_observed' => ['valid']],
    ];
    $audit = $this->coordinationAuditProjector()->buildWorkspaceCoordinationAuditSections($merged, 1_700_000_000);
    $encoded = json_encode($audit, JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('device_fingerprint', $encoded);
    $this->assertStringNotContainsString('fp-secret', $encoded);
  }

  public function testOperationalPostureTransitions(): void {
    $audit = $this->coordinationAuditProjector();
    $rows = $audit->projectOperationalPostureTransitions([
      [
        'coordination_state' => 'nominal',
        'posture' => 'standard_operations',
        'recorded_at' => 100,
        'note' => 'a',
      ],
      [
        'coordination_state' => 'invalid-coordination',
        'posture' => 'invalid-posture',
        'recorded_at' => 200,
        'note' => 'b',
      ],
    ]);
    $this->assertSame('nominal', $rows[1]['coordination_lane']);
    $this->assertSame('standard_operations', $rows[1]['operational_posture_lane']);
  }

  public function testCoordinationSeverityProjectionComposition(): void {
    $merged = [
      'issuance' => ['worst_quantity_alignment_status' => 'invalid'],
      'recovery' => ['recovery_mismatch_observed' => FALSE, 'completion_states_observed' => ['complete']],
      'artifacts' => [
        'canonical_pdf_readiness' => 'valid',
        'qr_payload_operational' => 'valid',
        'attachment_continuity' => 'valid',
      ],
      'guest_continuity' => ['continuity_statuses_observed' => ['valid']],
    ];
    $model = $this->coordinationStateManager()->composeOperationalCoordinationReadModel($merged, 1_700_000_000);
    $this->assertSame('severe', $model['governed_severity']);
    $this->assertSame('degraded', $model['coordination_state']);
    $this->assertSame('critical', $model['venue_confidence_state']);
  }

  public function testRollupCompositionContinuity(): void {
    $empty = $this->coordinationStateManager()->composeOperationalCoordinationReadModel([], 1_700_000_000);
    $this->assertSame('nominal', $empty['coordination_state']);
    $this->assertSame('standard_operations', $empty['operational_posture']);

    $minimal = [
      'issuance' => ['worst_quantity_alignment_status' => 'valid'],
      'recovery' => ['recovery_mismatch_observed' => FALSE, 'completion_states_observed' => ['complete']],
      'artifacts' => [
        'canonical_pdf_readiness' => 'valid',
        'qr_payload_operational' => 'valid',
        'attachment_continuity' => 'valid',
      ],
      'guest_continuity' => ['continuity_statuses_observed' => ['valid']],
    ];
    $full = $this->coordinationStateManager()->composeOperationalCoordinationReadModel($minimal, 1_700_000_000);
    $this->assertNotEmpty($full['operational_coordination_visibility_descriptor']);
  }

  public function testWorkspaceBuilderExposesCoordinationMetaKey(): void {
    $ref = new \ReflectionClass(OperationalWorkspaceBuilder::class);
    $params = $ref->getConstructor()?->getParameters() ?? [];
    $types = [];
    foreach ($params as $p) {
      $t = $p->getType();
      if ($t instanceof \ReflectionNamedType) {
        $types[] = $t->getName();
      }
    }
    $this->assertContains(OperationalCoordinationProjectionBuilder::class, $types);
    $this->assertContains(OperationalCoordinationAuditProjector::class, $types);
  }

  private function coordinationStateManager(): OperationalCoordinationStateManager {
    return $this->container->get('myeventlane_tickets.operational_coordination_state_manager');
  }

  private function coordinationProjectionBuilder(): OperationalCoordinationProjectionBuilder {
    return $this->container->get('myeventlane_tickets.operational_coordination_projection_builder');
  }

  private function coordinationAuditProjector(): OperationalCoordinationAuditProjector {
    return $this->container->get('myeventlane_tickets.operational_coordination_audit_projector');
  }

}
