<?php

declare(strict_types=1);

namespace Drupal\myeventlane_questions\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\myeventlane_questions\Entity\VendorQuestionInterface;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Service to clone VendorQuestion templates into Paragraph entities.
 */
class QuestionTemplateCloner {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a QuestionTemplateCloner.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Clones a VendorQuestion template into a new Paragraph entity.
   *
   * @param \Drupal\myeventlane_questions\Entity\VendorQuestionInterface $template
   *   The vendor question template to clone.
   *
   * @return \Drupal\paragraphs\ParagraphInterface
   *   A new paragraph entity with the question data.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   If the paragraph cannot be created.
   */
  public function cloneToParagraph(VendorQuestionInterface $template): ParagraphInterface {
    $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');

    /** @var \Drupal\paragraphs\ParagraphInterface $paragraph */
    $paragraph = $paragraph_storage->create([
      'type' => 'attendee_extra_field',
    ]);

    // Copy label.
    if ($paragraph->hasField('field_question_label')) {
      $paragraph->set('field_question_label', $template->getLabel());
    }

    // Copy question type.
    if ($paragraph->hasField('field_question_type')) {
      $paragraph->set('field_question_type', $template->getQuestionType());
    }

    // Copy options.
    if ($paragraph->hasField('field_question_options')) {
      $paragraph->set('field_question_options', $template->getOptions());
    }

    // Copy help text.
    if ($paragraph->hasField('field_question_help')) {
      $paragraph->set('field_question_help', $template->getHelpText());
    }

    // Copy required flag.
    if ($paragraph->hasField('field_question_required')) {
      $paragraph->set('field_question_required', $template->isRequired());
    }

    // Generate machine name if field exists.
    if ($paragraph->hasField('field_question_machine_name')) {
      $machine_name = strtolower(preg_replace('/[^a-z0-9_]+/', '_', $template->getLabel()));
      $machine_name = preg_replace('/_+/', '_', $machine_name);
      $machine_name = trim($machine_name, '_');
      $paragraph->set('field_question_machine_name', $machine_name);
    }

    // Save the paragraph.
    $paragraph->save();

    return $paragraph;
  }

}
