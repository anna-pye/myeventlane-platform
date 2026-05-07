<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Canonical observability contract grouping (explainability only).
 */
enum MelObservabilityTraceCategory: string {

  case State = 'state';

  case Workflow = 'workflow';

  case Intelligence = 'intelligence';

  case Policy = 'policy';

  case Experience = 'experience';

  case TelemetryGovernance = 'telemetry_governance';

}
