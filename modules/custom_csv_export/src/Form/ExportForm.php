<?php
namespace Drupal\custom_csv_export\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Views;
use Drupal\Core\Url;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\ConfirmFormHelper;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Component\Utility\UrlHelper;

/**
 * Class PcExportForm.
 *
 * @package Drupal\custom_csv_export\Form
 */
class ExportForm extends ConfirmFormBase {

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {

    $current_url = \Drupal::service('path.current')->getPath();
    $cancel_url = str_replace("/export", "", $current_url);
    $get = $_GET;
    $options =  ['query' => $get];
    $url_object = Url::fromUri("internal:" . $cancel_url, $options);

    return $url_object;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return 'The export process may take a while.  Would you still like to proceed?';
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelText() {
    return $this->t('No, go back');
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'export_form';
  }
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $node_type = NULL) {

    $cancel             = ConfirmFormHelper::buildCancelLink($this, $this->getRequest());
    // The cancel URL contains the query parameters, so use that for redirect.
    $redirect           = $cancel['#url']->toString();
    $view_query_array   = $cancel['#url']->getOption("query");
    $view_query_string  = UrlHelper::buildQuery($view_query_array);
    $route              = \Drupal::routeMatch()->getRouteName();
    $chunks             = explode('.', $route);
    $type1              = $chunks[1];
    $archive            = FALSE;

    if (strpos($type1, "archive")) {
      $archive  = TRUE;
      $chunks   = explode('_', $type1);
      $type1    = $chunks[0];
    }

    if ($type1 == "sales") {
      switch ($node_type) {
        case 'contracts':
          $export_type  = "sales_contract";
          // $routename is the route for non-batch export
          $routename    = "view.sales_contracts.sales_contracts_export";
          break;
        case 'notes':
          $export_type  = "memo";
          $routename    = "view.debit_credit_notes.sales_notes_export";
          break;
        case 'releases':
          $export_type  = "release";
          $routename    = "view.releases.releases_export";
          break;
        case 'invoicing':
          $export_type  = "invoice";
          $routename    = "view.invoices.invoices_export";
          break;
        case 'customers':
          $export_type  = "customer";
          $routename    = "view.customers.customers_export";
          break;
        case 'truckers':
          $export_type  = "trucker";
          $routename    = "view.truckers.truckers_export";
          break;
      }
    }

    if ($type1 == "purchasing") {
      switch ($node_type) {
        case 'contracts':
          $export_type  = "purchase_contract";
          $routename    = "view.purchase_contracts.purchase_contracts_export";
          break;
        case 'notes':
          $export_type  = "memo";
          $routename    = "view.debit_credit_notes.purchasing_notes_export";
          break;
        case 'suppliers':
          $export_type  = "supplier";
          $routename    = "view.suppliers.suppliers_export";
          break;
      }
    }

    if ($type1 == "shipping") {
      $export_type  = "shipment";
      $routename    = "view.shipments.shipments_export";
    }

    if ($type1 == "inventory") {
      $export_type  = "container";
      $routename    = "view.inventory.containers_export";
    }

    $node_type_name = \Drupal::entityTypeManager()->getStorage('node_type')->load($export_type)->label();

    if ($export_type == "memo") {
      $node_type_name = ucfirst($type1) . " " . $node_type_name;
    }

    if ($archive) {
      $node_type_name .= " Archive";
    }

    // If this is not an Archive export, skip batch processing and
    //  prepare to use the old CSV export.
    if (!$archive) {
      $active_export_url = Url::fromRoute($routename)->toString();
      if ($view_query_string) {
        $active_export_url .= "?" . $view_query_string;
      }
    } else {
      $active_export_url = NULL;
    }

    $question = $this->getQuestion();

    // Set the page title.
    $form['#title'] = $this->t('Export ' . $node_type_name . "s");

    $form['export_type'] = array(
      '#type'   => 'hidden',
      '#value'  => $export_type,
    );
    $form['export_type_name'] = array(
      '#type'   => 'hidden',
      '#value'  => $node_type_name,
    );
    $form['type1'] = array(
      '#type'   => 'hidden',
      '#value'  => $type1,
    );
    $form['archive'] = array(
      '#type'   => 'hidden',
      '#value'  => $archive,
    );
    $form['redirect'] = array(
      '#type'   => 'hidden',
      '#value'  => $redirect,
    );
    $form['active_export_url'] = array(
      '#type'   => 'hidden',
      '#value'  => $active_export_url,
    );
    $form['view_query_array'] = array(
      '#type'   => 'value',
      '#value'  => $view_query_array,
    );

    $form['question'] = [
        '#markup' => '<div class="form-item confirm-question">' . $question . '</div>',
      ];
    $form['#attributes']['class'][] = 'confirmation';

    $form['actions'] = array(
      '#type' => 'actions');

    $form['actions']['submit'] = array(
      '#type'        => 'submit',
      '#value'       => $this->t('Yes, continue'),
      '#button_type' => 'primary',
    );

    $form['actions']['cancel'] = $cancel;

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $export_type        = $form_state->getValue('export_type');
    $export_type_name   = $form_state->getValue('export_type_name');
    $type1              = $form_state->getValue('type1');
    $archive            = $form_state->getValue('archive');
    $redirect           = $form_state->getValue('redirect');
    $active_export_url  = $form_state->getValue('active_export_url');
    $view_query_array   = $form_state->getValue('view_query_array');

    // For active list export, skip batch processing
    //  and redirect to the original export view.
    // The iframe triggers automatic download of the file
    //  once the redirect back to the list view is complete.
    if (!$archive) {
      drupal_set_message(t('Export complete. <iframe width="1" height="1" frameborder="0" src=":download_url"></iframe>', [':download_url' => $active_export_url]));

      $redirect = str_replace("/export", "", $redirect);
      $response = new RedirectResponse($redirect);
      $response->send();
      exit;
    }

    switch ($export_type) {
      case 'purchase_contract':
        $view_id      = "purchase_contracts";
        $display_id   = "purchase_contracts_export";
        break;
      case 'supplier':
        $view_id      = "suppliers";
        $display_id   = "suppliers_export";
        break;
      case 'shipment':
        $view_id      = "shipments";
        $display_id   = "shipments_export";
        break;
      case "container":
        $view_id      = "inventory";
        $display_id   = "containers_export";
        break;
      case "sales_contract":
        $view_id      = "sales_contracts";
        $display_id   = "sales_contracts_export";
        break;
      case "release":
        $view_id      = "releases";
        $display_id   = "releases_export";
        break;
      case "invoice":
        $view_id      = "invoices";
        $display_id   = "invoices_export";
        break;
      case "customer":
        $view_id      = "customers";
        $display_id   = "customers_export";
        break;
      case "trucker":
        $view_id      = "truckers";
        $display_id   = "truckers_export";
        break;
      case "memo":
        $view_id        = "debit_credit_notes";
        if ($type1 == "purchasing") {
          $display_id   = "purchasing_notes_export";
        }
        elseif ($type1 == "sales") {
          $display_id   = "sales_notes_export";
        }
        break;
    }

    // Rebuild the display id for archive
    if ($archive) {
      $chunks     = explode("_", $display_id);
      $last       = array_pop($chunks);
      $display_id = "";
      foreach ($chunks as $chunk) {
        if ($display_id == "") {
          $display_id = $chunk;
        } else {
          $display_id = $display_id . "_" . $chunk;
        }
      }
      $display_id .= "_archive";
      $display_id .= "_" . $last;
    }

    $view = Views::getView($view_id);
    $view->setDisplay($display_id);
    $view->setExposedInput($view_query_array);
    $view->get_total_rows = TRUE;
    $view->build();
    $count_query  = clone $view->query;
    $total_rows   = $count_query->query()->countQuery()->execute()->fetchField();
    // Don't load and instantiate so many entities.
    $view->query->setLimit(1);
    $view->execute();

    $batch = [
      'title' => t('Exporting ' . $export_type_name . "s"),
      'operations'        => [
        [
          '\Drupal\custom_csv_export\Export::export',
          [
            $view->id(),
            $view->current_display,
            $view_query_array,
            $total_rows,
            $archive,
            $redirect,
          ]
        ],
      ],
      'progressive'       => TRUE,
      'progress_message'  => 'Time elapsed: @elapsed',
      'finished' => '\Drupal\custom_csv_export\Export::exportFinishedCallback',
    ];

    batch_set($batch);

  }

  public function getRedirectPath() {
    $url  = \Drupal::request()->server->get('HTTP_REFERER');
    $path = parse_url($url);;
    return $path['path'];
  }
}

