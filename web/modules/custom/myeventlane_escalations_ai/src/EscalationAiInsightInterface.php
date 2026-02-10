<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations_ai;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;

/**
 * Interface for escalation AI insights.
 */
interface EscalationAiInsightInterface extends ContentEntityInterface, EntityChangedInterface {

}
