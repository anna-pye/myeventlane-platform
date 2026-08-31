<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_tickets\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects optional boolean choices on the ticket settings form.
 *
 * @group myeventlane_tickets
 */
final class EventTicketSettingsFormContractTest extends TestCase {

  /**
   * Ensures an unchecked setting remains a valid organiser choice.
   */
  public function testBooleanSettingsAreNotRequiredCheckboxes(): void {
    $form = file_get_contents(dirname(__DIR__, 3) . '/src/Form/EventTicketSettingsForm.php');
    $this->assertIsString($form);

    foreach ([
      'show_tickets_left',
      'show_prices_before_tax',
      'enable_unique_answers',
      'status',
    ] as $field) {
      $this->assertStringContainsString("'{$field}'", $form);
    }

    $this->assertStringContainsString("['widget']['value']['#required'] = FALSE", $form);
    $this->assertStringContainsString("unset(\$form[\$optional_boolean_field]['widget']['value']['#attributes']['required'])", $form);
  }

}
