<?php

declare(strict_types=1);

namespace Drupal\myeventlane_questions\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\commerce_store\Entity\StoreInterface;

/**
 * Interface for VendorQuestion entities.
 */
interface VendorQuestionInterface extends ContentEntityInterface {

  /**
   * Gets the question label.
   *
   * @return string
   *   The question label.
   */
  public function getLabel(): string;

  /**
   * Sets the question label.
   *
   * @param string $label
   *   The question label.
   *
   * @return $this
   */
  public function setLabel(string $label): static;

  /**
   * Gets the question type.
   *
   * @return string
   *   The question type (textfield, select, checkbox, textarea).
   */
  public function getQuestionType(): string;

  /**
   * Sets the question type.
   *
   * @param string $type
   *   The question type.
   *
   * @return $this
   */
  public function setQuestionType(string $type): static;

  /**
   * Gets the question options (for select/checkbox types).
   *
   * @return string
   *   The options, one per line.
   */
  public function getOptions(): string;

  /**
   * Sets the question options.
   *
   * @param string $options
   *   The options, one per line.
   *
   * @return $this
   */
  public function setOptions(string $options): static;

  /**
   * Gets the help text.
   *
   * @return string
   *   The help text.
   */
  public function getHelpText(): string;

  /**
   * Sets the help text.
   *
   * @param string $help_text
   *   The help text.
   *
   * @return $this
   */
  public function setHelpText(string $help_text): static;

  /**
   * Checks if the question is required.
   *
   * @return bool
   *   TRUE if required, FALSE otherwise.
   */
  public function isRequired(): bool;

  /**
   * Sets whether the question is required.
   *
   * @param bool $required
   *   TRUE if required, FALSE otherwise.
   *
   * @return $this
   */
  public function setRequired(bool $required): static;

  /**
   * Gets the store this question belongs to.
   *
   * @return \Drupal\commerce_store\Entity\StoreInterface|null
   *   The store entity, or NULL if not set.
   */
  public function getStore(): ?StoreInterface;

  /**
   * Sets the store this question belongs to.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store entity.
   *
   * @return $this
   */
  public function setStore(StoreInterface $store): static;

  /**
   * Checks if the question is enabled.
   *
   * @return bool
   *   TRUE if enabled, FALSE otherwise.
   */
  public function isEnabled(): bool;

  /**
   * Sets whether the question is enabled.
   *
   * @param bool $enabled
   *   TRUE if enabled, FALSE otherwise.
   *
   * @return $this
   */
  public function setEnabled(bool $enabled): static;

}
