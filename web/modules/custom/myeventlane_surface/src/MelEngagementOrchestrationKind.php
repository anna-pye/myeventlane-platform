<?php

declare(strict_types=1);

namespace Drupal\myeventlane_surface;

/**
 * Classifies intelligence for engagement sequencing (hints only — no messaging).
 */
enum MelEngagementOrchestrationKind: string {

  case None = 'none';

  case OnboardingContinuation = 'onboarding_continuation';

  case ReEngagement = 're_engagement';

  case LifecycleFollowup = 'lifecycle_followup';

  case SupportRecovery = 'support_recovery';

  case CommerceContinuity = 'commerce_continuity';

}
