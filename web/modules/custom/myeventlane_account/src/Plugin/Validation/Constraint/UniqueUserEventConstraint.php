<?php

declare(strict_types=1);

namespace Drupal\myeventlane_account\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Ensures one review per user per event.
 */
#[Constraint(
  id: 'UniqueUserEvent',
  label: new TranslatableMarkup('Unique user per event', [], ['context' => 'Validation'])
)]
class UniqueUserEventConstraint extends SymfonyConstraint {

  /**
   * The violation message.
   *
   * @var string
   */
  public string $message = 'You have already left a review for this event.';

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string {
    return UniqueUserEventConstraintValidator::class;
  }

}
