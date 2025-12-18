<?php

namespace Drupal\myeventlane_views\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\views\Views;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides an attendee CSV export response for a view display.
 */
class AttendeeCsvController extends ControllerBase {

  /**
   * Builds a CSV response for the attendee answers view.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return \Symfony\Component\HttpFoundation\Response|array
   *   A CSV download response when requested, or a rendered view array.
   */
  public function handle(Request $request) {
    $download_title = $request->query->get('download_csv');

    \Drupal::logger('csv_debug')->notice('✅ Exporting CSV for event: @event', ['@event' => $download_title]);

    if ($download_title) {
      $view = Views::getView('attendee_answer');

      if ($view) {
        $view->setDisplay('page_1');

        // ✅ Pass contextual filter argument to the View.
        $view->setArguments([$download_title]);
        $view->execute();

        $rows = [];
        $rows[] = ['First name', 'Last name', 'Email', 'Question', 'Answer'];

        foreach ($view->result as $row) {
          $entity = $row->_entity;
          $q = $row->_relationship_entities['field_attendee_questions'] ?? NULL;

          $rows[] = [
            $entity->field_first_name->value ?? '',
            $entity->field_last_name->value ?? '',
            $entity->field_email->value ?? '',
            $q->field_question_label->value ?? '',
            $q->field_attendee_extra_field->value ?? '',
          ];
        }

        $filename = 'attendees-' . preg_replace('/[^a-z0-9]/i', '_', $download_title) . '-' . date('Ymd-His') . '.csv';
        $csv = fopen('php://temp', 'r+');
        foreach ($rows as $fields) {
          fputcsv($csv, $fields);
        }
        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);

        return new Response($content, 200, [
          'Content-Type' => 'text/csv',
          'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
      }
    }

    // Fallback: render the normal View.
    return views_embed_view('attendee_answer', 'page_1');
  }

}
