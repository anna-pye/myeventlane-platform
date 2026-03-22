<?php

declare(strict_types=1);

namespace Drupal\myeventlane_pro\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Canonical copy for Pro billing / dunning lifecycle messages.
 *
 * Email HTML continues to use messaging templates; this service keeps
 * subject/body text aligned for reuse in logs, tests, or future channels.
 */
final class ProMessageBuilder {

  use StringTranslationTrait;

  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  public function paymentFailedSubject(): \Drupal\Core\StringTranslation\TranslatableMarkup {
    return $this->t('Your MEL Pro payment needs attention');
  }

  public function paymentFailedBodyPlain(int $graceDays = 7): \Drupal\Core\StringTranslation\TranslatableMarkup {
    return $this->t("Hi,\n\nWe couldn’t process your latest payment.\n\nYou have a @days-day grace period to update your payment method.\n\nThanks,\nMyEventLane", ['@days' => (string) $graceDays]);
  }

  public function graceReminderSubject(): \Drupal\Core\StringTranslation\TranslatableMarkup {
    return $this->t('Reminder: update your payment details');
  }

  public function graceReminderBodyPlain(): \Drupal\Core\StringTranslation\TranslatableMarkup {
    return $this->t('Your MEL Pro subscription is still active, but we need an updated payment method.');
  }

  public function finalWarningSubject(): \Drupal\Core\StringTranslation\TranslatableMarkup {
    return $this->t('Final notice: your MEL Pro access is ending');
  }

  public function finalWarningBodyPlain(): \Drupal\Core\StringTranslation\TranslatableMarkup {
    return $this->t('Your Pro access will end soon if payment isn’t updated.');
  }

}
