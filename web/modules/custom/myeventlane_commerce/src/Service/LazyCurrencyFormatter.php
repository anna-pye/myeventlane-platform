<?php

declare(strict_types=1);

namespace Drupal\myeventlane_commerce\Service;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;

/**
 * Lazy proxy for the currency formatter to break circular dependency.
 *
 * Commerce's AdjustmentItemNormalizer injects commerce_price.currency_formatter.
 * That creates a circular dependency: currency_formatter → serialization.exception
 * → serializer → AdjustmentItemNormalizer → currency_formatter.
 *
 * This class wraps the real formatter in a closure, so it is only resolved
 * when format() or parse() is actually called.
 *
 * @internal Used only to resolve the circular dependency.
 */
final class LazyCurrencyFormatter implements CurrencyFormatterInterface {

  private ?CurrencyFormatterInterface $formatter = NULL;

  /**
   * Constructs a new LazyCurrencyFormatter.
   *
   * @param \Closure $factory
   *   Closure that returns the real CurrencyFormatterInterface when invoked.
   */
  public function __construct(
    private readonly \Closure $factory,
  ) {}

  /**
   * Resolves and returns the real currency formatter.
   */
  private function getFormatter(): CurrencyFormatterInterface {
    if ($this->formatter === NULL) {
      $formatter = ($this->factory)();
      assert($formatter instanceof CurrencyFormatterInterface);
      $this->formatter = $formatter;
    }
    return $this->formatter;
  }

  /**
   * {@inheritdoc}
   */
  public function format(string $number, string $currencyCode, array $options = []): string {
    return $this->getFormatter()->format($number, $currencyCode, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function parse(string $number, string $currencyCode, array $options = []): string|bool {
    return $this->getFormatter()->parse($number, $currencyCode, $options);
  }

}
