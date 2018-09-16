<?php
namespace Drupal\custom_csv_export;

use Drupal\views\Views;
use Drupal\csv_serialization\Encoder\CsvEncoder;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Datetime\DrupalDateTime;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class Export {
  public static function export($view_id, $display_id, $args, $total_rows, $archive, $redirect, &$context){

    // Load the View we're working with and set its display ID so we get the
    // content we expect.
    $view = Views::getView($view_id);
    $view->setDisplay($display_id);
    $view->setExposedInput($args);

    if ($view_id == "inventory") {
      $file_title = "containers";
    } else {
      $file_title = $view_id;
    }

    if ($display_id == "purchasing_notes_export" || $display_id == "purchasing_notes_archive_export") {
      $file_title = "purchase_notes";
    }

    if ($display_id == "sales_notes_export" || $display_id == "sales_notes_archive_export") {
      $file_title = "sales_notes";
    }

    if ($archive) {
      $file_title .= "_archive";
    }

    if (isset($context['sandbox']['progress'])) {
      $view->setOffset($context['sandbox']['progress']);
    }

    // How many rows do we handle in each batch?
    $export_batch_size = 50;

    // Build the View so the query parameters and offset get applied.
    // This is necessary for the total to be calculated accurately and the call
    // to $view->render() to return the items we expect to process in the
    // current batch (i.e. not the same set of N, where N is the number of
    // items per page, over and over).
    $view->build();

    // First time through - create an output file to write to, set our
    // current item to zero and our total number of items we'll be processing.
    if (empty($context['sandbox'])) {

      // Store the redirect value for use later.
      $context['sandbox']['redirect'] = $redirect;

      // Initialize progress counter, which will keep track of how many items
      // we've processed.
      $context['sandbox']['progress'] = 0;

      // Initialize file we'll write our output results to.
      // This file will be written to with each batch iteration until all
      // batches have been processed.
      // This is a private file because some use cases will want to restrict
      // access to the file. The View display's permissions will govern access
      // to the file.
      $curDate      = new DrupalDateTime('today');
      $filename     = $file_title . "-" . $curDate->format('Y-m-d') . '.csv';
      $directory    = 'private://csv_export/';

      file_prepare_directory($directory, FILE_CREATE_DIRECTORY);

      $destination  = $directory . $filename;
      $file         = file_save_data('', $destination, FILE_EXISTS_REPLACE);

      if (!$file) {
        // Failed to create the file, abort the batch.
        unset($context['sandbox']);
        $context['success'] = FALSE;
        return;
      }

      $file->setTemporary();
      $file->save();
      // Create sandbox variable from filename that can be referenced
      // throughout the batch processing.
      $context['sandbox']['vde_file'] = $file->getFileUri();
    }

    // Render the current batch of rows - these will then be appended to the
    // output file we write to each batch iteration.

    // Set the limit directly on the query.
    $view->query->setLimit((int) $export_batch_size);
    $rendered_rows  = $view->render();
    $json           = (string) $rendered_rows['#markup'];

    // Convert JSON from the view into an array.
    $json_array = json_decode($json, TRUE);

    if ($json_array) {
      // Encode the array as CSV.
      $encoder = new CsvEncoder();
      $csv = $encoder->encode($json_array, 'csv');
    } else {
      $csv = "";
    }

    // Workaround for CSV headers, remove the first line.
    if ($context['sandbox']['progress'] != 0 ) {
      $csv = preg_replace('/^[^\n]+/', '', $csv);
    }

    // Write rendered rows to output file.
    if (file_put_contents($context['sandbox']['vde_file'], $csv, FILE_APPEND) === FALSE) {
      // Write to output file failed - log in logger and in ResponseText on
      // batch execution page user will end up on if write to file fails.
      $message = t('Could not write to temporary output file for result export (@file). Check permissions.', ['@file' => $context['sandbox']['vde_file']]);
      \Drupal::logger('views_data_export')->error($message);
      throw new ServiceUnavailableHttpException(NULL, $message);
    };

    // Update the progress of our batch export operation (i.e. number of
    // items we've processed). Note can exceed the number of total rows we're
    // processing, but that's considered in the if/else to determine when we're
    // finished below.
    $context['sandbox']['progress'] += $export_batch_size;

    // If our progress is less than the total number of items we expect to
    // process, we updated the "finished" variable to show the user how much
    // progress we've made via the progress bar.
    if ($context['sandbox']['progress'] < $total_rows) {
      $context['finished'] = $context['sandbox']['progress'] / $total_rows;
    }
    else {
      // We're finished processing, set progress bar to 100%.
      $context['finished'] = 1;
      // Store URI of export file in results array because it can be accessed
      // in our callback_batch_finished (finishBatch) callback. Better to do
      // this than use a SESSION variable. Also, we're not returning any
      // results so the $context['results'] array is unused.
      // Also, the redirect value is being passed in "results".
      $context['results'] = [
        'vde_file' => $context['sandbox']['vde_file'],
        'redirect' => $context['sandbox']['redirect'],
      ];

    }
  }

  public static function exportFinishedCallback($success, $results, $operations) {

    $redirect = $results['redirect'];

    // Set Drupal status message to let the user know the results of the export.
    // The 'success' parameter means no fatal PHP errors were detected.
    // All other error management should be handled using 'results'.
    if ($success
        && isset($results['vde_file'])
        && file_exists($results['vde_file'])) {
      // Check the permissions of the file to grant access and allow
      // modules to hook into permissions via hook_file_download().
      $headers = \Drupal::moduleHandler()->invokeAll('file_download', [$results['vde_file']]);
      // Require at least one module granting access and none denying access.
      if (!empty($headers) && !in_array(-1, $headers)) {

        // Create a web server accessible URL for the private file.
        // Permissions for accessing this URL will be inherited from the View
        // display's configuration.
        $url = file_create_url($results['vde_file']);

        drupal_set_message(t('Export complete. <iframe width="1" height="1" frameborder="0" src=":download_url"></iframe>', [':download_url' => $url]));

        $redirect = str_replace("/export", "", $redirect);
        $response = new RedirectResponse($redirect);
        $response->send();

      }
    }
    else {
      drupal_set_message(t('Export failed. Make sure the private file system is configured and check the error log.'), 'error');
    }
  }
}
