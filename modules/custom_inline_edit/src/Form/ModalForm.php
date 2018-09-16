<?php

namespace Drupal\custom_inline_edit\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\node\Entity\Node;

/**
 * ModalForm class.
 */
class ModalForm extends FormBase {
  
  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'custom_inline_edit_modal_form';
  }
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $options = NULL) {
    
    $nid         = $options['nid'];
    $entity      = \Drupal\node\Entity\Node::load($nid);
    $contentType = $entity->getType();
    $field       = $options['field'];
  
    $form_state->set('nid', $nid);
    $form_state->set('field', $field);
  
    //Create an empty representative entity
    $node = \Drupal::service('entity_type.manager')->getStorage('node')->create(array(
        'type' => $contentType
      )
    );
  
    // Get the EntityFormDisplay (i.e. the default Form Display) of this content type
    $entityFormDisplay = \Drupal::service('entity_type.manager')->getStorage('entity_form_display')->load("node.{$contentType}.default");
  
    $form_state->set('node', $node);
    $form_state->set('form_display', $entityFormDisplay);
  
    $form['#parents'] = [];
    $form['#prefix']  = '<div id="modal_example_form">';
    $form['#suffix']  = '</div>';
    
    // The status messages that will contain any form errors.
    $form['status_messages'] = [
      '#type' => 'status_messages',
      '#weight' => -10,
    ];
  
    foreach ($entityFormDisplay->getComponents() as $name => $component) {
      $widget = $entityFormDisplay->getRenderer($name);
      if (!$widget) {
        continue;
      }
    
      // Hide all fields but the one we want to edit
      $items = $entity->get($name);
      $items->filterEmptyItems();
      $form[$name] = $widget->form($items, $form, $form_state);
      $form[$name]['#access'] = $items->access('edit');
      if ($name != $field) {
        $form[$name]['#type'] = "hidden";
      }
    }
    
    $form['actions'] = array('#type' => 'actions');
    $form['actions']['send'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit modal form'),
      '#attributes' => [
        'class' => [
          'use-ajax',
        ],
      ],
      '#ajax' => [
        'callback' => [$this, 'submitForm'],
        'event' => 'click',
      ],
    ];
    
    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';
    
    return $form;
  }
  
  /**
   * AJAX callback handler that displays any errors or a success message.
   */
  public function submitModalFormAjax(array $form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    
    // If there are any form errors, re-display the form.
    if ($form_state->hasAnyErrors()) {
      $response->addCommand(new ReplaceCommand('#modal_example_form', $form));
    }
    else {
      $response->addCommand(new OpenModalDialogCommand("Success!", 'The modal form has been submitted.', ['width' => 800]));
    }
    
    return $response;
  }
  
  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
  
    $nid   = $form_state->get('nid');

    $node         = Node::load($nid);
    $violations   = $node->validate();

    if ($violations->count() > 0) {
      $violation_data = [];
      for ($x = 0; $x < $violations->count(); $x++) {
        $violation_data[] = $violations->get($x)->getMessage()->__toString();
      }

      $form_state->set('violations', $violation_data);
    }
  }
  
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  
    $nid           = $form_state->get('nid');
    $field         = $form_state->get('field');
    $node          = Node::load($nid);
    $form_display  = $form_state->get('form_display');
    $userInput     = $form_state->getUserInput();
  
    $violation_data = $form_state->get('violations');
  
    // If there were validation violations, report and abort save.
    if (count($violation_data) > 0) {
      $response = new AjaxResponse();
      $response->addCommand(
        new HtmlCommand(
          "#result_message_{$nid}_{$field}",
          '<div class="my_top_message">' . t('%message', ['%message' => implode("\n\r", $violation_data) ] ) . '</div>')
      );
      return $response;
    }
    
    // Apply form edits to the node.
    $extracted = $form_display->extractFormValues($node, $form, $form_state);
    // Save the modified node.
    $node->save();
    
    $content_type      = $node->getType();
    $field_definitions = $node->getFieldDefinitions();
    $field_definition  = $field_definitions[$field];
    $field_type        = $field_definition->getType();
    $value             = !empty($node->get($field)->value) ? $node->get($field)->value : NULL;
  
    switch ($field_type) {
      case "entity_reference" :
        $reference_entity = $node->get($field)->entity;
        $entity_type      = $reference_entity->getEntityTypeId();
        $value            = !empty($node->get($field)->target_id) ? $node->get($field)->target_id : NULL;
      
        switch ($entity_type) {
          case "taxonomy_term" :
            $output = $reference_entity->getName();
            break;
          case "node" :
            //$output = $reference_entity->getTitle();
            $output = $node->get($field)->entity->link();
            break;
          case "user" :
            $output = $reference_entity->getAccountName();
            break;
          case "file" :
            $output = $reference_entity->getFilename();
            break;
        }
      
        break;
    
      case "boolean" :
        $field_settings = $field_definition->getSettings();
        if (isset($node->get($field)->value) && $node->get($field)->value == TRUE) {
          $output = $field_settings['on_label'];
        } else {
          $output = $field_settings['off_label'];
        }
      
        break;
    
      case "text_with_summary" :
        $data   = $node->get($field)->getValue();
        $output = [
          '#type'   => 'processed_text',
          '#text'   => $data[0]['value'],
          '#format' => $data[0]['format'],
        ];
      
        break;
    
      default :
        $output = $value;
    
    }

    $response = new AjaxResponse();
  
    // If there are any form errors, re-display the form.
    if ($form_state->hasAnyErrors()) {
      $response->addCommand(new ReplaceCommand('#modal_example_form', $form));
    }
    else {
      $response->addCommand(
        new HtmlCommand(
          "#{$nid}_{$field}",
          $output)
      );
      $response->addCommand(new OpenModalDialogCommand("Success!", '<h1>The modal form has been submitted.</h1>', ['width' => 400]));
    }
  
    return $response;
    
  }
  
  /**
   * Gets the configuration names that will be editable.
   *
   * @return array
   *   An array of configuration object names that are editable if called in
   *   conjunction with the trait's config() method.
   */
  protected function getEditableConfigNames() {
    return ['config.modal_form_example_modal_form'];
  }
  
}