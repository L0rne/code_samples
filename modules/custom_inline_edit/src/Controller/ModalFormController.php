<?php

namespace Drupal\custom_inline_edit\Controller;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilder;

/**
 * ModalFormExampleController class.
 */
class ModalFormController extends ControllerBase {
  
  /**
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilder
   */
  protected $formBuilder;
  
  /**
   * The ModalFormExampleController constructor.
   *
   * @param \Drupal\Core\Form\FormBuilder $formBuilder
   *   The form builder.
   */
  public function __construct(FormBuilder $formBuilder) {
    $this->formBuilder = $formBuilder;
  }
  
  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The Drupal service container.
   *
   * @return static
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('form_builder')
    );
  }
  
  /**
   * Callback for opening the modal form.
   */
  public function openModalForm($nid = NULL, $field = NULL) {
    
    $response = new AjaxResponse();
    
    $options = [
      'nid'   => $nid,
      'field' => $field
    ];
    
    // Get the modal form using the form builder.
    $modal_form = $this->formBuilder->getForm('Drupal\custom_inline_edit\Form\ModalForm', $options);
    
    // Add an AJAX command to open a modal dialog with the form as the content.
    $response->addCommand(new OpenModalDialogCommand('Field Edit', $modal_form, ['width' => '400']));
    
    return $response;
  }
  
}