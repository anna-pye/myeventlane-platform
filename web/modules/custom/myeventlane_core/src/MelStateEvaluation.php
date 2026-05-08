<?php

declare(strict_types=1);

namespace Drupal\myeventlane_core;

/**
 * Result of interpreting a single canonical state against merged signals/facts.
 */
enum MelStateEvaluation: string {

  /**
   * All required positive signals present and no blocking signals tripped.
   */
  case Satisfied = 'satisfied';

  /**
   * Blocking signal fired or required positive signal missing.
   */
  case Unsatisfied = 'unsatisfied';

  /**
   * Insufficient data for interpretation (conservative default).
   */
  case Unknown = 'unknown';

}
