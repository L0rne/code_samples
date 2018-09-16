<?php

namespace Drupal\custom_shipment_advice\Plugin\CodigoVision\Notification;

use Drupal\cv_notifications\Plugin\CodigoVision\Notification\NotificationBase;
use Drupal\Core\Form\FormStateInterface;


/**
* @Notification(
*  id = "custom_shipment_advice_notification",
* )
*/
class ShipmentAdviceNotification extends NotificationBase {

  /**
  * {@inheritdoc}
  */
  public function defaultConfiguration() {
    $config = \Drupal::configFactory()
      ->getEditable('custom_shipment_advice_notification.settings');
    return [
      'subject'         => $config->get('subject'),
      'body'            => $config->get('body'),
      'enabled'         => $config->get('enabled'),
      'days_before_eta' => $config->get('days_before_eta'),
      'dev_mode'        => $config->get('dev_mode'),
      'dev_email'       => $config->get('dev_email')
    ];
  }

  /**
  * {@inheritdoc}
  */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form                               = parent::buildConfigurationForm($form, $form_state);
    $form['subject']['#default_value']  = $this->configuration['subject'];
    $form['body']['#default_value']     = $this->configuration['body'];

    // Define the available tokens for this email.
    $form['tokens']['#rows'] = [
      [
        '[customer_name]', 'Customer\'s name related to the shipment advice.'
      ],
      [
        '[shipment_data]', 'The Shipment data table displaying container details. (only available in the body)'
      ],
    ];

    $form['enabled'] = [
      '#type'           => 'checkbox',
      '#title'          => t('Enable Shipment Advice'),
      '#default_value'  => $this->configuration['enabled'],
      '#weight' => -50
    ];

    $form['days_before_eta'] = [
      '#type'           => 'number',
      '#title'          => t('Days before ETA'),
      '#default_value'  => $this->configuration['days_before_eta'],
      '#required'        => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {

    if (!$form_state->getErrors()) {
      $config = \Drupal::configFactory()
        ->getEditable('custom_shipment_advice_notification.settings');
      $values = $form_state->getValue($form['#parents']);

      $config
        ->set('subject', $values['subject'])
        ->set('body', $values['body'])
        ->set('enabled', $values['enabled'])
        ->set('days_before_eta', $values['days_before_eta'])
        ->set('dev_mode', $values['dev_mode'])
        ->set('dev_email', $values['dev_email'])
        ->save();

    }
  }

  /**
  *{@inheritdoc}
  */
  public function execute() {

    if ($this->configuration['enabled'] == FALSE) {
      return;
    }

    $notification_data = $this->getShipmentsForNotification();

    if (count($notification_data) > 0) {
      // Setup the defaults.  We will override the tokens in the loop.
      $subject  = (isset($this->configuration['subject']) ? $this->configuration['subject'] : '');
      $body     = (isset($this->configuration['body']) ? $this->configuration['body'] : '');
      $from     = (isset($this->configuration['from']) ? $this->configuration['from'] : '');

      $mailManager  = \Drupal::service('plugin.manager.mail');
      $langcode     = \Drupal::languageManager()->getCurrentLanguage()->getId();

      $shipment_data_header = [
        'Origin',
        'Color',
        'Grade',
        'SC',
        'Container ID',
        'TS #',
        'Supplier'
      ];
      $shipment_table = [
        '#theme'  => 'table',
        '#header' => $shipment_data_header
      ];

      // Tokens for processing
      $subject_tokens = [
        '[customer_name]',
      ];

      $body_tokens = $subject_tokens;
      // We dont want to put a table in the subject line!
      $body_tokens[] = '[shipment_data]';

      $renderer             = \Drupal::service('renderer');
      $shipment_table_orig  = $shipment_table;

      // Loop and process for email
      foreach($notification_data as $notification) {
        $message['html']    = TRUE;
        $message['headers'] = [
          'content-type'  => 'text/html',
          'MIME-Version'  => '1.0',
          'reply-to'      => $from,
          'from'          => 'custom <'.$from.'>'
        ];
        $message['language']  = \Drupal::currentUser()->getPreferredLangcode();
        $message['to']        = $this->configuration['dev_mode'] ? $this->configuration['dev_email'] : $notification['email'];

        // Process the subject, updating with tokens.
        $subject_token_data = [
          $notification['customer'],
        ];
        $message['subject'] = $this->token_replace($subject, $subject_tokens, $subject_token_data);

        $tables         = "";
        $shipments      = [];
        $shipments_sent = [];

        // build a data table for each shipment
        foreach ($notification['shipments'] as $shipment_notification_nid =>$shipment_notification) {
          // Reset the shipment table
          $shipment_table = $shipment_table_orig;
          $shipment_notification_eta    = date('M d, Y', strtotime($shipment_notification['shipment_eta']));
          $shipment_table['#caption']   = "Shipment {$shipment_notification['shipment_id']} - Qty: {$shipment_notification['quantity']} - ETA: {$shipment_notification_eta}";
          $shipment_table['#rows']      = $shipment_notification['containers'];
          $shipment_table_rendered      = $renderer->render($shipment_table);
          $tables                       .= $shipment_table_rendered . "<br />";
          $shipments[]                  = $shipment_notification_nid;
        }

        $body_token_data                     = [
          $notification['customer'],
          $tables,
        ];
        $message['body'] = $this->token_replace($body, $body_tokens, $body_token_data);

        $result = $mailManager->mail('cv_notifications', 'shipment_advice', $message['to'], $langcode, $message, NULL, TRUE);

        if (!$result) {
          \Drupal::logger('cv_notifications')->error("There was an error while attempting to send the shipment advice notification to %customer.",[
            '%customer' => $notification['customer']
          ]);
        }
        else {
          // Flag the shipments for update
          $shipments_sent = $shipments;
        }

        foreach ($shipments_sent as $shipment_sent_nid) {
          $shipment_sent = \Drupal\node\Entity\Node::load($shipment_sent_nid);
          $now      = date('Y-m-d', mktime(0, 0, 0, date('m'), date('d'), date('Y')));
          $shipment_sent->set('field_shipment_advice_sent', [$now, ]);
          $shipment_sent->save();
        }
      }
    }
  }

  /**
  * Helper function to get valid shipments.
  */
  private function getShipmentsForNotification() {
    $days_advance = $this->configuration['days_before_eta'];
    $now          = date('Y-m-d', mktime(0, 0, 0, date('m'), date('d'), date('Y')));
    $date         = date('Y-m-d', mktime(0, 0, 0, date('m'), date('d') + $days_advance, date('Y')));

    // Get Shipments within the correct date range
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'shipment')
      ->condition('field_eta', $date, '<=')
      ->condition('field_eta', $now, '>=');
    $query->notExists('field_shipment_advice_sent'); // check for NULL value
    $nids = $query->execute();

    $notification_data = [];

    foreach($nids as $nid) {
      $shipment_nid 		      = $nid;
      $shipment 				      = \Drupal\node\Entity\Node::load($nid);
      $shipment_id 			      = $shipment->get('field_shipment_id')->value;
      $shipment_eta 			    = $shipment->get('field_eta')->value;
      $supplier_company_name 	= $shipment->get('field_supplier')->entity->getTitle();

      // Get containers
      $query_containers = \Drupal::entityQuery('node')
        ->condition('type', 'container')
        ->condition('field_shipment', $shipment_nid);
      $query_containers->Exists('field_sales_contract'); // check for not NULL value
      $nids_containers = $query_containers->execute();

      $container_nodes = \Drupal::entityManager()->getStorage('node')->loadMultiple($nids_containers);

      foreach ($container_nodes as $id => $container_node) {
        $origin         = $container_node->get('field_origin')->first()->entity->getName();
        $color          = $container_node->get('field_color')->first()->entity->getName();
        $grade          = $container_node->get('field_grade')->first()->entity->getName();
        $container_id   = $container_node->get('field_container_id')->value;
        $ts_seal_number = (($container_node->get('field_ts_seal_number')->isEmpty()) ? 'N/A' : $container_node->get('field_ts_seal_number')->value);

        // Get SC
        $sc                 = $container_node->get('field_sales_contract')->entity;
        $sc_id              = $sc->get('field_sc_id')->value;
        $quantity           = $sc->get('field_quantity')->value;
        $sc_delivery_terms  = $sc->get('field_delivery_terms')->value;

        // If delivery_terms not "EX/DOCK", skip this container
        if ($sc_delivery_terms != "EX/DOCK") {
          continue;
        }

        // Get customer from SC
        $customer_nid           = $sc->get('field_customer')->target_id;
        $customer_company_name  = $sc->get('field_customer')->entity->getTitle();
        $email                  = $sc->get('field_customer')->entity->get('field_email')->value;

        // Set up return data
        if (!isset($notification_data[$customer_nid])) {
          $notification_data[$customer_nid] = [
            'customer'      => $customer_company_name,
            'email'         => $email,
            'shipments'    => []
          ];
        }

        if (!isset($notification_data[$customer_nid]['shipments'][$shipment_nid])) {
          $notification_data[$customer_nid]['shipments'][$shipment_nid] = [
            'quantity'      => $quantity,
            'shipment_id'   => $shipment_id,
            'shipment_eta'  => $shipment_eta,
            'shipment_nid'  => $shipment_nid,
            'containers'    => []
          ];
        }

        $notification_data[$customer_nid]['shipments'][$shipment_nid]['containers'][] =[
          $origin,
          $color,
          $grade,
          $sc_id,
          $container_id,
          $ts_seal_number,
          $supplier_company_name
        ];
      }
    }

    return $notification_data;

  }

}
