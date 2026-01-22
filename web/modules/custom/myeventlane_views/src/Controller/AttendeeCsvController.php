<?php

declare(strict_types=1);

namespace Drupal\myeventlane_views\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\myeventlane_core\Service\OptionalServiceResolver;
use Drupal\paragraphs\ParagraphInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides an attendee CSV export response.
 *
 * Loads attendee_answer paragraphs via entity storage and applies
 * entity access checks to ensure users can only export data they
 * are entitled to view.
 */
final class AttendeeCsvController extends ControllerBase {

  /**
   * The logger channel factory for myeventlane_views.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $viewsLoggerFactory;

  /**
   * The optional service resolver.
   *
   * @var \Drupal\myeventlane_core\Service\OptionalServiceResolver
   */
  protected OptionalServiceResolver $optionalServiceResolver;

  /**
   * Constructs AttendeeCsvController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $viewsLoggerFactory
   *   The logger channel factory.
   * @param \Drupal\myeventlane_core\Service\OptionalServiceResolver $optionalServiceResolver
   *   The optional service resolver.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    AccountProxyInterface $currentUser,
    LoggerChannelFactoryInterface $viewsLoggerFactory,
    OptionalServiceResolver $optionalServiceResolver,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
    $this->viewsLoggerFactory = $viewsLoggerFactory;
    $this->optionalServiceResolver = $optionalServiceResolver;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('logger.factory'),
      $container->get('myeventlane_core.optional_service_resolver'),
    );
  }

  /**
   * Builds a CSV response for attendee answers.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return \Symfony\Component\HttpFoundation\Response|array
   *   A CSV download response when requested, or a rendered view array.
   */
  public function handle(Request $request) {
    $download_title = $request->query->get('download_csv');

    $this->viewsLoggerFactory->get('myeventlane_views')->notice('Exporting CSV for event: @event', ['@event' => $download_title]);

    if ($download_title) {
      // Load paragraphs via entity storage (not Views).
      $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
      $access_handler = $this->entityTypeManager
        ->getAccessControlHandler('paragraph');

      // Query attendee_answer paragraphs.
      // Note: We load entities directly and check access manually.
      // We use accessCheck(FALSE) to load all, then filter by access.
      $query = $paragraph_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'attendee_answer')
        ->condition('status', 1);

      // If event ID is provided, filter by event via order items.
      // This is a simplified approach - in production, you might want
      // to join through order items to filter by event.
      // For now, we load all accessible paragraphs and filter in memory.
      $paragraph_ids = $query->execute();

      $rows = [];
      $rows[] = [
        'First name', 'Last name', 'Email', 'Phone',
        'Question', 'Answer', 'Checked in', 'Checked in time',
      ];

      $access_resolver = $this->optionalServiceResolver->get(
        'myeventlane_checkout_paragraph.access_resolver'
      );

      foreach ($paragraph_ids as $paragraph_id) {
        $paragraph = $paragraph_storage->load($paragraph_id);
        if (!$paragraph instanceof ParagraphInterface || $paragraph->bundle() !== 'attendee_answer') {
          continue;
        }

        // Check entity access - this enforces vendor/customer access rules.
        $access = $access_handler->access($paragraph, 'view', $this->currentUser);
        if (!$access) {
          // User cannot view this paragraph, skip it.
          continue;
        }

        // If event filter is provided, verify paragraph belongs to that event.
        if ($download_title && $access_resolver) {
          $event = $access_resolver->getEvent($paragraph);
          if (!$event || (string) $event->id() !== (string) $download_title) {
            continue;
          }
        }

        // Extract attendee data.
        $first_name = $paragraph->hasField('field_first_name') && !$paragraph->get('field_first_name')->isEmpty()
          ? $paragraph->get('field_first_name')->value : '';
        $last_name = $paragraph->hasField('field_last_name') && !$paragraph->get('field_last_name')->isEmpty()
          ? $paragraph->get('field_last_name')->value : '';
        $email = $paragraph->hasField('field_email') && !$paragraph->get('field_email')->isEmpty()
          ? $paragraph->get('field_email')->value : '';
        $phone = $paragraph->hasField('field_phone') && !$paragraph->get('field_phone')->isEmpty()
          ? $paragraph->get('field_phone')->value : '';

        // Extract check-in data.
        $checked_in = 'No';
        $checked_in_time = '';
        if ($paragraph->hasField('field_checked_in') && !$paragraph->get('field_checked_in')->isEmpty()) {
          $checked_in_value = (bool) $paragraph->get('field_checked_in')->value;
          $checked_in = $checked_in_value ? 'Yes' : 'No';

          if ($checked_in_value && $paragraph->hasField('field_checked_in_timestamp') && !$paragraph->get('field_checked_in_timestamp')->isEmpty()) {
            $timestamp = (int) $paragraph->get('field_checked_in_timestamp')->value;
            $checked_in_time = date('Y-m-d H:i:s', $timestamp);
          }
        }

        // Extract extra questions.
        $questions = [];
        if ($paragraph->hasField('field_attendee_questions') && !$paragraph->get('field_attendee_questions')->isEmpty()) {
          $question_paragraphs = $paragraph->get('field_attendee_questions')->referencedEntities();
          foreach ($question_paragraphs as $q_para) {
            $q_label = $q_para->hasField('field_question_label') && !$q_para->get('field_question_label')->isEmpty()
              ? $q_para->get('field_question_label')->value : '';
            $q_answer = $q_para->hasField('field_attendee_extra_field') && !$q_para->get('field_attendee_extra_field')->isEmpty()
              ? $q_para->get('field_attendee_extra_field')->value : '';

            if ($q_label || $q_answer) {
              $questions[] = [
                'label' => $q_label,
                'answer' => $q_answer,
              ];
            }
          }
        }

        // Add rows: one per question, or one row if no questions.
        if (empty($questions)) {
          $rows[] = [
            $first_name, $last_name, $email, $phone,
            '', '', $checked_in, $checked_in_time,
          ];
        }
        else {
          foreach ($questions as $q) {
            $rows[] = [
              $first_name, $last_name, $email, $phone,
              $q['label'], $q['answer'], $checked_in, $checked_in_time,
            ];
          }
        }
      }

      $filename = 'attendees-' . preg_replace('/[^a-z0-9]/i', '_', $download_title) . '-' . date('Ymd-His') . '.csv';
      $csv = fopen('php://temp', 'r+');
      foreach ($rows as $fields) {
        fputcsv($csv, $fields);
      }
      rewind($csv);
      $content = stream_get_contents($csv);
      fclose($csv);

      $disposition = 'attachment; filename="' . $filename . '"';
      return new Response(
        $content,
        200,
        [
          'Content-Type' => 'text/csv',
          'Content-Disposition' => $disposition,
        ]
      );
    }

    // Fallback: render the normal View (for non-CSV requests).
    return views_embed_view('attendee_answer', 'page_1');
  }

}
