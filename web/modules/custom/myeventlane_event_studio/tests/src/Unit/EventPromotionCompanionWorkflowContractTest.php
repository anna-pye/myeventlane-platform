<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the selected-event workflow on companion promotion pages.
 *
 * @group myeventlane_event_studio
 */
final class EventPromotionCompanionWorkflowContractTest extends TestCase {

  private function repositoryRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Event message branding accepts the route parameter and provides a return.
   */
  public function testBrandingFormUsesEventRouteParameter(): void {
    $form = file_get_contents($this->repositoryRoot() . '/web/modules/custom/myeventlane_messaging/src/Form/EventBrandOverrideForm.php');
    $this->assertIsString($form);

    $this->assertStringContainsString('?NodeInterface $event = NULL', $form);
    $this->assertStringContainsString('$node = $event;', $form);
    $this->assertStringContainsString('Back to Event Messages', $form);
    $this->assertStringContainsString('myeventlane_event_studio.workspace_messaging', $form);
  }

  /**
   * The composer starts with one explicit preview action.
   */
  public function testComposerDoesNotDuplicatePreviewAction(): void {
    $form = file_get_contents($this->repositoryRoot() . '/web/modules/custom/myeventlane_vendor_comms/src/Form/VendorEventCommsForm.php');
    $this->assertIsString($form);

    $this->assertSame(1, substr_count($form, "'#value' => \$this->t('Preview')"));
    $this->assertStringContainsString("'#submit' => ['::previewSubmit']", $form);
    $this->assertStringContainsString("\$form_state->get('preview') ? 'secondary' : 'primary'", $form);
    $this->assertStringContainsString("\$form_state->set('preview', \$this->buildPreview(", $form);
    $this->assertStringContainsString("\$this->t('Send message')", $form);
    $this->assertStringNotContainsString('private readonly AccountProxyInterface', $form);
    $this->assertStringNotContainsString('private readonly AttendeeRecipientResolver', $form);
    $this->assertStringContainsString('protected AccountProxyInterface $currentUserAccount', $form);
    $this->assertStringContainsString('protected AttendeeRecipientResolver $attendeeRecipientResolver', $form);
  }

  /**
   * Companion forms use the approved cards, accordions and logo upload.
   */
  public function testCompanionFormsUseStudioPresentationContract(): void {
    $composer = file_get_contents($this->repositoryRoot() . '/web/modules/custom/myeventlane_vendor_comms/src/Form/VendorEventCommsForm.php');
    $branding = file_get_contents($this->repositoryRoot() . '/web/modules/custom/myeventlane_messaging/src/Form/EventBrandOverrideForm.php');
    $styles = file_get_contents($this->repositoryRoot() . '/web/themes/custom/myeventlane_vendor_theme/src/scss/pages/_event-promotion-companion.scss');
    $this->assertIsString($composer);
    $this->assertIsString($branding);
    $this->assertIsString($styles);

    $this->assertStringContainsString('mel-event-studio-companion-form--compose', $composer);
    $this->assertStringContainsString("'#type' => 'details'", $composer);
    $this->assertStringContainsString("'#title' => \$this->t('3. Write and preview')", $composer);
    $this->assertStringContainsString('mel-messages-compose__option-grid--types', $composer);
    $this->assertStringContainsString('Nothing sends until you confirm the preview.', $composer);
    $this->assertStringContainsString('mel-messages-compose__confirmation-copy', $composer);

    $this->assertStringContainsString('mel-event-studio-companion-form--branding', $branding);
    $this->assertStringContainsString("'#type' => 'managed_file'", $branding);
    $this->assertStringContainsString("'#upload_location' => 'public://event-message-branding/'", $branding);
    $this->assertStringContainsString('generateAbsoluteString', $branding);
    $this->assertStringNotContainsString('private readonly EntityTypeManagerInterface', $branding);
    $this->assertStringContainsString('protected EntityTypeManagerInterface $entityTypeManager', $branding);

    $this->assertStringContainsString('.mel-event-studio-companion-form__accordion', $styles);
    $this->assertStringContainsString('max-width: $mel-layout-workspace;', $styles);
    $this->assertStringContainsString('.mel-messages-compose__option-grid--types', $styles);
    $this->assertStringContainsString('grid-template-columns: repeat(5, minmax(0, 1fr));', $styles);
  }

  /**
   * Companion pages reuse the canonical Event Studio navigation.
   */
  public function testCompanionPagesUseCanonicalStudioNavigation(): void {
    $theme = file_get_contents($this->repositoryRoot() . '/web/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.theme');
    $sidebar = file_get_contents($this->repositoryRoot() . '/web/themes/custom/myeventlane_vendor_theme/templates/includes/sidebar.html.twig');
    $this->assertIsString($theme);
    $this->assertIsString($sidebar);

    $this->assertStringContainsString('event_workspace_companion_sections', $theme);
    $this->assertStringContainsString("buildNavigation(\n        \$node,\n        \$account,\n        'messaging',", $theme);
    $this->assertStringContainsString('event_workspace_companion_sections', $sidebar);
  }

  /**
   * Display-language rewriting never corrupts posted form identifiers.
   */
  public function testLanguageStylingSkipsFormControlValues(): void {
    $theme = file_get_contents($this->repositoryRoot() . '/web/themes/custom/myeventlane_vendor_theme/myeventlane_vendor_theme.theme');
    $this->assertIsString($theme);

    $this->assertStringContainsString("in_array(\$hook, ['input', 'textarea'], TRUE)", $theme);
  }

}
