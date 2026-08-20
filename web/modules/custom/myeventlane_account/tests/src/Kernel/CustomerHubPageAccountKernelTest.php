<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_account\Kernel;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\myeventlane_surface\MelCustomerRouteCatalog;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Customer hub uses page__account so MELWorkflowSystem regions render once in the shell.
 *
 * @group myeventlane_account
 */
#[RunTestsInSeparateProcesses]
final class CustomerHubPageAccountKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'options',
    'flag',
    'myeventlane_core',
    'myeventlane_surface',
    'myeventlane_account',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('user_role');
    $this->installConfig(['system', 'user']);

    if (!User::load(1) instanceof UserInterface) {
      User::create([
        'name' => '_mel_placeholder_uid1',
        'mail' => 'mel-placeholder@example.com',
        'status' => 1,
      ])->save();
    }
  }

  public function testDashboardAndPastEventsRoutesSuggestPageAccount(): void {
    foreach ([
      'myeventlane_account.dashboard',
      'myeventlane_account.past_events',
      'myeventlane_account.settings',
    ] as $route_name) {
      $path = match ($route_name) {
        'myeventlane_account.dashboard' => '/my-account',
        'myeventlane_account.settings' => '/my-settings/1',
        default => '/my-past-events',
      };
      $this->pushRouteRequest($path, $route_name, new Route($path));

      $suggestions = ['page'];
      $variables = [];
      $this->container->get('module_handler')->alter('theme_suggestions_page', $suggestions, $variables);

      $this->assertContains('page__account', $suggestions, 'Route must use account shell with workflow regions: ' . $route_name);

      $this->popRequest();
    }
  }

  public function testCustomerRouteCatalogMarksHubShellRoutes(): void {
    $this->assertTrue(MelCustomerRouteCatalog::isAccountPageShellRoute('myeventlane_notifications.inbox'));
    $this->assertTrue(MelCustomerRouteCatalog::isCustomerSurface('myeventlane_rsvp.ics_bundle', '/'));
    $this->assertTrue(MelCustomerRouteCatalog::pathMatchesCustomerPrefix('/my-saved-events'));
    $this->assertTrue(MelCustomerRouteCatalog::isAccountPageShellRoute('myeventlane_account.followed_organisers'));
    $this->assertTrue(MelCustomerRouteCatalog::pathMatchesCustomerPrefix('/my-organisers'));
    $this->assertTrue(MelCustomerRouteCatalog::isAccountPageShellRoute('entity.commerce_payment_method.collection'));
    $this->assertTrue(MelCustomerRouteCatalog::isAccountPageShellRoute('entity.commerce_payment_method.add_form'));
    $this->assertTrue(MelCustomerRouteCatalog::isAccountPageShellRoute('entity.commerce_payment_method.edit_form'));
    $this->assertTrue(MelCustomerRouteCatalog::isAccountPageShellRoute('entity.commerce_payment_method.delete_form'));
  }

  public function testPaymentMethodCollectionExposesSecureAddAction(): void {
    $user = User::load(1);
    $this->assertInstanceOf(UserInterface::class, $user);

    $actions = _myeventlane_account_payment_method_header_actions(
      'entity.commerce_payment_method.collection',
      $user,
    );
    $action = $actions['add_payment_method'] ?? NULL;
    $this->assertIsArray($action);
    $this->assertSame('entity.commerce_payment_method.add_form', $action['#url']->getRouteName());
    $this->assertSame(['user' => '1'], $action['#url']->getRouteParameters());
    $this->assertSame([], _myeventlane_account_payment_method_header_actions('myeventlane_account.dashboard', $user));
  }

  public function testPaymentMethodRoutesExplainCheckoutAndProBoundary(): void {
    $role = Role::create(['id' => 'vendor', 'label' => 'Vendor']);
    $role->save();
    $user = User::load(1);
    $this->assertInstanceOf(UserInterface::class, $user);
    $user->addRole('vendor');
    $user->save();

    $notice = _myeventlane_account_payment_method_notice(
      'entity.commerce_payment_method.collection',
      $user,
    );
    $this->assertStringContainsString('general MyEventLane payment account', (string) $notice['message']['#value']);
    $this->assertStringContainsString('do not change the card used for MEL Pro', (string) $notice['message']['#value']);
    $this->assertSame('myeventlane_pro.manage', $notice['pro_link']['#url']->getRouteName());
    $this->assertSame([], _myeventlane_account_payment_method_notice('myeventlane_account.dashboard', $user));
  }

  public function testStripeClientKeyReadinessCheck(): void {
    $this->assertFalse(_myeventlane_account_stripe_gateway_has_client_key([]));
    $this->assertFalse(_myeventlane_account_stripe_gateway_has_client_key(['publishable_key' => '']));
    $this->assertTrue(_myeventlane_account_stripe_gateway_has_client_key(['publishable_key' => 'pk_test_example']));
  }

  public function testNegotiatorExposesWorkflowRegionVariablesOnDashboardRoute(): void {
    $user = User::create([
      'name' => 'customer_dashboard_test',
      'mail' => 'customer_dashboard_test@example.com',
      'status' => 1,
    ]);
    $user->save();

    $this->container->get('current_user')->setAccount($user);

    $this->pushRouteRequest('/my-account', 'myeventlane_account.dashboard', new Route('/my-account'));

    $variables = ['#attached' => []];
    $this->container->get('myeventlane_surface.negotiator')->attachPageMetadata($variables);

    $this->assertArrayHasKey('mel_workflow_region_primary', $variables);
    $this->assertArrayHasKey('mel_workflow_region_progress', $variables);
    $this->assertArrayHasKey('mel_workflow', $variables);

    // Stacked intelligence items + repeated dismiss copy belong off the account shell;
    // the dashboard uses a single account hero instead (SurfaceNegotiator).
    $this->assertArrayHasKey('mel_intelligence_panel', $variables);
    $this->assertNull($variables['mel_intelligence_panel']);

    $this->popRequest();
  }

  private function pushRouteRequest(string $path, string $route_name, Route $route): void {
    $request = Request::create($path);
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $this->container->get('request_stack')->push($request);
  }

  private function popRequest(): void {
    $this->container->get('request_stack')->pop();
  }

}
