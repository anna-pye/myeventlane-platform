<?php

declare(strict_types=1);

namespace Drupal\myeventlane_escalations\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\UserInterface;

/**
 * Provides an interface defining an escalation entity type.
 */
interface EscalationInterface extends ContentEntityInterface {

  /**
   * Gets the escalation subject.
   */
  public function getSubject(): string;

  /**
   * Sets the escalation subject.
   */
  public function setSubject(string $subject): EscalationInterface;

  /**
   * Gets the escalation status.
   */
  public function getStatus(): string;

  /**
   * Sets the escalation status.
   */
  public function setStatus(string $status): EscalationInterface;

  /**
   * Gets the escalation priority.
   */
  public function getPriority(): string;

  /**
   * Sets the escalation priority.
   */
  public function setPriority(string $priority): EscalationInterface;

  /**
   * Gets the customer who submitted the escalation.
   */
  public function getCustomer(): ?UserInterface;

  /**
   * Sets the customer who submitted the escalation.
   */
  public function setCustomer(UserInterface $account): EscalationInterface;

}
















