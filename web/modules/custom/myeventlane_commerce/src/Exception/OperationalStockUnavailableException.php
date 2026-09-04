<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Exception;

/**
 * Raised when an operational add-on cannot be reserved safely.
 */
final class OperationalStockUnavailableException extends \RuntimeException {}
