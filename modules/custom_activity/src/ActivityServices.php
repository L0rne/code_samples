<?php
/**
 * @file ActivityServices.php.  Provices a service for creating Activity
 * nodes tracking system events.
 *
 */

namespace Drupal\custom_activity;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\field_collection\Entity\FieldCollectionItem;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

class ActivityServices {

  protected $username;
  protected $userLink;

  public function __construct() {
    $currentUser = \Drupal::currentUser();
    $user        = User::load($currentUser->id());

    $this->username = $user->getUsername();
    $this->userLink = $user->link();
  }


  public function log_activity($activityType = '', array $options) {

    $return = NULL;

    // Do not log if this was triggered by update.php or other maintenance tasks.
    $state = \Drupal::state()->get('system.maintenance_mode');
    if ($state == TRUE) {
      return $return;
    }

    // Categories for each content type.
    $memoPrefix  = NULL;
    $contentType = $options['node']->getType();

    if ($contentType == 'memo') {
      $counterPartyType = $options['node']->get('field_counter_party_type')->value;
      $memoPrefix = ((strtolower($counterPartyType) == 'purchasing') ? 'Purchasing ' : 'Sales ');
    }

    $categories = [
      "container"         => 'Container',
      "customer"          => 'Customer',
      "memo"              => $memoPrefix . 'D/C Note',
      "file"              => 'File',
      "invoice"           => 'Invoice',
      "purchase_contract" => 'Purchase Contract',
      "release"           => 'Release',
      "sales_contract"    => 'Sales Contract',
      "shipment"          => 'Shipment',
      "supplier"          => 'Supplier',
      "trucker"           => 'Trucker',
    ];

    $category = $categories[$contentType];

    $node = $options['node'];
    $link = $node->link();

    // Is company supplier or customer?
    $companyType = NULL;
    if ($node->hasField('field_supplier') && !$node->get('field_supplier')->isEmpty()) {
      $companyType  = "supplier";
    } elseif ($node->hasField('field_customer') && !$node->get('field_customer')->isEmpty()) {
      $companyType = "customer";
    }

    if ($companyType) {
      $companyNid  = $node->get('field_' . $companyType)->target_id;
      $companyNode = $node->get('field_' . $companyType)->entity;
    } else {
      $companyNid  = $node->id();
      $companyNode = $node;
    }

    $companyLink = $companyNode->link();

    switch ($activityType) {
      case "FieldEdit" :
        // Field(s) changed.  Called from custom_activity_node_update.

        switch ($contentType) {
          case 'container' :
            // Example: "CodigoVision modified container PC 01234/01 referencing supplier Acme Honey."
            $description = "{$this->userLink} modified container {$link} referencing {$companyType} {$companyLink}.";
            break;
          case 'file' :
            // Example: "CodigoVision modified file FULL DOCS on Shipment 1234 referencing supplier Acme Honey."
            // Get the parent node for the file.
            $attributes = ['field_files' => $node->id()];
            $results = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties($attributes);
            foreach ($results as $nid => $parentNode) {
              $parentLink = $parentNode->link();
            }
            // Get Company info from the parent node.
            $parentCompanyType = NULL;
            if ($parentNode->hasField('field_supplier') && !$parentNode->get('field_supplier')->isEmpty()) {
              $parentCompanyType  = "supplier";
            } elseif ($parentNode->hasField('field_customer') && !$parentNode->get('field_customer')->isEmpty()) {
              $parentCompanyType = "customer";
            }
            if ($parentCompanyType) {
              $parentCompanyNode = $parentNode->get('field_' . $parentCompanyType)->entity;
            } else {
              $parentCompanyNode = $parentNode;
            }
            $parentCompanyLink = $parentCompanyNode->link();
            $description       = "{$this->userLink} modified file {$node->getTitle()} on {$parentLink} referencing {$parentCompanyType} {$parentCompanyLink}.";
            break;
          case 'customer' :
          case 'supplier' :
          case 'trucker' :
            // Example: "CodigoVision modified customer Acme Honey."
            $description = "{$this->userLink} modified {$contentType} {$companyLink}.";
            break;
          default :
            // Example: "CodigoVision modified Invoice 1234 referencing supplier Acme Honey."
            $description = "{$this->userLink} modified {$link} referencing {$companyType} {$companyLink}.";
            break;
        }

        $return = $this->createActivityNode($activityType, $category, $description, $companyNid, $options);
        break;

      case "PcCreated" :
        // PC created.  Called from custom_activity_node_insert.
        // Example: "CodigoVision created PC 12345 for 3 FCLs from Acme Supplier."
        $category     = "Purchase Contract";
        $count        = $options['count'];
        $pcNode       = $options['node'];
        $pcLink       = $pcNode->link();
        $supplierNode = $pcNode->get('field_supplier')->entity;
        $supplierLink = $supplierNode->link();
        $companyNid   = $pcNode->get('field_supplier')->target_id;
        $description  = "{$this->userLink} created {$pcLink} for {$count} FCL" . (($count == 1) ? '' : 's') . " from {$supplierLink}.";
        $return       = $this->createActivityNode($activityType, $category, $description, $companyNid, $options);
        break;

      case "MemoCreated":
        // Memo created (Debit or Credit).  Called from custom_activity_node_insert.
        // Example: "CodigoVision issued Debit Note to Acme Customer (or Supplier)."
        $memoNode         = $options['node'];
        $memoLink         = $memoNode->link();
        $counterPartyType = $memoNode->get('field_counter_party_type')->value;
        $memoType         = $memoNode->get('field_memo_type')->value;
        $activityType     = $memoType . "MemoCreated";

        if (strtolower($counterPartyType) == "purchasing") {
          $category         = "Purchasing D/C Note";
          $supplierNode     = $memoNode->get('field_supplier')->entity;
          $counterPartyLink = $supplierNode->link();
          $companyNid       = $memoNode->get('field_supplier')->target_id;
        } else {
          $category         = "Sales D/C Note";
          $customerNode     = $memoNode->get('field_customer')->entity;
          $counterPartyLink = $customerNode->link();
          $companyNid       = $memoNode->get('field_customer')->target_id;
        }

        $description = "{$this->userLink} issued {$memoLink} to {$counterPartyLink}.";
        $return      = $this->createActivityNode($activityType, $category, $description, $companyNid, $options);
        break;

      case "DestinationsUpdated":
        // Destinations updated.  Called form Update Destination Action.
        // Example: "CodigoVision updated destination to Los Angeles for 3 FCLs on PC 12345."
        $category    = "Container";
        $count       = $options['count'];
        $destination = $options['destination'];
        $pcNode      = $options['node'];
        $pcLink      = $pcNode->link();
        $companyNid  = $pcNode->get('field_supplier')->target_id;
        $description = "{$this->userLink} updated destination to {$destination} for {$count} FCL" . (($count == 1) ? '' : 's') . " on {$pcLink}.";
        $return      = $this->createActivityNode($activityType, $category, $description, $companyNid, $options);
        break;

      case "ContainersAddedToShipment":
        // Containers added to Shipment.  Called from add to shipment action and add containers action.
        // Example: "CodigoVision added 3 FCLs to Acme Supplier Shipment 12345 to Philadelphia."
        $category     = "Shipment";
        $count        = $options['count'];
        $shipmentNode = $options['node'];
        $shipmentLink = $shipmentNode->link();
        $supplierNode = $shipmentNode->get('field_supplier')->entity;
        $supplierLink = $supplierNode->link();
        $destination  = $shipmentNode->get('field_destination')->entity->getName();
        $companyNid   = $shipmentNode->get('field_supplier')->target_id;
        $description  = "{$this->userLink} added {$count} FCL" . (($count == 1) ? '' : 's') . " to {$supplierLink} {$shipmentLink} to {$destination}.";
        $return       = $this->createActivityNode($activityType, $category, $description, $companyNid, $options);
        break;

      case "ContainerAddedToInventory":
        // Container added to inventory.  Triggered when Container inventory date
        // field is filled.  Called from custom_activity_node_update.
        // Example: "CodigoVision added PC 12345/01 from Acme Supplier to Inventory."
        $category      = "Container";
        $containerNode = $options['node'];
        $containerLink = $containerNode->link();
        $supplierNode  = $containerNode->get('field_supplier')->entity;
        $supplierLink  = $supplierNode->link();
        $companyNid    = $containerNode->get('field_supplier')->target_id;
        $description   = "{$this->userLink} added {$containerLink} from {$supplierLink} to Inventory.";
        $return        = $this->createActivityNode($activityType, $category, $description, $companyNid, $options);
        break;

      case "ContainersSentToWarehouse":
        // Containers sent to warehouse.  Called from Send To Warehouse Action.
        // Example: "CodigoVision sent 3 FCLs from Acme Supplier to Grimes."
        $category     = "Container";
        $supplierNode = $options['node'];
        $count        = $options['count'];
        $warehouse    = $options['warehouse'];
        $supplierLink = $supplierNode->link();
        $companyNid   = $supplierNode->id();
        $description  = "{$this->userLink} sent {$count} FCL" . (($count == 1) ? '' : 's') . " from {$supplierLink} to {$warehouse}.";
        $return       = $this->createActivityNode($activityType, $category, $description, $companyNid, $options);
        break;

      case "ContainersAddedToSc":
        // Containers added to SC.  Called from Add to Sales Contract action.
        // Example: "CodigoVision allocated 3 FCLs to Acme Customer on SC 12345."
        $category     = "Sales Contract";
        $count        = $options['count'];
        $scNode       = $options['node'];
        $scLink       = $scNode->link();
        $customerLink = $scNode->get('field_customer')->entity->link();
        $companyNid   = $scNode->get('field_customer')->target_id;
        $description  = "{$this->userLink} allocated {$count} FCL" . (($count == 1) ? '' : 's') . " to {$customerLink} on {$scLink}.";
        $return       = $this->createActivityNode($activityType, $category, $description, $companyNid, $options);
        break;

      case "ScCreated":
        // SC created.  Called from custom_activity_node_insert.
        // Example: "CodigoVision created SC 12345 for 3 FCLs to Acme Customer."
        $category     = "Sales Contract";
        $count        = $options['count'];
        $scNode       = $options['node'];
        $scLink       = $scNode->link();
        $customerNode = $scNode->get('field_customer')->entity;
        $customerLink = $customerNode->link();
        $companyNid   = $scNode->get('field_customer')->target_id;
        $description  = "{$this->userLink} created {$scLink} for {$count} FCL" . (($count == 1) ? '' : 's') . " to {$customerLink}.";
        $return       = $this->createActivityNode($activityType, $category, $description, $companyNid, $options);
        break;

      case "ContainersAddedToRelease":
        // Containers added to Release.  Called from Release Containers Action.
        // Example: "CodigoVision released 3 FCLs from Grimes to Acme Customer."
        $category     = "Release";
        $count        = $options['count'];
        $releaseNode  = $options['node'];
        $customerNode = $releaseNode->get('field_sales_contract')->entity->get('field_customer')->entity;
        $companyNid   = $releaseNode->get('field_sales_contract')->entity->get('field_customer')->target_id;
        $customerLink = $customerNode->link();

        // If warehouse is not set, use vessel.
        $whseOrVessel = (empty($options['warehouse']) ? NULL          : $options['warehouse']);
        $whseOrVessel = (empty($options['warehouse']) ? $whseOrVessel : $options['vessel']);

        $description = "{$this->userLink} released {$count} FCL" . (($count == 1) ? '' : 's') . " from {$whseOrVessel} to {$customerLink}.";
        $return      = $this->createActivityNode($activityType, $category, $description, $companyNid, $options);
        break;

      case "ContainersAddedToInvoice":
        // Containers added to Invoice.  Called from Add to Invoice action.
        // Example: "CodigoVision issued Invoice 12345 for 3 FCLs to Acme Customer."
        $category     = "Invoice";
        $count        = $options['count'];
        $invoiceNode  = $options['node'];
        $invoiceLink  = $invoiceNode->link();
        $customerNode = $invoiceNode->get('field_customer')->entity;
        $customerLink = $customerNode->link();
        $companyNid   = $invoiceNode->get('field_customer')->target_id;
        $description  = "{$this->userLink} issued {$invoiceLink} for {$count} FCL" . (($count == 1) ? '' : 's') . " to {$customerLink}.";
        $return       = $this->createActivityNode($activityType, $category, $description, $companyNid, $options);
        break;

      case "ContainersReclaimed":
        // Containers reclaimed.  Called from Reclaim Containers action.
        // Example: "CodigoVision reclaimed 3 FCLs from Acme Customer contract SC 12345."
        $category     = "Sales Contract";
        $count        = $options['count'];
        $scNode       = $options['node'];
        $scLink       = $scNode->link();
        $customerNode = $scNode->get('field_customer')->entity;
        $customerLink = $customerNode->link();
        $companyNid   = $scNode->get('field_customer')->target_id;
        $description  = "{$this->userLink} reclaimed {$count} FCL" . (($count == 1) ? '' : 's') . " from {$customerLink} contract {$scLink}.";
        $return       = $this->createActivityNode($activityType, $category, $description, $companyNid, $options);
        break;

      case "Cancel":
        // Node canceled.  Called from custom_cancel.
        // Example: "CodigoVision cancelled PC 12345."
        $category     = $category;
        $description  = "{$this->userLink} canceled {$link}.";
        $return       = $this->createActivityNode($activityType, $category, $description, $companyNid, $options);
        break;

      case "Uncancel":
        // Node uncanceled.  Called from custom_cancel.
        // Example: "CodigoVision uncancelled PC 12345."
        $category     = $category;
        $description  = "{$this->userLink} uncanceled {$link}.";
        $return       = $this->createActivityNode($activityType, $category, $description, $companyNid, $options);
      break;

      case "Close":
        // Node closed.  Called from custom_close.
        // Example: "CodigoVision closed PC 12345."
        $category     = $category;
        $description  = "{$this->userLink} closed {$link}.";
        $return       = $this->createActivityNode($activityType, $category, $description, $companyNid, $options);
        break;

      case "Re-open":
        // Node reopened.  Called from custom_close.
        // Example: "CodigoVision re-opened PC 12345."
        $category     = $category;
        $description  = "{$this->userLink} re-opened {$link}.";
        $return       = $this->createActivityNode($activityType, $category, $description, $companyNid, $options);
        break;

    }

    return $return;
  }


  /*
  * Create the Activity node using the collected information
  */
  private function createActivityNode($type = '', $category = '', $description = '', $company = '', array $options) {

    $date          = new DrupalDateTime('now');
    $dateFormatted = $date->format('Y-m-d H:i:s');

    // Define a new Activity node.
    $activityNode = Node::create([
      'type'                    => 'activity',
      'title'                   => 'Activity',    //Overridden in custom_auto_title
      'field_affected_entity'   => $options['node']->id(),
      'field_activity_category' => $category,
      'field_company'           => $company,
    ]);

    $activityNode->set('field_description', ['value' => $description, 'format' => 'basic_html']);

    // Add changed fields to field_modifications.
    if ($type == "FieldEdit") {
      $changedFields = $options['changed_fields'];

      // Handle node fields
      foreach ($changedFields[$options['node']->id()] as $field) {
        if (isset($field['old_value'][0]['value'])) {
          $oldValue = $field['old_value'][0]['value'];
        } elseif (isset($field['old_value'][0]['target_id'])) {
          $oldValue = $field['old_value'][0]['target_id'];
        } else {
          if (empty($field['old_value'])) {
            $oldValue = "NONE";
          } else {
            $oldValue = $field['old_value'];
          }
        }

        if (isset($field['new_value'][0]['value'])) {
          $newValue = $field['new_value'][0]['value'];
        } elseif (isset($field['new_value'][0]['target_id'])) {
          $newValue = $field['new_value'][0]['target_id'];
        } else {
          $newValue = $field['new_value'];
        }

        $fc = FieldCollectionItem::create(['field_name' => 'field_modifications']);

        $fc->set('field_field_name', $field['field_name']);
        $fc->set('field_field_label', $field['field_label']);
        $fc->set('field_old_value', ['value' => $oldValue, 'format' => 'basic_html']);
        $fc->set('field_new_value', ['value' => $newValue, 'format' => 'basic_html']);

        $activityNode->field_modifications[] = ['field_collection_item' => $fc];
      }
    }

    return $activityNode->save();
  }

}
