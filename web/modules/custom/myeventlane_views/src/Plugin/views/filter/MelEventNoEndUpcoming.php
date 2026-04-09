<?php

declare(strict_types=1);

namespace Drupal\myeventlane_views\Plugin\views\filter;

use Drupal\Component\Datetime\DateTimePlus;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * OR branch: no end datetime + start time still upcoming.
 *
 * Used with field_event_end >= now in filter group 2 (OR) on upcoming_events.
 */
#[ViewsFilter('mel_event_no_end_upcoming')]
final class MelEventNoEndUpcoming extends FilterPluginBase {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly DateFormatterInterface $dateFormatter,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('date.formatter'),
      $container->get('logger.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    $query = $this->query;
    if ($query === NULL) {
      return;
    }

    $start_alias = $query->ensureTable('node__field_event_start');
    $end_alias = $query->ensureTable('node__field_event_end');
    if (!$start_alias || !$end_alias) {
      $this->loggerFactory->get('myeventlane_views')->error('Could not join event date tables for @filter filter.', [
        '@filter' => 'mel_event_no_end_upcoming',
      ]);
      return;
    }

    // Compare "now" to stored datetimes using the same SQL shaping as core's
    // datetime Views filter (see \Drupal\datetime\Plugin\views\filter\Date::opSimple()).
    //
    // For getDateField($x, $string_date = TRUE), drivers pass $x through or wrap it
    // (e.g. PostgreSQL: TO_TIMESTAMP($x, ...)). The value must be a valid SQL
    // expression: a column reference or a quoted literal. An unquoted
    // Y-m-d\TH:i:s string is not valid SQL in DATE_FORMAT/TO_TIMESTAMP and would
    // produce malformed queries; core therefore concatenates single quotes here.
    $timezone = date_default_timezone_get();
    $value = new DateTimePlus('now', new \DateTimeZone($timezone));
    $quoted_storage_now = "'" . $this->dateFormatter->format(
      $value->getTimestamp(),
      'custom',
      DateTimeItemInterface::DATETIME_STORAGE_FORMAT,
      DateTimeItemInterface::STORAGE_TIMEZONE
    ) . "'";

    $value_sql = $query->getDateFormat(
      $query->getDateField($quoted_storage_now, TRUE, TRUE),
      DateTimeItemInterface::DATETIME_STORAGE_FORMAT,
      TRUE
    );
    $start_field = $query->getDateFormat(
      $query->getDateField($start_alias . '.field_event_start_value', TRUE, TRUE),
      DateTimeItemInterface::DATETIME_STORAGE_FORMAT,
      TRUE
    );

    $group = $this->options['group'] ?? 1;
    $expression = sprintf('((%s.field_event_end_value IS NULL OR %s.field_event_end_value = \'\') AND (%s >= %s))', $end_alias, $end_alias, $start_field, $value_sql);
    $query->addWhereExpression($group, $expression);
  }

  /**
   * {@inheritdoc}
   */
  public function adminSummary(): string {
    return $this->t('Empty end date and start >= now');
  }

}
