<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_account\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards customer settings fields against save-breaking reparenting.
 *
 * @group myeventlane_account
 */
final class CustomerSettingsFormGroupingTest extends TestCase {

  /**
   * Ensures visual grouping preserves entity field structure and parents.
   */
  public function testEntityFieldsRemainAtTheirOriginalKeys(): void {
    require_once dirname(__DIR__, 3) . '/myeventlane_account.module';

    $form = [
      'mel_settings_profile' => [
        '#type' => 'container',
      ],
      'field_display_name' => [
        '#type' => 'textfield',
        '#parents' => ['field_display_name'],
      ],
      'field_city' => [
        '#type' => 'textfield',
        '#parents' => ['field_city'],
      ],
    ];

    _myeventlane_account_group_entity_fields(
      $form,
      ['field_display_name', 'field_city', 'field_missing'],
      'mel_settings_profile',
    );

    $this->assertArrayHasKey('field_display_name', $form);
    $this->assertArrayHasKey('field_city', $form);
    $this->assertSame(['field_display_name'], $form['field_display_name']['#parents']);
    $this->assertSame(['field_city'], $form['field_city']['#parents']);
    $this->assertSame('mel_settings_profile', $form['field_display_name']['#group']);
    $this->assertSame('mel_settings_profile', $form['field_city']['#group']);
    $this->assertArrayNotHasKey('field_missing', $form);
  }

}
