<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Silent logger for unit tests.
 */
final class TestLoggerChannel extends AbstractLogger implements LoggerChannelInterface {

  public function log($level, $message, array $context = []): void {}

  public function setRequestStack(?RequestStack $requestStack = NULL): void {}

  public function setCurrentUser(?AccountInterface $current_user = NULL): void {}

  public function setLoggers(array $loggers): void {}

  public function addLogger(LoggerInterface $logger, $priority = 0): void {}

}
