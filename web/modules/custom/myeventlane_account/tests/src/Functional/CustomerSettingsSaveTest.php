<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_account\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies customer settings retain Drupal's account form contract and save.
 *
 * @group myeventlane_account
 */
#[RunTestsInSeparateProcesses]
final class CustomerSettingsSaveTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'text',
    'user',
    'myeventlane_account_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    foreach (['field_display_name', 'field_city'] as $field_name) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'user',
        'type' => 'string',
      ])->save();
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'user',
        'bundle' => 'user',
        'label' => $field_name === 'field_display_name' ? 'Display name' : 'City',
      ])->save();
    }

    $this->container->get('entity_display.repository')
      ->getFormDisplay('user', 'user', 'default')
      ->setComponent('field_display_name', ['type' => 'string_textfield'])
      ->setComponent('field_city', ['type' => 'string_textfield'])
      ->save();
  }

  /**
   * Ensures profile and password values persist using top-level account inputs.
   */
  public function testCustomerSettingsSaveAndReload(): void {
    $account = $this->drupalCreateUser();
    $this->drupalLogin($account);

    $this->drupalGet('/user/' . $account->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('mail');
    $this->assertSession()->fieldNotExists('account[mail]');
    $this->assertSession()->fieldExists('name');
    $this->assertSession()->fieldNotExists('account[name]');

    $password = 'MEL-functional-settings-save!';
    $this->submitForm([
      'field_display_name[0][value]' => 'Customer Test',
      'field_city[0][value]' => 'Sydney',
      // Drupal's BrowserTestBase adds this runtime-only test property.
      // @phpstan-ignore-next-line
      'current_pass' => $account->passRaw,
      'pass[pass1]' => $password,
      'pass[pass2]' => $password,
    ], 'Save');

    $this->assertSession()->pageTextContains('The changes have been saved.');

    $this->drupalGet('/user/' . $account->id() . '/edit');
    $this->assertSession()->fieldValueEquals('field_display_name[0][value]', 'Customer Test');
    $this->assertSession()->fieldValueEquals('field_city[0][value]', 'Sydney');

    $saved = $this->container->get('entity_type.manager')->getStorage('user')->loadUnchanged($account->id());
    self::assertInstanceOf(UserInterface::class, $saved);
    self::assertTrue(\Drupal::service('password')->check($password, $saved->getPassword()));

    $this->drupalLogout();
    $this->drupalGet('/user/login');
    $this->submitForm([
      'name' => $account->getAccountName(),
      'pass' => $password,
    ], 'Log in');
    $this->drupalGet('/user/' . $account->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);
  }

}
