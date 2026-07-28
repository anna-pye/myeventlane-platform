<?php

declare(strict_types=1);

namespace Drupal\Tests\myeventlane_event_studio\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the canonical Event Studio hero-crop workflow.
 *
 * @group myeventlane_event_studio
 */
final class EventStudioBrandingCropContractTest extends TestCase {

  public function testBrandingUsesCompatibleRequiredEventHeroCrop(): void {
    $webRoot = dirname(__DIR__, 6);
    $cropSettings = (string) file_get_contents(
      dirname($webRoot) . '/config/sync/image_widget_crop.settings.yml',
    );
    $brandingDisplay = (string) file_get_contents(
      dirname($webRoot) . '/config/sync/core.entity_form_display.node.event.studio_branding.yml',
    );

    self::assertStringContainsString('cdnjs.cloudflare.com/ajax/libs/cropper/4.0.0/cropper.min.js', $cropSettings);
    self::assertStringNotContainsString('cropperjs/1.6.2', $cropSettings);
    self::assertMatchesRegularExpression('/crop_types_required:\\s*\\n\\s*- event_hero/', $brandingDisplay);
  }

  public function testPublicEventAndBookingHeroesUseCanonicalCropStyle(): void {
    $webRoot = dirname(__DIR__, 6);
    $fullDisplay = (string) file_get_contents(
      dirname($webRoot) . '/config/sync/core.entity_view_display.node.event.full.yml',
    );
    $responsiveStyle = (string) file_get_contents(
      dirname($webRoot) . '/config/sync/responsive_image.styles.mel_event_hero.yml',
    );
    $bookController = (string) file_get_contents(
      $webRoot . '/modules/custom/myeventlane_commerce/src/Controller/BookController.php',
    );

    self::assertStringContainsString('responsive_image_style: mel_event_hero', $fullDisplay);
    self::assertStringContainsString('image.style.mel_event_hero_featured', $responsiveStyle);
    self::assertStringContainsString('fallback_image_style: mel_event_hero_featured', $responsiveStyle);
    self::assertStringContainsString("ImageStyle::load('mel_event_hero_featured')", $bookController);
  }

  public function testCropAreaExplainsOneFramingFlowWithoutFocalShortcuts(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $form = (string) file_get_contents($moduleRoot . '/src/Form/EventBrandingForm.php');
    $augmenter = (string) file_get_contents($moduleRoot . '/src/Service/BrandingHeroFocalAugmenter.php');
    $script = (string) file_get_contents($moduleRoot . '/js/mel-branding-hero-tools.js');
    $styles = (string) file_get_contents($moduleRoot . '/css/mel-event-studio-branding.css');

    self::assertStringContainsString('Choose a new or saved landscape image', $form);
    self::assertStringContainsString('Cover image steps', $form);
    self::assertStringContainsString('Saved public hero preview', $form);
    self::assertStringNotContainsString('Focal shortcuts', $form);
    self::assertStringContainsString('Drag the frame to choose what guests will see.', $augmenter);
    self::assertStringContainsString("'#type' => 'hidden'", $augmenter);
    self::assertStringContainsString('formValidateRequiredCrop', $augmenter);
    self::assertStringContainsString('formRetainManagedHeroUpload', $augmenter);
    self::assertStringContainsString('formProcessHeroWidget', $augmenter);
    self::assertStringContainsString('setValueForElement', $augmenter);
    self::assertStringContainsString("str_ends_with(\$name, '_upload_button')", $augmenter);
    self::assertStringContainsString("'#array_parents' => \$guidance_array_parents", $augmenter);
    self::assertStringNotContainsString('$(document).trigger("drupalFocalPointSet");', $script);
    self::assertStringContainsString('.image-data__crop-wrapper .vertical-tabs__menu', $styles);
    self::assertStringContainsString('.mel-es-branding-crop-guidance', $styles);
  }

  public function testBrandingToolsWakeVisibleCropperInStudioLayout(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $script = (string) file_get_contents($moduleRoot . '/js/mel-branding-hero-tools.js');
    $compat = (string) file_get_contents($moduleRoot . '/js/mel-cropper-jquery-compat.js');
    $module = (string) file_get_contents($moduleRoot . '/myeventlane_event_studio.module');

    self::assertStringContainsString('function wakeCropperWhenVisible(root)', $script);
    self::assertStringContainsString('$(image).trigger("visible.iwc")', $script);
    self::assertStringContainsString('wakeCropperWhenVisible(root);', $script);
    self::assertMatchesRegularExpression('/function refreshBrandingHeroObservers\\(root\\)[\\s\\S]*wakeCropperWhenVisible\\(root\\);/', $script);
    self::assertStringContainsString('function repairCropWidgetSemantics(root)', $script);
    self::assertStringContainsString('document.createElement("span")', $script);
    self::assertStringContainsString('label.replaceWith(visualLabel)', $script);
    self::assertStringContainsString('Adjust 16:9 framing', $script);
    self::assertStringContainsString('!activeFid || activeFid === settingsFid', $script);
    self::assertStringContainsString('typeof $.isFunction', $compat);
    self::assertStringContainsString('myeventlane_event_studio/mel_cropper_jquery_compat', $module);
  }

  public function testBrandingOffersSavedMediaSaveActionAndProColourGuidance(): void {
    $repositoryRoot = dirname(__DIR__, 7);
    $moduleRoot = dirname(__DIR__, 3);
    $form = (string) file_get_contents($moduleRoot . '/src/Form/EventBrandingForm.php');
    $augmenter = (string) file_get_contents($moduleRoot . '/src/Service/BrandingHeroFocalAugmenter.php');
    $saveService = (string) file_get_contents($moduleRoot . '/src/Service/EventStudioSaveService.php');
    $section = (string) file_get_contents($moduleRoot . '/src/Plugin/EventStudioSection/BrandingSection.php');
    $formDisplay = (string) file_get_contents(
      $repositoryRoot . '/config/sync/core.entity_form_display.node.event.studio_branding.yml',
    );

    self::assertStringContainsString("title: 'Branding'", $section);
    self::assertStringContainsString('supports_autosave: FALSE', $section);
    self::assertStringContainsString('field_mel_event_cover_media:', $formDisplay);
    self::assertStringContainsString('type: media_library_widget', $formDisplay);
    self::assertStringContainsString('Choose from saved images', $form);
    self::assertStringContainsString('Use and frame image', $form);
    self::assertStringContainsString("'#validate' => []", $form);
    self::assertStringContainsString("'#submit' => ['::applySavedCoverImage']", $form);
    self::assertStringContainsString('isBrandingHeroManagedFileAction', $form);
    self::assertStringContainsString('if ($managed_file_action || $nid < 1)', $form);
    self::assertStringContainsString('applySubmittedHeroOverlay', $form);
    self::assertStringContainsString('$this->applySubmittedHeroOverlay($formNode, $form_state);', $form);
    self::assertStringContainsString("'callback' => '::refreshBrandingAfterSavedCover'", $form);
    self::assertStringContainsString('Save changes', $form);
    self::assertStringContainsString("'autocomplete' => 'off'", $form);
    self::assertStringContainsString("'#submit' => ['::submitContinue']", $form);
    self::assertStringContainsString('MEL Pro active', $form);
    self::assertStringContainsString('Choose page colours', $form);
    self::assertStringContainsString('saveBrandingCoverMediaField(', $saveService);
    self::assertStringContainsString('$this->attachBrandingHeroPreviewSettings($element, $node, []);', $augmenter);
    self::assertStringContainsString('$image->isValid()', $saveService);
  }

}
