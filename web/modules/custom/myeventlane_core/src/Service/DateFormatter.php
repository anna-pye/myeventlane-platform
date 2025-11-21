<?php
namespace Drupal\myeventlane_core\Service;

use Drupal\Core\Datetime\TimeInterface;
use Drupal\Core\Language\LanguageManagerInterface;

final class DateFormatter {
  public function __construct(
    private TimeInterface $time,
    private LanguageManagerInterface $lang
  ) {}
}
