<?php

declare(strict_types=1);

namespace Drupal\myeventlane_account\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the UniqueUserEvent constraint.
 */
class UniqueUserEventConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs the validator.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $entity, Constraint $constraint): void {
    if (!$constraint instanceof UniqueUserEventConstraint) {
      throw new UnexpectedTypeException($constraint, UniqueUserEventConstraint::class);
    }

    if (!$entity->hasField('uid') || $entity->get('uid')->isEmpty()) {
      return;
    }
    if (!$entity->hasField('event_id') || $entity->get('event_id')->isEmpty()) {
      return;
    }

    $uid = (int) $entity->get('uid')->target_id;
    $eventId = (int) $entity->get('event_id')->target_id;

    $query = $this->entityTypeManager->getStorage('event_review')->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('event_id', $eventId);

    if (!$entity->isNew()) {
      $query->condition('id', $entity->id(), '<>');
    }

    if ($query->count()->execute() > 0) {
      $this->context->addViolation($constraint->message);
    }
  }

}
