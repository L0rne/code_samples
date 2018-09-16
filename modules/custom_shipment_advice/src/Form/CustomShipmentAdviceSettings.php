<?php
/**
* @file
* - This is needed for our form controller, that creates the settings page.
*   all of the logic for loading / saving settings is handled in the Notifiction plugin itself.
*/
namespace Drupal\custom_shipment_advice\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class CustomShipmentAdviceSettings extends FormBase {

  public $plugin_manager;
  public $plugin;

  /**
  * {@inheritdoc}
  */
  public function __construct() {
    $this->plugin_manager = \Drupal::service('plugin.manager.cv_notifications');
    $plugin_definition    = $this->plugin_manager->getDefinition('custom_shipment_advice_notification');
    $this->plugin         = $this->plugin_manager->createInstance('custom_shipment_advice_notification', []);
  }

  public function getFormId() {
    return 'custom_shipment_advice_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = $this->plugin->buildConfigurationForm($form, $form_state);
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $this->plugin->validateconfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->plugin->submitConfigurationForm($form, $form_state);
  }
}
