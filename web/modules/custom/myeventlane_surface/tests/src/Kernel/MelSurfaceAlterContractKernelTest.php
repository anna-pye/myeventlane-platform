<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_surface\Kernel;

use Drupal\myeventlane_surface\MelObservabilityDiagnosticsAccess;
use Drupal\myeventlane_surface\MelObservabilityVisibilityLevel;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\Routing\Route;

/**
 * Verifies MEL surface alter hooks receive Drupal-safe argument delivery.
 *
 * @group myeventlane_surface
 */
final class MelSurfaceAlterContractKernelTest extends MelSurfaceGovernanceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'flag',
    'mel_kernel_route_fixtures',
    'mel_surface_alter_contract_test',
    'myeventlane_core',
    'myeventlane_surface',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    \Drupal::state()->deleteMultiple([
      'mel_surface_alter_contract_test.observability_merged_keys',
      'mel_surface_alter_contract_test.observability_state_evaluations',
      'mel_surface_alter_contract_test.operational_policy_merged_keys',
      'mel_surface_alter_contract_test.operational_policy_state_evaluations',
    ]);
  }

  public function testOperationalPolicyAlterDeliversStateEvaluationsInMergedSignals(): void {
    $this->loginAnonymous();
    $this->pushRouteRequest('/checkout/review', 'commerce_checkout.review', new Route('/checkout/review'));

    $this->container->get('myeventlane_surface.operational_policy_manager')->buildPageInterpretation();

    $evaluations = \Drupal::state()->get('mel_surface_alter_contract_test.operational_policy_state_evaluations');
    $this->assertIsArray($evaluations);
    $this->assertNotSame([], $evaluations);
    foreach ($evaluations as $state_id => $value) {
      $this->assertIsString($state_id);
      $this->assertIsString($value);
    }

    $merged_keys = \Drupal::state()->get('mel_surface_alter_contract_test.operational_policy_merged_keys');
    $this->assertIsArray($merged_keys);
    $this->assertContains('state_evaluations', $merged_keys);

    $this->popRequest();
  }

  public function testObservabilityAlterDeliversStateEvaluationsInMergedSignals(): void {
    $user = $this->createUserWithDiagnosticsPermission();
    $this->setCurrentUser($user);
    $this->pushRouteRequest('/admin/config', 'system.admin_config', $this->adminRoute('/admin/config'));

    $state = $this->container->get('myeventlane_surface.state_manager')->buildPageInterpretation();
    $workflow = $this->container->get('myeventlane_surface.workflow_manager')->buildPageAttachments();
    $policy = $this->container->get('myeventlane_surface.operational_policy_manager')->buildPageInterpretation();
    $experience = $this->container->get('myeventlane_surface.experience_manager')->buildPageAttachments($policy);
    $intelligence = $this->container->get('myeventlane_surface.intelligence_manager')->buildPagePayload($experience, $policy);

    $payload = $this->container->get('myeventlane_surface.observability_manager')->buildPagePayload(
      $state,
      $workflow,
      $experience,
      $intelligence,
      $policy,
    );

    $this->assertSame(MelObservabilityVisibilityLevel::Diagnostic->value, $payload['visibility_tier_value']);

    $evaluations = \Drupal::state()->get('mel_surface_alter_contract_test.observability_state_evaluations');
    $this->assertIsArray($evaluations);
    $this->assertNotSame([], $evaluations);

    $merged_keys = \Drupal::state()->get('mel_surface_alter_contract_test.observability_merged_keys');
    $this->assertIsArray($merged_keys);
    $this->assertContains('state_evaluations', $merged_keys);

    $this->popRequest();
  }

  private function createUserWithDiagnosticsPermission(): User {
    $rid = 'mel_obs_diag_contract';
    if (!Role::load($rid)) {
      $role = Role::create(['id' => $rid, 'label' => 'MEL observability diagnostics contract test']);
      $role->save();
      user_role_grant_permissions($rid, [MelObservabilityDiagnosticsAccess::PERMISSION]);
    }
    $user = User::create([
      'name' => $this->randomMachineName(),
      'mail' => $this->randomMachineName() . '@example.com',
      'status' => 1,
    ]);
    $user->addRole($rid);
    $user->save();
    return $user;
  }

}
