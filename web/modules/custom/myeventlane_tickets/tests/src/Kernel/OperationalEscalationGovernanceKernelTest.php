<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_tickets\Service\OperationalEscalationAuditProjector;
use Drupal\myeventlane_tickets\Service\OperationalEscalationPolicyManager;
use Drupal\myeventlane_tickets\Service\OperationalResolutionGovernanceManager;
use Drupal\myeventlane_vendor\Service\EventVendorAccessChecker;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @coversDefaultClass \Drupal\myeventlane_tickets\Service\OperationalEscalationPolicyManager
 *
 * @group myeventlane_tickets
 */
#[RunTestsInSeparateProcesses]
final class OperationalEscalationGovernanceKernelTest extends KernelTestBase {

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

  public function testEscalationNormalization(): void {
    $m = $this->escalationManager();
    $this->assertSame('none', $m->normalizeEscalation(''));
    $this->assertSame('review', $m->normalizeEscalation('review'));
    $this->assertSame('none', $m->normalizeEscalation('unknown_level'));
  }

  public function testSeverityNormalization(): void {
    $m = $this->escalationManager();
    $this->assertSame('low', $m->normalizeSeverity(''));
    $this->assertSame('severe', $m->normalizeSeverity('severe'));
    $this->assertSame('low', $m->normalizeSeverity('not-a-severity'));
  }

  public function testSlaProjectionNormalization(): void {
    $m = $this->escalationManager();
    $sla = $m->slaSemanticsForEscalation('urgent');
    $this->assertSame(1800, $sla['acknowledgement_seconds']);
    $resolution = $this->resolutionManager();
    $timing = $resolution->projectAcknowledgementTiming('severe', 1_700_000_000);
    $this->assertSame(1_700_000_000 + 1800, $timing['acknowledgement_deadline_unix']);
  }

  public function testSeverityRollupProjection(): void {
    $m = $this->escalationManager();
    $merged = [
      'issuance' => ['worst_quantity_alignment_status' => 'invalid'],
      'recovery' => ['recovery_mismatch_observed' => FALSE],
      'artifacts' => [
        'canonical_pdf_readiness' => 'valid',
        'qr_payload_operational' => 'valid',
        'attachment_continuity' => 'valid',
      ],
      'guest_continuity' => ['continuity_statuses_observed' => ['valid']],
    ];
    $this->assertSame('severe', $m->projectSeverityFromOperationalRollup($merged));
  }

  public function testResolutionGovernanceTransitions(): void {
    $r = $this->resolutionManager();
    $this->assertTrue($r->lifecycleTransitionAllowed('monitoring', 'resolved'));
    $this->assertFalse($r->lifecycleTransitionAllowed('unresolved', 'suppressed'));
    $this->assertSame('under_review', $r->projectResolutionStateFromRollup([
      'recovery' => ['recovery_mismatch_observed' => TRUE],
    ]));
  }

  public function testSuppressionGovernance(): void {
    $r = $this->resolutionManager();
    $invalid = $r->validateSuppression([]);
    $this->assertFalse($invalid['valid']);
    $valid = $r->validateSuppression(['reason' => 'Coordinated maintenance window']);
    $this->assertTrue($valid['valid']);
  }

  public function testAuditHistoryProjections(): void {
    $r = $this->resolutionManager();
    $timeline = $r->projectResolutionAuditTimeline([
      ['state' => 'acknowledged', 'recorded_at' => 100, 'note' => 'staff visibility'],
    ]);
    $this->assertSame('acknowledged', $timeline[0]['resolution_state']);
    $this->assertSame(100, $timeline[0]['recorded_at_unix']);
  }

  public function testAuditProjectorNoSecretLeakage(): void {
    $projector = $this->auditProjector();
    $sections = $projector->buildWorkspaceGovernanceSections([], 1_700_000_000);
    $blob = json_encode($sections, JSON_THROW_ON_ERROR);
    $this->assertStringNotContainsString('replay_token', $blob);
    $this->assertStringNotContainsString('qr_payload', $blob);
    $this->assertStringNotContainsString('@', $blob);
  }

  private function escalationManager(): OperationalEscalationPolicyManager {
    return $this->container->get('myeventlane_tickets.operational_escalation_policy_manager');
  }

  private function resolutionManager(): OperationalResolutionGovernanceManager {
    return $this->container->get('myeventlane_tickets.operational_resolution_governance_manager');
  }

  private function auditProjector(): OperationalEscalationAuditProjector {
    return $this->container->get('myeventlane_tickets.operational_escalation_audit_projector');
  }

}
