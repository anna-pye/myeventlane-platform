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
   * Ensures the settings form and its submit actions render exactly once.
   */
  public function testSettingsTemplateUsesCanonicalFormChildren(): void {
    $template = file_get_contents(dirname(__DIR__, 7) . '/web/themes/custom/myeventlane_theme/templates/form/form--user-profile-form.html.twig');
    self::assertIsString($template);

    self::assertStringContainsString("{% if attributes.hasClass('mel-account-settings-form') %}\n    {{ children }}", $template);
    self::assertSame(2, substr_count($template, '{{ children }}'));
    self::assertStringNotContainsString('{{ element.', $template);
    self::assertStringNotContainsString('{{ element|without', $template);
  }

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

  /**
   * Ensures core account controls retain their original submitted parents.
   */
  public function testNestedAccountFieldsRetainTheirOriginalParents(): void {
    require_once dirname(__DIR__, 3) . '/myeventlane_account.module';

    $form = [
      'mel_settings_profile' => [
        '#type' => 'container',
      ],
      'account' => [
        '#type' => 'container',
        'mail' => [
          '#type' => 'email',
          '#parents' => ['mail'],
        ],
        'name' => [
          '#type' => 'textfield',
          '#parents' => ['name'],
        ],
      ],
    ];

    _myeventlane_account_group_nested_fields(
      $form,
      'account',
      ['mail', 'name', 'missing'],
      'mel_settings_profile',
    );

    $this->assertArrayHasKey('mail', $form['account']);
    $this->assertArrayHasKey('name', $form['account']);
    $this->assertSame(['mail'], $form['account']['mail']['#parents']);
    $this->assertSame(['name'], $form['account']['name']['#parents']);
    $this->assertSame('mel_settings_profile', $form['account']['mail']['#group']);
    $this->assertSame('mel_settings_profile', $form['account']['name']['#group']);
    $this->assertArrayNotHasKey('missing', $form['account']);
  }

}
