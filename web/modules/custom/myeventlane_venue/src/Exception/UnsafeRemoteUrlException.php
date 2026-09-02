<?php

declare(strict_types=1);

namespace Drupal\myeventlane_venue\Exception;

/**
 * Raised when a remote venue URL is not safe to request.
 */
final class UnsafeRemoteUrlException extends \RuntimeException {}
