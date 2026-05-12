<?php

declare(strict_types=1);

namespace Drupal\myeventlane_event_studio\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Canonical registry for attendee question field types.
 *
 * This registry owns type identity and UI/rendering capabilities only. It does
 * not own applicability, archived filtering, ticket matching, or answer storage.
 */
final class QuestionFieldTypeRegistry {

  use StringTranslationTrait;

  public const TYPE_TEXTFIELD = 'textfield';
  public const TYPE_TEXTAREA = 'textarea';
  public const TYPE_SELECT = 'select';
  public const TYPE_CHECKBOXES = 'checkboxes';
  public const TYPE_RADIOS = 'radios';
  public const TYPE_EMAIL = 'email';
  public const TYPE_TEL = 'tel';
  public const TYPE_NUMBER = 'number';

  /**
   * @var array<string, string>
   */
  private const ALIASES = [
    'text' => self::TYPE_TEXTFIELD,
    'textfield' => self::TYPE_TEXTFIELD,
    'textarea' => self::TYPE_TEXTAREA,
    'select' => self::TYPE_SELECT,
    'checkbox' => self::TYPE_CHECKBOXES,
    'checkboxes' => self::TYPE_CHECKBOXES,
    'radio' => self::TYPE_RADIOS,
    'radios' => self::TYPE_RADIOS,
    'email' => self::TYPE_EMAIL,
    'tel' => self::TYPE_TEL,
    'number' => self::TYPE_NUMBER,
  ];

  /**
   * @var list<string>
   */
  private const CANONICAL_TYPES = [
    self::TYPE_TEXTFIELD,
    self::TYPE_TEXTAREA,
    self::TYPE_SELECT,
    self::TYPE_CHECKBOXES,
    self::TYPE_RADIOS,
    self::TYPE_EMAIL,
    self::TYPE_TEL,
    self::TYPE_NUMBER,
  ];

  /**
   * @var list<string>
   */
  private const OPTION_TYPES = [
    self::TYPE_SELECT,
    self::TYPE_CHECKBOXES,
    self::TYPE_RADIOS,
  ];

  /**
   * Normalizes aliases to stable stored values.
   */
  public function normalize(string $type): string {
    $type = trim($type);
    return self::ALIASES[$type] ?? self::TYPE_TEXTFIELD;
  }

  public function isSupported(string $type): bool {
    return in_array($this->normalize($type), self::CANONICAL_TYPES, TRUE);
  }

  public function isRenderable(string $type): bool {
    return $this->isSupported($type);
  }

  public function isLegacy(string $type): bool {
    return $this->normalize($type) === self::TYPE_TEL;
  }

  public function supportsValidation(string $type): bool {
    return in_array($this->normalize($type), [
      self::TYPE_EMAIL,
      self::TYPE_NUMBER,
      self::TYPE_SELECT,
      self::TYPE_CHECKBOXES,
      self::TYPE_RADIOS,
    ], TRUE);
  }

  public function requiresOptions(string $type): bool {
    return in_array($this->normalize($type), self::OPTION_TYPES, TRUE);
  }

  public function label(string $type): string {
    return match ($this->normalize($type)) {
      self::TYPE_TEXTAREA => (string) $this->t('Long text'),
      self::TYPE_SELECT => (string) $this->t('Select'),
      self::TYPE_CHECKBOXES => (string) $this->t('Checkbox'),
      self::TYPE_RADIOS => (string) $this->t('Radio'),
      self::TYPE_EMAIL => (string) $this->t('Email'),
      self::TYPE_TEL => (string) $this->t('Phone'),
      self::TYPE_NUMBER => (string) $this->t('Number'),
      default => (string) $this->t('Text'),
    };
  }

  /**
   * Studio UI options. Legacy-compatible types are hidden unless requested.
   *
   * @return array<string, string>
   */
  public function options(bool $includeLegacy = FALSE): array {
    $options = [];
    foreach (self::CANONICAL_TYPES as $type) {
      if (!$includeLegacy && $this->isLegacy($type)) {
        continue;
      }
      $options[$type] = $this->label($type);
    }
    return $options;
  }

  /**
   * @return list<string>
   */
  public function optionTypes(): array {
    return self::OPTION_TYPES;
  }

}
