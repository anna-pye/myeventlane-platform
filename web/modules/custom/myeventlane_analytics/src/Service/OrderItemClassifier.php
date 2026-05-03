<?php

declare(strict_types=1);

namespace Drupal\myeventlane_analytics\Service;

use Drupal\myeventlane_commerce\Service\OrderItemClassifier as CommerceOrderItemClassifier;

/**
 * Backwards-compatible analytics service alias for Commerce classification.
 */
final class OrderItemClassifier extends CommerceOrderItemClassifier {}
