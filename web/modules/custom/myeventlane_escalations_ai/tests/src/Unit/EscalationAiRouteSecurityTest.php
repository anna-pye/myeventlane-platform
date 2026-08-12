<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_escalations_ai\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the escalation AI action route security contract.
 */
#[Group('myeventlane_escalations_ai')]
final class EscalationAiRouteSecurityTest extends TestCase {

  /**
   * Tests the method, CSRF, permission and object-access route requirements.
   */
  public function testDraftRoutesRequirePostCsrfAndObjectAccess(): void {
    $root = dirname(__DIR__, 3);
    $routes = [
      Yaml::parseFile($root . '/myeventlane_escalations_ai.routing.yml')['myeventlane_escalations_ai.generate_draft'],
      Yaml::parseFile($root . '/../myeventlane_escalations_ai_draft/myeventlane_escalations_ai_draft.routing.yml')['myeventlane_escalations_ai_draft.generate'],
    ];

    foreach ($routes as $route) {
      $this->assertSame(['POST'], $route['methods'] ?? NULL);
      $this->assertSame('TRUE', $route['requirements']['_csrf_request_header_token'] ?? NULL);
      $this->assertSame('generate escalation ai drafts', $route['requirements']['_permission'] ?? NULL);
      $this->assertNotEmpty($route['requirements']['_custom_access'] ?? NULL);
      $this->assertSame('entity:escalation', $route['options']['parameters']['escalation']['type'] ?? NULL);
    }
  }

}
